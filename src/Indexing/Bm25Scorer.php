<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class Bm25Scorer
{
    public function __construct(
        private float $k1 = 1.5,
        private float $b  = 0.75,
    ) {}

    /**
     * Normalise the two accepted term shapes — a plain list (every term at 1.0) or
     * term => weight — into term => weight. Keys are cast back to strings for SQL bindings
     * because PHP turns numeric-string keys ('2024') into ints and SQL Server refuses to
     * compare an nvarchar column with an int binding.
     *
     * @param  array<int, string>|array<string, float> $terms
     * @return array<string, float>
     */
    private function weights(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        // A weight map always has numeric values; a term list never does.
        $first = $terms[array_key_first($terms)];

        return (is_int($first) || is_float($first))
            ? $terms
            : array_fill_keys(array_map('strval', array_values($terms)), 1.0);
    }

    /** @param array<string, float> $weights */
    private function termBindings(array $weights): array
    {
        return array_map('strval', array_keys($weights));
    }

    /**
     * Count the number of distinct models that contain at least one query term.
     * Used by FuzzySearchEngine::paginate() to obtain an accurate total (C13).
     *
     * @param array<int, string>|array<string, float> $terms Processed terms, or term => weight
     */
    public function count(array $terms, string $modelType): int
    {
        $weights = $this->weights($terms);
        if ($weights === []) {
            return 0;
        }

        $termIds = DB::table('fuzzy_index_terms')
            ->whereIn('term', $this->termBindings($weights))
            ->pluck('id');

        if ($termIds->isEmpty()) {
            return 0;
        }

        return (int) DB::table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->whereIn('term_id', $termIds)
            ->distinct('model_id')
            ->count('model_id');
    }

    /**
     * Run BM25 over the inverted index and return the top scored model IDs.
     *
     * @param  array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param  string                                  $modelType     Fully-qualified model class name
     * @param  int                                     $limit
     * @param  array<string, int|float>                $columnWeights column => weight; '' and unknown names weigh 1
     * @return Collection<object{model_id: int|string, score: float}>
     */
    public function search(array $terms, string $modelType, int $limit = 15, array $columnWeights = []): Collection
    {
        return collect(array_slice($this->rank($terms, $modelType, $columnWeights), 0, $limit, true))
            ->map(fn($score, $modelId) => (object) ['model_id' => $modelId, 'score' => $score]);
    }

    /**
     * Run BM25 over the inverted index and return the complete ranking as
     * [model_id => score], best first. Callers that must apply Eloquent constraints
     * (filters, scopes) walk this list so the cut happens after constraining, not before.
     *
     * BM25F-lite: for each (document, term), sums columnWeight × frequency across that
     * term's column postings, then applies the BM25 saturation once. With no weights this
     * equals the merged frequency, so unweighted calls and legacy ('' column_name) postings
     * score exactly as before column weights existed.
     *
     * @param  array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param  array<string, int|float>                $columnWeights column => weight; '' and unknown names weigh 1
     * @return array<int|string, float>
     */
    public function rank(array $terms, string $modelType, array $columnWeights = []): array
    {
        $weights = $this->weights($terms);
        if ($weights === []) {
            return [];
        }

        $meta = DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->first();

        if (!$meta || $meta->total_docs == 0) {
            return [];
        }

        $N     = (float) $meta->total_docs;
        $avgdl = (float) $meta->avg_doc_length ?: 1.0;

        $termData = DB::table('fuzzy_index_terms')
            ->whereIn('term', $this->termBindings($weights))
            ->select('id', 'term', 'doc_count')
            ->get()
            ->keyBy('id');

        if ($termData->isEmpty()) {
            return [];
        }

        $termIds = $termData->keys()->toArray();

        // Join postings directly with documents table — eliminates the full-table GROUP BY scan.
        // Order by frequency DESC and cap at max_postings_per_term so that a high-frequency
        // term (e.g. "john" with 50k hits) cannot pull the entire posting list into PHP.
        // High-frequency rows are prioritised globally across all matched terms; in a pathological
        // corpus a single dominant term could consume the cap, but at the default 50k the bound
        // is never reached for normal workloads.
        $maxPostings = (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        $postings = DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_documents as d', function ($join) use ($modelType) {
                $join->on('p.model_id', '=', 'd.model_id')
                     ->where('d.model_type', '=', $modelType);
            })
            ->where('p.model_type', $modelType)
            ->whereIn('p.term_id', $termIds)
            ->select('p.model_id', 'p.term_id', 'p.column_name', 'p.frequency', 'd.doc_length as doc_len')
            ->orderBy('p.frequency', 'desc')
            ->limit($maxPostings)
            ->get();

        // BM25F-lite: weighted frequency per (document, term) summed across columns, then one
        // saturation. '' (legacy) and unknown column names weigh 1; a weight <= 0 removes the column.
        $frequency = []; // model_id => term_id => weighted frequency
        $docLen    = [];
        foreach ($postings as $row) {
            $cw = max(0.0, (float) ($columnWeights[$row->column_name] ?? 1.0));
            $frequency[$row->model_id][$row->term_id] = ($frequency[$row->model_id][$row->term_id] ?? 0.0) + $cw * $row->frequency;
            $docLen[$row->model_id] = (float) $row->doc_len;
        }

        $scores = [];
        foreach ($frequency as $modelId => $byTerm) {
            foreach ($byTerm as $termId => $f) {
                if ($f <= 0) {
                    continue;
                }
                $td     = $termData[$termId];
                $weight = (float) ($weights[$td->term] ?? 1.0);
                $idf    = log(($N - $td->doc_count + 0.5) / ($td->doc_count + 0.5) + 1);
                $tf     = ($f * ($this->k1 + 1))
                        / ($f + $this->k1 * (1 - $this->b + $this->b * $docLen[$modelId] / $avgdl));

                $scores[$modelId] = ($scores[$modelId] ?? 0) + $weight * $idf * $tf;
            }
        }

        arsort($scores);

        return array_map(fn($score) => round($score, 6), $scores);
    }
}
