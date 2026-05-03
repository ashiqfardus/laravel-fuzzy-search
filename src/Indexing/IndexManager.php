<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class IndexManager
{
    public function __construct(
        private TokenizerInterface $tokenizer,
        private StemmerInterface   $stemmer,
        private array              $stopWords = [],
    ) {
        // Normalise stop words to lowercase so they match tokenizer output regardless of caller casing
        $this->stopWords = array_map('mb_strtolower', $stopWords);
    }

    /**
     * Index (or re-index) a single model instance.
     * Removes old postings first, then writes fresh ones.
     * Does NOT inflate total_docs on re-index.
     */
    public function indexModel(Model $model): void
    {
        $modelType = get_class($model);
        $modelId   = $model->getKey();
        $columns   = $model->getSearchableColumns();

        if (empty($columns)) {
            return;
        }

        $tokens = $this->buildTokenFrequencyMap($model, $columns);

        if (empty($tokens)) {
            return;
        }

        $docLength = array_sum($tokens);

        DB::transaction(function () use ($modelType, $modelId, $tokens, $docLength) {
            // Read old doc_length BEFORE removing so we can compute the delta for avg_doc_length (C11)
            $oldDoc       = DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->first(['doc_length']);
            $wasIndexed   = $oldDoc !== null;
            $oldDocLength = $wasIndexed ? (int) ($oldDoc->doc_length ?? 0) : 0;

            $this->removeFromIndex($modelType, $modelId, updateMeta: false);

            // Batch upsert all terms
            DB::table('fuzzy_index_terms')->upsert(
                array_map(fn($term) => ['term' => $term, 'doc_count' => 1], array_keys($tokens)),
                ['term'],
                ['doc_count' => DB::raw('doc_count + 1')]
            );

            // Fetch all term IDs in one query
            $termIds = DB::table('fuzzy_index_terms')
                ->whereIn('term', array_keys($tokens))
                ->pluck('id', 'term');

            // Build posting rows
            $postingRows = [];
            foreach ($tokens as $term => $frequency) {
                $postingRows[] = [
                    'term_id'    => $termIds[$term],
                    'model_type' => $modelType,
                    'model_id'   => $modelId,
                    'frequency'  => $frequency,
                ];
            }

            // Upsert postings — INSERT ... ON DUPLICATE KEY UPDATE prevents concurrent-worker
            // collisions on the UNIQUE (term_id, model_type, model_id) constraint (C9)
            if (!empty($postingRows)) {
                DB::table('fuzzy_index_postings')->upsert(
                    $postingRows,
                    ['term_id', 'model_type', 'model_id'],
                    ['frequency']
                );
            }

            // Upsert document length
            DB::table('fuzzy_index_documents')->upsert(
                [['model_type' => $modelType, 'model_id' => $modelId, 'doc_length' => $docLength]],
                ['model_type', 'model_id'],
                ['doc_length']
            );

            $this->upsertMeta($modelType, $docLength, isNewDoc: !$wasIndexed, oldDocLength: $oldDocLength);
        });
    }

    /**
     * Remove all index entries for a specific model instance.
     */
    public function removeFromIndex(string $modelType, int|string $modelId, bool $updateMeta = true): void
    {
        DB::transaction(function () use ($modelType, $modelId, $updateMeta) {
            $termIds = DB::table('fuzzy_index_postings')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->pluck('term_id');

            if ($termIds->isEmpty()) {
                return;
            }

            // Capture old doc_length before the document row is deleted — needed to keep
            // total_tokens (and thus avg_doc_length) accurate when updateMeta=true (C11)
            $oldDocLength = $updateMeta
                ? (int) (DB::table('fuzzy_index_documents')
                    ->where('model_type', $modelType)
                    ->where('model_id', $modelId)
                    ->value('doc_length') ?? 0)
                : 0;

            DB::table('fuzzy_index_postings')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->delete();

            DB::table('fuzzy_index_terms')
                ->whereIn('id', $termIds)
                ->decrement('doc_count');

            DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->delete();

            if ($updateMeta) {
                // Two-step meta update wrapped in the enclosing transaction so no concurrent
                // BM25 read can observe an inconsistent avg_doc_length between the two UPDATEs
                DB::table('fuzzy_index_meta')
                    ->where('model_type', $modelType)
                    ->update([
                        'total_docs'   => DB::raw('CASE WHEN total_docs > 0 THEN total_docs - 1 ELSE 0 END'),
                        'total_tokens' => DB::raw(
                            'CASE WHEN total_tokens >= ' . $oldDocLength .
                            ' THEN total_tokens - ' . $oldDocLength . ' ELSE 0 END'
                        ),
                    ]);

                DB::table('fuzzy_index_meta')
                    ->where('model_type', $modelType)
                    ->update([
                        'avg_doc_length' => DB::raw(
                            'CASE WHEN total_docs > 0 THEN 1.0 * total_tokens / total_docs ELSE 0 END'
                        ),
                    ]);
            }
        });
    }

    /**
     * Flush (delete) the entire index for a model class.
     */
    public function flush(string $modelClass): void
    {
        DB::transaction(function () use ($modelClass) {
            // Bulk delete index data for this model type — DB-side, no PHP memory load.
            DB::table('fuzzy_index_postings')->where('model_type', $modelClass)->delete();
            DB::table('fuzzy_index_documents')->where('model_type', $modelClass)->delete();
            DB::table('fuzzy_index_meta')->where('model_type', $modelClass)->delete();

            // Clean up orphan terms (those with no remaining postings) via DB-side JOIN
            // — avoids loading million-row term_id arrays into PHP memory.
            $driver = DB::connection()->getDriverName();

            if ($driver === 'mysql') {
                DB::statement(
                    'DELETE t FROM fuzzy_index_terms t ' .
                    'LEFT JOIN fuzzy_index_postings p ON t.id = p.term_id ' .
                    'WHERE p.id IS NULL'
                );
            } elseif ($driver === 'pgsql') {
                DB::statement(
                    'DELETE FROM fuzzy_index_terms t ' .
                    'WHERE NOT EXISTS (SELECT 1 FROM fuzzy_index_postings p WHERE p.term_id = t.id)'
                );
            } else {
                DB::table('fuzzy_index_terms')
                    ->whereNotExists(function ($q) {
                        $q->selectRaw('1')
                          ->from('fuzzy_index_postings')
                          ->whereColumn('fuzzy_index_postings.term_id', 'fuzzy_index_terms.id');
                    })
                    ->delete();
            }
        });
    }

    /**
     * Tokenize + stem search input — used by Bm25Scorer and didYouMean().
     */
    public function processTerms(string $text): array
    {
        $words = $this->tokenizer->tokenize($text);
        $terms = [];
        foreach ($words as $word) {
            if (!in_array($word, $this->stopWords, true)) {
                $terms[] = $this->stemmer->stem($word);
            }
        }
        return array_unique($terms);
    }

    /**
     * Bulk-index a collection of models in a single transaction.
     * For 500 models, executes ~5 queries instead of 500 × 7.
     * Used by RebuildCommand for fast initial builds.
     *
     * @param iterable<Model> $models
     */
    public function indexBatch(iterable $models): int
    {
        $modelType     = null;
        $tokensByModel = []; // model_id => [term => freq]
        $allTerms      = []; // unique terms across batch

        foreach ($models as $model) {
            if ($modelType === null) {
                $modelType = get_class($model);
            }

            $columns = $model->getSearchableColumns();
            if (empty($columns)) {
                continue;
            }

            $tokens = $this->buildTokenFrequencyMap($model, $columns);
            if (empty($tokens)) {
                continue;
            }

            $tokensByModel[$model->getKey()] = $tokens;
            foreach (array_keys($tokens) as $term) {
                $allTerms[$term] = true;
            }
        }

        if (empty($tokensByModel)) {
            return 0;
        }

        $allTerms = array_keys($allTerms);
        $modelIds = array_keys($tokensByModel);

        return DB::transaction(function () use ($modelType, $tokensByModel, $allTerms, $modelIds) {
            // Find which models in this batch are already indexed (for accurate meta)
            $alreadyIndexed = DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->whereIn('model_id', $modelIds)
                ->pluck('model_id')
                ->flip();

            // For re-indexed models: capture per-term model-counts and old total_tokens
            // BEFORE deleting. We need per-term counts (not a flat -1) because N models
            // may share a term — decrementing by 1 would under-correct doc_count. (C12)
            // Old token sum is used to keep avg_doc_length accurate. (C11)
            $oldReindexTermCounts = collect(); // term_id => number of re-indexed models that had it
            $oldReindexTokens     = 0;

            if ($alreadyIndexed->isNotEmpty()) {
                $reindexIds = $alreadyIndexed->keys()->toArray();

                // COUNT(*) per term_id = number of models in the batch that had this term
                $oldReindexTermCounts = DB::table('fuzzy_index_postings')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $reindexIds)
                    ->groupBy('term_id')
                    ->selectRaw('term_id, COUNT(*) as cnt')
                    ->pluck('cnt', 'term_id');

                $oldReindexTokens = (int) DB::table('fuzzy_index_documents')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $reindexIds)
                    ->sum('doc_length');

                DB::table('fuzzy_index_postings')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $reindexIds)
                    ->delete();
                DB::table('fuzzy_index_documents')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $reindexIds)
                    ->delete();

                // Decrement doc_count by the exact number of removed models per term (C12)
                foreach ($oldReindexTermCounts as $termId => $cnt) {
                    DB::table('fuzzy_index_terms')
                        ->where('id', $termId)
                        ->update([
                            'doc_count' => DB::raw(
                                "CASE WHEN doc_count >= {$cnt} THEN doc_count - {$cnt} ELSE 0 END"
                            ),
                        ]);
                }
            }

            // Count term occurrences across ALL models (new + re-indexed).
            // Re-indexed models had their old doc_counts decremented above, so we must
            // also increment for their new token sets to keep doc_count correct (C12).
            $termOccurrences = [];
            foreach ($tokensByModel as $modelId => $tokens) {
                foreach (array_keys($tokens) as $term) {
                    $termOccurrences[$term] = ($termOccurrences[$term] ?? 0) + 1;
                }
            }

            // Upsert each term with its occurrence increment
            foreach ($allTerms as $term) {
                $increment = $termOccurrences[$term] ?? 0;
                DB::table('fuzzy_index_terms')->upsert(
                    [['term' => $term, 'doc_count' => $increment]],
                    ['term'],
                    ['doc_count' => DB::raw("doc_count + {$increment}")]
                );
            }

            // Fetch term IDs
            $termIds = DB::table('fuzzy_index_terms')
                ->whereIn('term', $allTerms)
                ->pluck('id', 'term');

            // Build all postings rows
            $postingRows      = [];
            $documentRows     = [];
            $totalNewDocs     = 0;
            $totalNewTokens   = 0;
            $reindexNewTokens = 0;

            foreach ($tokensByModel as $modelId => $tokens) {
                $docLength = array_sum($tokens);
                $documentRows[] = [
                    'model_type' => $modelType,
                    'model_id'   => $modelId,
                    'doc_length' => $docLength,
                ];
                foreach ($tokens as $term => $frequency) {
                    $postingRows[] = [
                        'term_id'    => $termIds[$term],
                        'model_type' => $modelType,
                        'model_id'   => $modelId,
                        'frequency'  => $frequency,
                    ];
                }
                if (!$alreadyIndexed->has($modelId)) {
                    $totalNewDocs++;
                    $totalNewTokens += $docLength;
                } else {
                    $reindexNewTokens += $docLength;
                }
            }

            // Upsert postings — prevents concurrent-worker UNIQUE constraint failures (C9)
            foreach (array_chunk($postingRows, 1000) as $chunk) {
                DB::table('fuzzy_index_postings')->upsert(
                    $chunk,
                    ['term_id', 'model_type', 'model_id'],
                    ['frequency']
                );
            }

            // Upsert documents
            foreach (array_chunk($documentRows, 1000) as $chunk) {
                DB::table('fuzzy_index_documents')->upsert(
                    $chunk,
                    ['model_type', 'model_id'],
                    ['doc_length']
                );
            }

            // Update meta: add new docs and adjust total_tokens for both new and re-indexed docs (C11)
            $reindexTokenDelta = $reindexNewTokens - $oldReindexTokens;
            if ($totalNewDocs > 0 || $reindexTokenDelta !== 0) {
                $this->upsertMetaBulk($modelType, $totalNewDocs, $totalNewTokens, $reindexTokenDelta);
            }

            return count($tokensByModel);
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildTokenFrequencyMap(Model $model, array $columns): array
    {
        $tokens    = [];
        $maxTokens = config('fuzzy-search.indexing.max_tokens_per_doc', 5000);

        foreach ($columns as $column) {
            $value = $model->getAttribute($column);
            if (empty($value)) {
                continue;
            }
            foreach ($this->tokenizer->tokenize((string) $value) as $word) {
                if (in_array($word, $this->stopWords, true)) {
                    continue;
                }
                $stemmed = $this->stemmer->stem($word);
                if (strlen($stemmed) > 255) {
                    continue; // token exceeds varchar(255) — skip rather than truncate silently
                }
                $tokens[$stemmed] = ($tokens[$stemmed] ?? 0) + 1;

                if (count($tokens) >= $maxTokens) {
                    \Illuminate\Support\Facades\Log::warning(
                        'fuzzy-search: Token cap (' . $maxTokens . ') reached for ' .
                        get_class($model) . ' id=' . $model->getKey() . '. Extra tokens discarded.'
                    );
                    return $tokens;
                }
            }
        }
        return $tokens;
    }

    /**
     * Insert or atomically update the meta row for a model class after indexing a single model.
     *
     * @param bool $isNewDoc     true on first index, false on re-index
     * @param int  $oldDocLength token count of the previous version (0 if new) — used to
     *                           correct total_tokens drift on re-index (C11)
     */
    private function upsertMeta(string $modelType, int $docLength, bool $isNewDoc, int $oldDocLength = 0): void
    {
        // Ensure the row exists before updating (safe against concurrent first-insert race — C10)
        DB::table('fuzzy_index_meta')->insertOrIgnore([
            'model_type'     => $modelType,
            'total_docs'     => 0,
            'total_tokens'   => 0,
            'avg_doc_length' => 0,
        ]);

        if ($isNewDoc) {
            DB::table('fuzzy_index_meta')
                ->where('model_type', $modelType)
                ->update([
                    'total_docs'   => DB::raw('total_docs + 1'),
                    'total_tokens' => DB::raw("total_tokens + {$docLength}"),
                ]);
        } else {
            $delta = $docLength - $oldDocLength;
            if ($delta !== 0) {
                $abs  = abs($delta);
                $expr = $delta > 0
                    ? "total_tokens + {$abs}"
                    : "CASE WHEN total_tokens >= {$abs} THEN total_tokens - {$abs} ELSE 0 END";
                DB::table('fuzzy_index_meta')
                    ->where('model_type', $modelType)
                    ->update(['total_tokens' => DB::raw($expr)]);
            }
        }

        // Recompute avg_doc_length from the now-consistent total_docs / total_tokens (C11)
        DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->update([
                'avg_doc_length' => DB::raw(
                    'CASE WHEN total_docs > 0 THEN 1.0 * total_tokens / total_docs ELSE 0 END'
                ),
            ]);
    }

    /**
     * Bulk-update meta after indexBatch().
     *
     * @param int $newDocs           number of genuinely new (never-before-indexed) models
     * @param int $newTokens         total token count of the new models
     * @param int $reindexTokenDelta net change in token count for re-indexed models
     *                               (new total − old total); may be negative (C11)
     */
    private function upsertMetaBulk(string $modelType, int $newDocs, int $newTokens, int $reindexTokenDelta = 0): void
    {
        // Ensure the row exists (race-safe — C10)
        DB::table('fuzzy_index_meta')->insertOrIgnore([
            'model_type'     => $modelType,
            'total_docs'     => 0,
            'total_tokens'   => 0,
            'avg_doc_length' => 0,
        ]);

        $tokenAdjustment = $newTokens + $reindexTokenDelta;

        $updates = [];
        if ($newDocs > 0) {
            $updates['total_docs'] = DB::raw("total_docs + {$newDocs}");
        }
        if ($tokenAdjustment !== 0) {
            $abs = abs($tokenAdjustment);
            $updates['total_tokens'] = DB::raw(
                $tokenAdjustment >= 0
                    ? "total_tokens + {$abs}"
                    : "CASE WHEN total_tokens >= {$abs} THEN total_tokens - {$abs} ELSE 0 END"
            );
        }

        if (!empty($updates)) {
            DB::table('fuzzy_index_meta')
                ->where('model_type', $modelType)
                ->update($updates);
        }

        DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->update([
                'avg_doc_length' => DB::raw(
                    'CASE WHEN total_docs > 0 THEN 1.0 * total_tokens / total_docs ELSE 0 END'
                ),
            ]);
    }
}
