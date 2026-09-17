<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ashiqfardus\LaravelFuzzySearch\Support\StopWords;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class IndexManager
{
    private Pipeline $default;

    public function __construct(
        private TokenizerInterface $tokenizer,
        private StemmerInterface   $stemmer,
        private array              $stopWords = [],
    ) {
        // Normalise stop words to lowercase so they match tokenizer output regardless of caller casing
        $this->stopWords = array_map('mb_strtolower', $stopWords);

        $this->default = new Pipeline($tokenizer, $stemmer, $stopWords, (bool) config('fuzzy-search.indexing.accent_insensitive', false));
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

        if (empty($columns) && !method_exists($model, 'searchableText')) {
            return;
        }

        $byColumn = $this->buildTokenFrequencyMap($model, $columns);
        $tokens   = $this->mergeColumnFrequencies($byColumn);

        if (empty($tokens)) {
            // The model's indexable text became empty (tags/relations cleared, hook now
            // returns nothing) — clear any stale postings from a previous index instead of
            // silently leaving them searchable.
            $this->removeFromIndex($modelType, $modelId);
            return;
        }

        $docLength = array_sum($tokens);

        DB::transaction(function () use ($modelType, $modelId, $tokens, $byColumn, $docLength) {
            // Read old doc_length BEFORE removing so we can compute the delta for avg_doc_length (C11)
            $oldDoc       = DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->first(['doc_length']);
            $wasIndexed   = $oldDoc !== null;
            $oldDocLength = $wasIndexed ? (int) ($oldDoc->doc_length ?? 0) : 0;

            $this->removeFromIndex($modelType, $modelId, updateMeta: false);

            // PHP normalises numeric-string array keys (e.g. '10') to int keys, so
            // array_keys($tokens) can yield an int for a purely numeric token. Cast back to
            // string wherever a key becomes a query binding: SQL Server's MERGE ... USING
            // (VALUES (...)) infers one type per column from the batch of bindings, so a
            // mixed int/string 'term' column fails with "Conversion failed when converting
            // the nvarchar value 'paginate' to data type int". $termIds[$term] lookups below
            // still work because PHP normalises numeric-string keys the same way on read.
            $termKeys = array_map('strval', array_keys($tokens));

            // Batch upsert all terms
            DB::table('fuzzy_index_terms')->upsert(
                array_map(fn($term) => [
                    'term'        => (string) $term,
                    'doc_count'   => 1,
                    'term_length' => mb_strlen((string) $term),
                ], $termKeys),
                ['term'],
                // Table-qualified: PostgreSQL treats a bare "doc_count" as ambiguous inside
                // ON CONFLICT DO UPDATE. The qualified form is valid on MySQL/MariaDB
                // (ON DUPLICATE KEY UPDATE), SQLite, PostgreSQL and SQL Server (MERGE target).
                ['doc_count' => DB::raw('fuzzy_index_terms.doc_count + 1')]
            );

            // Fetch all term IDs in one query
            $termIds = DB::table('fuzzy_index_terms')
                ->whereIn('term', $termKeys)
                ->pluck('id', 'term');

            // Build posting rows — one per (term, column); a term missing from $termIds means
            // a pre-migration MySQL/MariaDB *_ci collation collapsed it into a variant (B25).
            $postingRows = $this->postingRows($byColumn, $termIds, $modelType, $modelId);

            // Upsert postings — INSERT ... ON DUPLICATE KEY UPDATE prevents concurrent-worker
            // collisions on the UNIQUE (term_id, model_type, model_id, column_name) constraint (C9)
            if (!empty($postingRows)) {
                DB::table('fuzzy_index_postings')->upsert(
                    $postingRows,
                    ['term_id', 'model_type', 'model_id', 'column_name'],
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
                ->distinct()
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

            // Guard against underflow on unsigned columns under concurrent deletes
            DB::table('fuzzy_index_terms')
                ->whereIn('id', $termIds)
                ->update([
                    'doc_count' => DB::raw('CASE WHEN doc_count > 0 THEN doc_count - 1 ELSE 0 END'),
                ]);

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

            if (\Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::isMySqlFamily($driver)) {
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

    /** @var array<string, Pipeline> model class → resolved pipeline */
    private static array $pipelines = [];

    public static function resetPipelineCache(): void
    {
        self::$pipelines = [];
    }

    /**
     * The pipeline (tokenizer, stemmer, stop words, accent folding) for one model class.
     * Resolves $searchable['tokenizer'|'stemmer'|'stemmer_language'|'locale'] overrides
     * (Phase 7), cached per class; non-Searchable classes and null get the default pipeline.
     */
    public function pipelineFor(?string $modelClass): Pipeline
    {
        if ($modelClass === null || !class_exists($modelClass) || !method_exists($modelClass, 'getSearchablePipeline')) {
            return $this->default;
        }

        return self::$pipelines[$modelClass] ??= $this->resolvePipeline($modelClass);
    }

    private function resolvePipeline(string $modelClass): Pipeline
    {
        $overrides = (new $modelClass)->getSearchablePipeline();
        if ($overrides === []) {
            return $this->default;
        }

        $tokenizer = $this->default->tokenizer();
        if (isset($overrides['tokenizer'])) {
            $tokenizer = $this->instantiate($overrides['tokenizer'], TokenizerInterface::class, $modelClass, 'tokenizer');
        }

        $stemmer = $this->default->stemmer();
        if (isset($overrides['stemmer']) || isset($overrides['stemmer_language'])) {
            $class   = $overrides['stemmer'] ?? get_class($this->default->stemmer());
            $stemmer = $this->instantiate($class, StemmerInterface::class, $modelClass, 'stemmer', $overrides['stemmer_language'] ?? null);
        }

        $stopWords = $this->default->stopWords();
        if (isset($overrides['locale'])) {
            $stopWords = StopWords::forLocale($overrides['locale']);
        }

        return new Pipeline($tokenizer, $stemmer, $stopWords, $this->default->foldsAccents());
    }

    private function instantiate(string $class, string $interface, string $modelClass, string $key, ?string $language = null): object
    {
        if (!class_exists($class) || !is_subclass_of($class, $interface)) {
            throw new \InvalidArgumentException("{$modelClass}::\$searchable['{$key}'] must name a class implementing {$interface}, got '{$class}'.");
        }

        if ($language === null) {
            return new $class();
        }

        // A language only means something to a stemmer that takes one (PorterStemmer). Constructing
        // NullStemmer('French') would silently ignore it — say so instead of stemming nothing.
        $ctor = (new \ReflectionClass($class))->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            throw new \InvalidArgumentException("{$modelClass}::\$searchable['stemmer_language'] needs a stemmer that accepts a language (for example PorterStemmer); {$class} takes none.");
        }

        return new $class($language);
    }

    /**
     * Tokenize + stem search input — used by Bm25Scorer, SearchBuilder and didYouMean().
     * $stopWords: null keeps the configured locale list; an array is ADDED to it for this call
     * (SearchBuilder::ignoreStopWords() on the inverted-index path). Adding rather than
     * replacing is the only useful behaviour here: a term dropped at index time cannot match
     * anyway, so restoring a configured stop word would only feed typo expansion with noise.
     * $modelClass selects the pipeline (Task 3 adds per-model overrides); null uses the default.
     *
     * @return string[]
     */
    public function processTerms(string $text, ?array $stopWords = null, ?string $modelClass = null): array
    {
        return array_values(array_unique($this->pipelineFor($modelClass)->tokens($text, $stopWords ?? [])));
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
        $modelType      = null;
        $tokensByModel  = []; // model_id => [term => freq] (merged across columns)
        $columnsByModel = []; // model_id => [column => [term => freq]]
        $allTerms       = []; // unique terms across batch

        foreach ($models as $model) {
            if ($modelType === null) {
                $modelType = get_class($model);
            }

            $columns = $model->getSearchableColumns();
            if (empty($columns) && !method_exists($model, 'searchableText')) {
                continue;
            }

            $byColumn = $this->buildTokenFrequencyMap($model, $columns);
            $tokens   = $this->mergeColumnFrequencies($byColumn);
            if (empty($tokens)) {
                // Same as indexModel(): a model whose text emptied out must lose its stale
                // postings, not just be skipped from the batch's re-index.
                $this->removeFromIndex($modelType, $model->getKey());
                continue;
            }

            $tokensByModel[$model->getKey()]  = $tokens;
            $columnsByModel[$model->getKey()] = $byColumn;
            foreach (array_keys($tokens) as $term) {
                $allTerms[$term] = true;
            }
        }

        if (empty($tokensByModel)) {
            return 0;
        }

        // See the comment in indexModel(): numeric-string keys like '10' are normalised to
        // int by PHP, and SQL Server's MERGE ... USING (VALUES (...)) fails when the 'term'
        // column mixes int and string bindings. Cast back to string.
        $allTerms = array_map('strval', array_keys($allTerms));
        $modelIds = array_keys($tokensByModel);

        return DB::transaction(function () use ($modelType, $tokensByModel, $columnsByModel, $allTerms, $modelIds) {
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

                // COUNT(DISTINCT model_id) per term_id = number of models in the batch that had
                // this term (a term now has one posting row per column it appears in, so
                // COUNT(*) would double-count a term that lives in two columns of one document).
                $oldReindexTermCounts = DB::table('fuzzy_index_postings')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $reindexIds)
                    ->groupBy('term_id')
                    ->selectRaw('term_id, COUNT(DISTINCT model_id) as cnt')
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

                // Decrement doc_count per term in a single UPDATE to avoid N round-trips.
                // $termId and $cnt are PHP ints sourced from the DB — not user input.
                if ($oldReindexTermCounts->isNotEmpty()) {
                    $cases   = '';
                    $termIds = [];
                    foreach ($oldReindexTermCounts as $termId => $cnt) {
                        $termId  = (int) $termId;
                        $cnt     = (int) $cnt;
                        $cases  .= " WHEN {$termId} THEN CASE WHEN doc_count >= {$cnt} THEN doc_count - {$cnt} ELSE 0 END";
                        $termIds[] = $termId;
                    }
                    $inList = implode(',', $termIds);
                    DB::statement(
                        "UPDATE fuzzy_index_terms SET doc_count = CASE id{$cases} ELSE doc_count END WHERE id IN ({$inList})"
                    );
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
                    [['term' => $term, 'doc_count' => $increment, 'term_length' => mb_strlen((string) $term)]],
                    ['term'],
                    ['doc_count' => DB::raw("fuzzy_index_terms.doc_count + {$increment}")]
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
                foreach ($this->postingRows($columnsByModel[$modelId], $termIds, $modelType, $modelId) as $row) {
                    $postingRows[] = $row;
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
                    ['term_id', 'model_type', 'model_id', 'column_name'],
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

    /**
     * The texts to index for a model: the searchableText() hook when the model defines one
     * (any keys, related data allowed), otherwise the searchable columns' attributes.
     *
     * A hook value may be a Collection or array (e.g. `$this->tags->pluck('name')`) — its
     * scalar items are joined with a space so callers don't have to implode() themselves.
     * Any other non-scalar (an object without __toString) is a hook bug, not silently
     * indexable text, so it throws rather than being coerced into a warning-laden string.
     *
     * @return array<string, string> name => non-empty text
     * @throws \InvalidArgumentException if a hook value is a non-scalar, non-stringable object
     */
    private function searchableTexts(Model $model, array $columns): array
    {
        $texts = method_exists($model, 'searchableText')
            ? (array) $model->searchableText()
            : array_combine($columns, array_map(fn ($c) => $model->getAttribute($c), $columns));

        $clean = [];
        foreach ($texts as $name => $value) {
            if ($value instanceof \Illuminate\Support\Collection) {
                $value = $value->all();
            }

            if (is_array($value)) {
                $value = implode(' ', array_map('strval', array_filter($value, 'is_scalar')));
            } elseif (is_object($value) && !method_exists($value, '__toString')) {
                throw new \InvalidArgumentException(
                    'fuzzy-search: searchableText() value for "' . $name . '" on ' . get_class($model) .
                    ' is a ' . get_class($value) . ', which cannot be indexed as text. ' .
                    'Return a string, scalar, array, or Collection of scalars instead.'
                );
            }

            if ($value === null || $value === '') { // empty() would also skip the legitimate string "0"
                continue;
            }
            $clean[$name] = (string) $value;
        }
        return $clean;
    }

    /**
     * Term frequencies per searchable column (or searchableText() key), stemmed and
     * stop-word filtered: ['title' => ['widget' => 2], 'body' => ['widget' => 1]].
     * The token cap counts distinct (column, term) pairs for the whole document.
     *
     * @return array<string, array<string, int>>
     */
    private function buildTokenFrequencyMap(Model $model, array $columns): array
    {
        $byColumn  = [];
        $distinct  = 0;
        $maxTokens = config('fuzzy-search.indexing.max_tokens_per_doc', 5000);

        $pipeline = $this->pipelineFor(get_class($model));

        foreach ($this->searchableTexts($model, $columns) as $name => $value) {
            $column = mb_substr((string) $name, 0, 64);
            foreach ($pipeline->tokens($value) as $stemmed) {
                if (strlen($stemmed) > 255) {
                    continue; // token exceeds varchar(255) — skip rather than truncate silently
                }
                if (!isset($byColumn[$column][$stemmed])) {
                    $distinct++;
                }
                $byColumn[$column][$stemmed] = ($byColumn[$column][$stemmed] ?? 0) + 1;

                if ($distinct >= $maxTokens) {
                    \Illuminate\Support\Facades\Log::warning(
                        'fuzzy-search: Token cap (' . $maxTokens . ') reached for ' .
                        get_class($model) . ' id=' . $model->getKey() . '. Extra tokens discarded.'
                    );
                    return $byColumn;
                }
            }
        }
        return $byColumn;
    }

    /** Per-document term frequencies (term => total across columns) — doc_length and doc_count use this. */
    private function mergeColumnFrequencies(array $byColumn): array
    {
        $tokens = [];
        foreach ($byColumn as $terms) {
            foreach ($terms as $term => $frequency) {
                $tokens[$term] = ($tokens[$term] ?? 0) + $frequency;
            }
        }
        return $tokens;
    }

    /**
     * One posting row per (term, column). Terms missing from $termIds are skipped (B25: an
     * un-migrated *_ci dictionary collapsed them into a variant).
     */
    private function postingRows(array $byColumn, $termIds, string $modelType, int|string $modelId): array
    {
        $rows = [];
        foreach ($byColumn as $column => $terms) {
            foreach ($terms as $term => $frequency) {
                if (!isset($termIds[$term])) {
                    continue;
                }
                $rows[] = [
                    'term_id'     => $termIds[$term],
                    'model_type'  => $modelType,
                    'model_id'    => $modelId,
                    'column_name' => (string) $column,
                    'frequency'   => $frequency,
                ];
            }
        }
        return $rows;
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
        $this->ensureMetaRow($modelType);

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
        $this->ensureMetaRow($modelType);

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

    /**
     * Guarantee the meta row exists without clobbering its counters.
     *
     * upsert() is implemented on every supported driver (MySQL/MariaDB ON DUPLICATE KEY,
     * PostgreSQL/SQLite ON CONFLICT, SQL Server MERGE). insertOrIgnore() is not: Laravel's
     * SqlServerGrammar throws. Updating model_type to itself makes the conflict branch a no-op.
     */
    private function ensureMetaRow(string $modelType): void
    {
        DB::table('fuzzy_index_meta')->upsert(
            [[
                'model_type'     => $modelType,
                'total_docs'     => 0,
                'total_tokens'   => 0,
                'avg_doc_length' => 0,
            ]],
            ['model_type'],
            ['model_type']
        );
    }
}
