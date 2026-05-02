<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
            // Detect whether model was already indexed BEFORE removing
            $wasIndexed = DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->exists();

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

            // Bulk insert all postings
            $postingRows = [];
            foreach ($tokens as $term => $frequency) {
                $postingRows[] = [
                    'term_id'    => $termIds[$term],
                    'model_type' => $modelType,
                    'model_id'   => $modelId,
                    'frequency'  => $frequency,
                ];
            }
            if (!empty($postingRows)) {
                DB::table('fuzzy_index_postings')->insert($postingRows);
            }

            // Upsert document length
            DB::table('fuzzy_index_documents')->upsert(
                [['model_type' => $modelType, 'model_id' => $modelId, 'doc_length' => $docLength]],
                ['model_type', 'model_id'],
                ['doc_length']
            );

            // Only increment total_docs if this is a NEW indexing (not a re-index)
            $this->upsertMeta($modelType, $docLength, increment: !$wasIndexed);
        });
    }

    /**
     * Remove all index entries for a specific model instance.
     */
    public function removeFromIndex(string $modelType, int|string $modelId, bool $updateMeta = true): void
    {
        $termIds = DB::table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->pluck('term_id');

        if ($termIds->isEmpty()) {
            return;
        }

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
            DB::table('fuzzy_index_meta')
                ->where('model_type', $modelType)
                ->decrement('total_docs');
        }
    }

    /**
     * Flush (delete) the entire index for a model class.
     */
    public function flush(string $modelClass): void
    {
        $termIds = DB::table('fuzzy_index_postings')
            ->where('model_type', $modelClass)
            ->pluck('term_id')
            ->unique();

        DB::table('fuzzy_index_postings')->where('model_type', $modelClass)->delete();
        DB::table('fuzzy_index_documents')->where('model_type', $modelClass)->delete();
        DB::table('fuzzy_index_meta')->where('model_type', $modelClass)->delete();

        if ($termIds->isNotEmpty()) {
            DB::table('fuzzy_index_terms')->whereIn('id', $termIds)->delete();
        }
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

            // Clean up postings/documents for any model_ids that are being re-indexed
            if ($alreadyIndexed->isNotEmpty()) {
                DB::table('fuzzy_index_postings')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $alreadyIndexed->keys()->toArray())
                    ->delete();
                DB::table('fuzzy_index_documents')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $alreadyIndexed->keys()->toArray())
                    ->delete();
            }

            // Batch upsert all unique terms with doc_count starting at occurrence count in batch
            $termOccurrences = []; // term => count of NEW models (not already indexed) containing it
            foreach ($tokensByModel as $modelId => $tokens) {
                if ($alreadyIndexed->has($modelId)) {
                    continue; // re-indexed terms didn't change doc_count net
                }
                foreach (array_keys($tokens) as $term) {
                    $termOccurrences[$term] = ($termOccurrences[$term] ?? 0) + 1;
                }
            }

            // Upsert each term with its occurrence increment
            // Use a per-term upsert to stay portable across MySQL/SQLite/PostgreSQL
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
            $postingRows  = [];
            $documentRows = [];
            $totalNewDocs   = 0;
            $totalNewTokens = 0;
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
                }
            }

            // Bulk insert postings (chunk if very large to avoid SQL var limit)
            foreach (array_chunk($postingRows, 1000) as $chunk) {
                DB::table('fuzzy_index_postings')->insert($chunk);
            }

            // Bulk insert documents
            foreach (array_chunk($documentRows, 1000) as $chunk) {
                DB::table('fuzzy_index_documents')->insert($chunk);
            }

            // Update meta in one operation
            if ($totalNewDocs > 0) {
                $this->upsertMetaBulk($modelType, $totalNewDocs, $totalNewTokens);
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
                $stemmed          = $this->stemmer->stem($word);
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

    private function upsertMeta(string $modelType, int $docTokenCount, bool $increment): void
    {
        $existing = DB::table('fuzzy_index_meta')->where('model_type', $modelType)->first();

        if (!$existing) {
            DB::table('fuzzy_index_meta')->insert([
                'model_type'     => $modelType,
                'total_docs'     => 1,
                'total_tokens'   => $docTokenCount,
                'avg_doc_length' => $docTokenCount,
            ]);
            return;
        }

        $newTotalDocs   = $existing->total_docs + ($increment ? 1 : 0);
        $newTotalTokens = $existing->total_tokens + ($increment ? $docTokenCount : 0);

        DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->update([
                'total_docs'     => $newTotalDocs,
                'total_tokens'   => $newTotalTokens,
                'avg_doc_length' => $newTotalDocs > 0
                    ? round($newTotalTokens / $newTotalDocs, 4)
                    : 0,
            ]);
    }

    private function upsertMetaBulk(string $modelType, int $newDocs, int $newTokens): void
    {
        $existing = DB::table('fuzzy_index_meta')->where('model_type', $modelType)->first();

        if (!$existing) {
            DB::table('fuzzy_index_meta')->insert([
                'model_type'     => $modelType,
                'total_docs'     => $newDocs,
                'total_tokens'   => $newTokens,
                'avg_doc_length' => $newDocs > 0 ? round($newTokens / $newDocs, 4) : 0,
            ]);
            return;
        }

        $newTotalDocs   = $existing->total_docs + $newDocs;
        $newTotalTokens = $existing->total_tokens + $newTokens;

        DB::table('fuzzy_index_meta')
            ->where('model_type', $modelType)
            ->update([
                'total_docs'     => $newTotalDocs,
                'total_tokens'   => $newTotalTokens,
                'avg_doc_length' => $newTotalDocs > 0 ? round($newTotalTokens / $newTotalDocs, 4) : 0,
            ]);
    }
}
