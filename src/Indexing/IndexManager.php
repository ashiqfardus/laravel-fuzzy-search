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
    ) {}

    /**
     * Index (or re-index) a single model instance.
     * Removes old postings first, then writes fresh ones.
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

        DB::transaction(function () use ($modelType, $modelId, $tokens) {
            $this->removeFromIndex($modelType, $modelId, updateMeta: false);

            foreach ($tokens as $term => $frequency) {
                DB::table('fuzzy_index_terms')
                    ->upsert(
                        ['term' => $term, 'doc_count' => 1],
                        ['term'],
                        ['doc_count' => DB::raw('doc_count + 1')]
                    );

                $termId = DB::table('fuzzy_index_terms')->where('term', $term)->value('id');

                DB::table('fuzzy_index_postings')->insert([
                    'term_id'    => $termId,
                    'model_type' => $modelType,
                    'model_id'   => $modelId,
                    'frequency'  => $frequency,
                ]);
            }

            $this->upsertMeta($modelType, count($tokens), increment: true);
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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function buildTokenFrequencyMap(Model $model, array $columns): array
    {
        $tokens = [];
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
}
