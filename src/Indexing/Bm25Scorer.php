<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Bm25Scorer
{
    public function __construct(
        private float $k1 = 1.5,
        private float $b  = 0.75,
    ) {}

    /**
     * Run BM25 over the inverted index and return scored model IDs.
     *
     * @param  string[] $terms     Already tokenized + stemmed query terms
     * @param  string   $modelType Fully-qualified model class name
     * @param  int      $limit
     * @return Collection<object{model_id: int, score: float}>
     */
    public function search(array $terms, string $modelType, int $limit = 15): Collection
    {
        if (empty($terms)) {
            return collect();
        }

        $meta = DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->first();

        if (!$meta || $meta->total_docs == 0) {
            return collect();
        }

        $N     = (float) $meta->total_docs;
        $avgdl = (float) $meta->avg_doc_length ?: 1.0;

        $termData = DB::table('fuzzy_index_terms')
            ->whereIn('term', $terms)
            ->select('id', 'doc_count')
            ->get()
            ->keyBy('id');

        if ($termData->isEmpty()) {
            return collect();
        }

        $termIds = $termData->keys()->toArray();

        // Fetch per-document lengths (total token count per doc) separately
        $docLengths = DB::table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->select('model_id', DB::raw('SUM(frequency) as doc_len'))
            ->groupBy('model_id')
            ->pluck('doc_len', 'model_id');

        // Fetch relevant postings for the matched terms
        $rawPostings = DB::table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->whereIn('term_id', $termIds)
            ->select('model_id', 'term_id', 'frequency')
            ->get();

        // Attach doc_len to each posting row
        $postings = $rawPostings->map(function ($row) use ($docLengths) {
            $row->doc_len = $docLengths[$row->model_id] ?? 1;
            return $row;
        });

        $scores = [];
        foreach ($postings as $row) {
            $td  = $termData[$row->term_id];
            $idf = log(($N - $td->doc_count + 0.5) / ($td->doc_count + 0.5) + 1);
            $tf  = ($row->frequency * ($this->k1 + 1))
                 / ($row->frequency + $this->k1 * (1 - $this->b + $this->b * $row->doc_len / $avgdl));

            $scores[$row->model_id] = ($scores[$row->model_id] ?? 0) + $idf * $tf;
        }

        arsort($scores);

        return collect(array_slice($scores, 0, $limit, true))
            ->map(fn($score, $modelId) => (object) ['model_id' => $modelId, 'score' => round($score, 6)]);
    }
}
