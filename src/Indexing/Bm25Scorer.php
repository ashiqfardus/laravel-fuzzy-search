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

        // Join postings directly with documents table — eliminates the full-table GROUP BY scan.
        // Order by frequency DESC and cap at max_postings_per_term so that a high-frequency
        // term (e.g. "john" with 50k hits) cannot pull the entire posting list into PHP.
        // High-frequency rows are prioritised, preserving BM25 accuracy for top results.
        $maxPostings = (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        $postings = DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_documents as d', function ($join) use ($modelType) {
                $join->on('p.model_id', '=', 'd.model_id')
                     ->where('d.model_type', '=', $modelType);
            })
            ->where('p.model_type', $modelType)
            ->whereIn('p.term_id', $termIds)
            ->select('p.model_id', 'p.term_id', 'p.frequency', 'd.doc_length as doc_len')
            ->orderBy('p.frequency', 'desc')
            ->limit($maxPostings)
            ->get();

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
