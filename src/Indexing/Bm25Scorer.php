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

    /** Columns whose weight is <= 0 contribute nothing to rank(); count() must skip them too. */
    private function excludedColumns(array $columnWeights): array
    {
        return array_keys(array_filter($columnWeights, fn ($w) => (float) $w <= 0));
    }

    /**
     * Weighted frequency per posting row as SQL: SUM(CASE p.column_name WHEN ? THEN p.frequency * w … ELSE p.frequency END).
     * Weights are inlined as floats (they come from code, never from user strings); column names are bound.
     * '' (legacy) and unknown columns fall to the ELSE branch (weight 1). Returns [sql, bindings].
     */
    private function weightedFrequencySql(array $columnWeights): array
    {
        $cases    = '';
        $bindings = [];
        foreach ($columnWeights as $column => $weight) {
            $w = (float) $weight;
            if ($w <= 0 || $w == 1.0) {
                continue; // excluded by whereNotIn() / default branch
            }
            $cases     .= ' WHEN ? THEN p.frequency * ' . sprintf('%.6F', $w);
            $bindings[] = (string) $column;
        }

        return [$cases === '' ? 'SUM(p.frequency)' : "SUM(CASE p.column_name{$cases} ELSE p.frequency END)", $bindings];
    }

    /**
     * Count the number of distinct models that contain at least one query term.
     * Used by FuzzySearchEngine::paginate() to obtain an accurate total (C13).
     *
     * @param array<int, string>|array<string, float> $terms         Processed terms, or term => weight
     * @param array<string, int|float>                $columnWeights column => weight; a weight <= 0 removes the column
     */
    public function count(array $terms, string $modelType, array $columnWeights = []): int
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
            ->when($this->excludedColumns($columnWeights), fn ($q, $cols) => $q->whereNotIn('column_name', $cols))
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
            ->select('id', 'term')
            ->get()
            ->keyBy('id');

        if ($termData->isEmpty()) {
            return [];
        }

        $termIds = $termData->keys()->toArray();

        // Document frequency within $modelType, the population N counts. The dictionary's
        // doc_count spans every model, so a word another model used more often than this one
        // has rows gave a negative idf and ranked the best match last. DISTINCT model_id: a
        // document has one posting per column it holds the term in. Chunked under SQL Server's
        // 2,100 bindings; postings_unique_idx (term_id, model_type, model_id, …) covers it.
        $df = [];
        foreach (array_chunk($termIds, 1000) as $chunk) {
            $df += DB::table('fuzzy_index_postings')
                ->where('model_type', $modelType)
                ->whereIn('term_id', $chunk)
                ->groupBy('term_id')
                ->selectRaw('term_id, COUNT(DISTINCT model_id) as df')
                ->pluck('df', 'term_id')
                ->all();
        }

        // Join postings directly with documents table — eliminates the full-table GROUP BY scan.
        // One row per (document, term), weighted in SQL, ordered by that weighted frequency DESC
        // and capped at max_postings_per_term so that a high-frequency term (e.g. "john" with 50k
        // hits) cannot pull the entire posting list into PHP. The cap is over (document, term)
        // rows — exactly what v2.0 capped, which ordered by raw frequency — so a document is
        // never partially cut across its columns. High-frequency rows are prioritised globally
        // across all matched terms; in a pathological corpus a single dominant term could consume
        // the cap, but at the default 50k the bound is never reached for normal workloads.
        $maxPostings = (int) config('fuzzy-search.bm25.max_postings_per_term', 50000);

        [$wf, $wfBindings] = $this->weightedFrequencySql($columnWeights);

        $postings = DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_documents as d', function ($join) use ($modelType) {
                $join->on('p.model_id', '=', 'd.model_id')
                     ->where('d.model_type', '=', $modelType);
            })
            ->where('p.model_type', $modelType)
            ->whereIn('p.term_id', $termIds)
            ->when($this->excludedColumns($columnWeights), fn ($q, $cols) => $q->whereNotIn('p.column_name', $cols))
            ->groupBy('p.model_id', 'p.term_id', 'd.doc_length')
            ->select('p.model_id', 'p.term_id', 'd.doc_length as doc_len')
            ->selectRaw("{$wf} as wf", $wfBindings)
            ->orderByDesc('wf')
            ->limit($maxPostings)
            ->get();

        $scores = [];
        foreach ($postings as $row) {
            $f = (float) $row->wf;
            if ($f <= 0) {
                continue;
            }
            $td     = $termData[$row->term_id];
            $weight = (float) ($weights[$td->term] ?? 1.0);
            $n      = min((float) ($df[$row->term_id] ?? 1), $N); // never above N: the idf stays positive
            $idf    = log(($N - $n + 0.5) / ($n + 0.5) + 1);
            $tf     = ($f * ($this->k1 + 1))
                    / ($f + $this->k1 * (1 - $this->b + $this->b * (float) $row->doc_len / $avgdl));

            $scores[$row->model_id] = ($scores[$row->model_id] ?? 0) + $weight * $idf * $tf;
        }

        arsort($scores);

        return array_map(fn($score) => round($score, 6), $scores);
    }
}
