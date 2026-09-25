<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Support\IndexQuery;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Support\StopWords;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class IndexManager
{
    /**
     * Rows per upsert, so no statement passes SQL Server's 2,100 bindings: a posting row binds
     * five values (2,000 a statement), a dictionary or document row three (1,500).
     */
    private const POSTING_ROWS_PER_UPSERT = 400;
    private const ROWS_PER_UPSERT         = 500;

    // Every model id is bound as a string: model_id is a varchar, and an integer bound against it
    // makes MySQL/MariaDB compare numerically and SQL Server convert the column, so neither can
    // seek the key. The claim's FOR UPDATE then scans, and locks, every document row of the
    // model type: writes for different rows waited on each other and deadlocked (1213).

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
     * Whether $model has anything to index: searchable columns (declared or auto-detected) or a
     * searchableText() hook. The one rule the indexer and the search path share: a model it
     * fails is never indexed, so a search of it has nothing to find.
     */
    public static function indexesModel(Model $model): bool
    {
        return method_exists($model, 'searchableText')
            || (method_exists($model, 'getSearchableColumns') && $model->getSearchableColumns() !== []);
    }

    /**
     * Index (or re-index) one model: the row as it is committed now, reloaded after its document
     * row is claimed (see write()). An unsaved instance is indexed as given.
     */
    public function indexModel(Model $model): void
    {
        if (!self::indexesModel($model)) {
            return;
        }

        $this->indexBatch([$model]);
    }

    /**
     * Bring one model's index entries in line with its committed row: index the row as it is now,
     * or remove its entries when it is gone or soft-deleted. IndexModelJob runs this, so a job
     * never writes a row it loaded before a newer save.
     */
    public function syncModel(string $modelClass, int|string $key): void
    {
        $this->write($modelClass, [$key], []);
    }

    /**
     * Remove all index entries for a specific model instance.
     */
    public function removeFromIndex(string $modelType, int|string $modelId): void
    {
        $modelId = (string) $modelId; // bound as a string: see the note at the top of the class

        DB::transaction(function () use ($modelType, $modelId) {
            // Waits for a write to this row in flight; empty = it was not indexed.
            $old = $this->claimDocuments($modelType, [$modelId]);

            $this->deletePostings($modelType, [$modelId]);

            // The document row, or the placeholder the claim just inserted.
            DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->delete();

            $this->adjustMeta($modelType, -count($old), -array_sum($old), ensure: false);
        });
    }

    /**
     * Claim the models' document rows for the enclosing transaction: insert a placeholder
     * (doc_length 0) where none exists, then lock every row. Every write to a model's index
     * entries (write() and removeFromIndex(), and so IndexModelJob, the Scout engine and the
     * rebuild batches) starts here, so two writes for one model run one after the other: the
     * second waits for the first to commit, then reads what it wrote. Without the
     * claim both read "not indexed yet" and both added the model to doc_count and total_docs.
     * The upsert also waits for a concurrent first insert of the row, which a lock on a missing
     * row cannot; SQLite takes its database write lock on it. A crashed process releases the
     * lock with its connection. Ids are claimed in sorted order, so two batches cannot deadlock.
     *
     * @param  array<int|string>  $modelIds
     * @return array<int|string, int> model id => doc_length, for the models already indexed
     */
    private function claimDocuments(string $modelType, array $modelIds): array
    {
        $modelIds = array_map('strval', $modelIds);
        sort($modelIds, SORT_STRING);
        $indexed = [];

        foreach (array_chunk($modelIds, self::ROWS_PER_UPSERT) as $chunk) {
            DB::table('fuzzy_index_documents')->upsert(
                array_map(fn ($id) => ['model_type' => $modelType, 'model_id' => $id, 'doc_length' => 0], $chunk),
                ['model_type', 'model_id'],
                ['model_type'] // a no-op update: the conflict branch only takes the row lock
            );

            $rows = DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->whereIn('model_id', $chunk)
                ->lockForUpdate()
                ->pluck('doc_length', 'model_id');

            foreach ($rows as $id => $length) {
                if ((int) $length > 0) {
                    $indexed[$id] = (int) $length;
                }
            }
        }

        return $indexed;
    }

    /**
     * Delete the models' postings and give back their share of each term's doc_count:
     * COUNT(DISTINCT model_id) per term, since a document has one posting per column a term is in.
     *
     * @param array<int|string> $modelIds
     */
    private function deletePostings(string $modelType, array $modelIds): void
    {
        foreach (array_chunk(array_map('strval', $modelIds), 1000) as $chunk) {
            $counts = DB::table('fuzzy_index_postings')
                ->where('model_type', $modelType)
                ->whereIn('model_id', $chunk)
                ->groupBy('term_id')
                ->selectRaw('term_id, COUNT(DISTINCT model_id) as cnt')
                ->pluck('cnt', 'term_id');

            if ($counts->isEmpty()) {
                continue;
            }

            DB::table('fuzzy_index_postings')
                ->where('model_type', $modelType)
                ->whereIn('model_id', $chunk)
                ->delete();

            $this->decrementDocCounts($counts->all());
        }
    }

    /**
     * Flush (delete) the entire index for a model class.
     */
    public function flush(string $modelClass): void
    {
        DB::transaction(function () use ($modelClass) {
            // Give back this model's share of every term's doc_count first. A term another model
            // still uses survives the orphan sweep below, and it kept counting this model's
            // documents: every rebuild --fresh inflated it. One chunk of terms in memory at a time.
            DB::table('fuzzy_index_postings')
                ->where('model_type', $modelClass)
                ->groupBy('term_id')
                ->selectRaw('term_id, COUNT(DISTINCT model_id) as cnt')
                ->chunkById(1000, fn ($rows) => $this->decrementDocCounts($rows->pluck('cnt', 'term_id')->all()), 'term_id');

            // Bulk delete index data for this model type — DB-side, no PHP memory load.
            DB::table('fuzzy_index_postings')->where('model_type', $modelClass)->delete();
            DB::table('fuzzy_index_documents')->where('model_type', $modelClass)->delete();
            DB::table('fuzzy_index_meta')->where('model_type', $modelClass)->delete();

            // Clean up orphan terms (those with no remaining postings) via DB-side JOIN
            // — avoids loading million-row term_id arrays into PHP memory.
            $driver   = DB::connection()->getDriverName();
            $terms    = DbDialect::rawIdentifier('fuzzy_index_terms');
            $postings = DbDialect::rawIdentifier('fuzzy_index_postings');

            if (\Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::isMySqlFamily($driver)) {
                DB::statement(
                    "DELETE t FROM {$terms} t " .
                    "LEFT JOIN {$postings} p ON t.id = p.term_id " .
                    'WHERE p.id IS NULL'
                );
            } elseif ($driver === 'pgsql') {
                DB::statement(
                    "DELETE FROM {$terms} t " .
                    "WHERE NOT EXISTS (SELECT 1 FROM {$postings} p WHERE p.term_id = t.id)"
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

    /** @var array<string, Pipeline|false> model class → resolved override pipeline, or false = "uses the default" */
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

        // Override pipelines are cached as objects; an override-less class is cached as `false` and
        // answered with THIS instance's default, so the resolution (a model construction) runs once
        // per class and a second IndexManager (tests, forgetInstance()) never serves a stale default.
        if (array_key_exists($modelClass, self::$pipelines)) {
            return self::$pipelines[$modelClass] ?: $this->default;
        }

        $pipeline = $this->resolvePipeline($modelClass);
        self::$pipelines[$modelClass] = $pipeline === $this->default ? false : $pipeline;

        return $pipeline;
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
     * Tokenize + stem search input — used by SearchBuilder (BM25 query terms, asYouType) and the Scout engine.
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
     * Index a collection of models (one class) in a single transaction: RebuildCommand's and
     * RebuildIndexJob's per-chunk write. Saved models are reloaded inside it, one query for the
     * chunk (see write()); unsaved instances are indexed as given. The dictionary is upserted
     * with one chunked statement per distinct doc_count increment, not one per term, and the
     * postings and document rows in chunked upserts.
     *
     * @param  iterable<Model> $models
     * @return int the number of models indexed
     */
    public function indexBatch(iterable $models): int
    {
        $modelType = null;
        $keys      = [];
        $unsaved   = [];

        foreach ($models as $model) {
            $modelType ??= get_class($model);
            if ($model->exists) {
                $keys[] = $model->getKey();
            } else {
                $unsaved[(string) $model->getKey()] = $model;
            }
        }

        return $modelType === null ? 0 : $this->write($modelType, $keys, $unsaved);
    }

    /**
     * The one index write, in one transaction:
     *  1. claim the models' document rows (claimDocuments()), so a concurrent write for one of
     *     them waits for this one, or this one for it;
     *  2. reload the saved rows (reload()): the text indexed is the row as committed now, so
     *     whichever write commits last leaves the latest save indexed (ruling ER-68);
     *  3. delete their old postings, giving back doc_count (C12);
     *  4. write the dictionary, postings, document rows and meta (C11) of the rows with text.
     * A row that is gone, soft-deleted or left without text leaves the index instead.
     *
     * @param  list<int|string>     $keys    saved models, reloaded here
     * @param  array<string, Model> $unsaved key => instance, indexed as given
     * @return int the number of models indexed
     */
    private function write(string $modelType, array $keys, array $unsaved): int
    {
        return DB::transaction(function () use ($modelType, $keys, $unsaved) {
            $ids = array_map('strval', [...$keys, ...array_keys($unsaved)]);
            $old = $this->claimDocuments($modelType, $ids); // id => doc_length, for the indexed ones

            $byModel = []; // id => [column => [term => frequency]]
            foreach ($this->reload($modelType, $keys) + $unsaved as $id => $model) {
                if (!self::indexesModel($model)) {
                    continue;
                }
                $byColumn = $this->buildTokenFrequencyMap($model, $model->getSearchableColumns());
                if ($this->mergeColumnFrequencies($byColumn) !== []) {
                    $byModel[$id] = $byColumn;
                }
            }

            $this->deletePostings($modelType, $ids);

            // Rows leaving the index (gone, soft-deleted, no text left) lose their document row,
            // and rows never indexed lose the placeholder the claim inserted.
            $leaving = array_values(array_diff($ids, array_map('strval', array_keys($byModel))));
            foreach (array_chunk($leaving, 1000) as $chunk) {
                DB::table('fuzzy_index_documents')->where('model_type', $modelType)->whereIn('model_id', $chunk)->delete();
            }

            $lengths = [];
            $counts  = []; // term => number of documents holding it: its doc_count increment
            foreach ($byModel as $id => $byColumn) {
                $tokens       = $this->mergeColumnFrequencies($byColumn);
                $lengths[$id] = array_sum($tokens);
                foreach (array_keys($tokens) as $term) {
                    $counts[$term] = ($counts[$term] ?? 0) + 1;
                }
            }

            // PHP normalises numeric-string array keys (e.g. '10') to int keys, so a purely
            // numeric token comes back an int. Cast back to string wherever a key becomes a query
            // binding: SQL Server's MERGE ... USING (VALUES (...)) infers one type per column from
            // the batch of bindings, so a mixed int/string 'term' column fails with "Conversion
            // failed when converting the nvarchar value 'paginate' to data type int". $termIds[$term]
            // lookups still work because PHP normalises numeric-string keys the same way on read.
            $byIncrement = [];
            foreach ($counts as $term => $increment) {
                $byIncrement[$increment][] = (string) $term;
            }
            foreach ($byIncrement as $increment => $terms) {
                foreach (array_chunk($terms, self::ROWS_PER_UPSERT) as $chunk) {
                    DB::table('fuzzy_index_terms')->upsert(
                        array_map(fn ($term) => ['term' => $term, 'doc_count' => $increment, 'term_length' => mb_strlen($term)], $chunk),
                        ['term'],
                        // Table-qualified: PostgreSQL treats a bare "doc_count" as ambiguous inside
                        // ON CONFLICT DO UPDATE. The qualified form is valid on MySQL/MariaDB
                        // (ON DUPLICATE KEY UPDATE), SQLite, PostgreSQL and SQL Server (MERGE target).
                        ['doc_count' => DB::raw(DbDialect::rawIdentifier('fuzzy_index_terms.doc_count') . " + {$increment}")]
                    );
                }
            }

            $termIds = $this->termIds(array_map('strval', array_keys($counts)));

            // One posting per (term, column); a term missing from $termIds means a pre-migration
            // MySQL/MariaDB *_ci collation collapsed it into a variant (B25).
            $postingRows  = [];
            $documentRows = [];
            foreach ($byModel as $id => $byColumn) {
                foreach ($this->postingRows($byColumn, $termIds, $modelType, $id) as $row) {
                    $postingRows[] = $row;
                }
                $documentRows[] = ['model_type' => $modelType, 'model_id' => (string) $id, 'doc_length' => $lengths[$id]];
            }

            // Upserts, not inserts: a concurrent worker's row on the unique key updates instead of failing (C9).
            foreach (array_chunk($postingRows, self::POSTING_ROWS_PER_UPSERT) as $chunk) {
                DB::table('fuzzy_index_postings')->upsert($chunk, ['term_id', 'model_type', 'model_id', 'column_name'], ['frequency']);
            }
            foreach (array_chunk($documentRows, self::ROWS_PER_UPSERT) as $chunk) {
                DB::table('fuzzy_index_documents')->upsert($chunk, ['model_type', 'model_id'], ['doc_length']);
            }

            $this->adjustMeta(
                $modelType,
                count(array_diff_key($byModel, $old)) - count(array_diff_key($old, $byModel)),
                array_sum($lengths) - array_sum($old),
                ensure: $byModel !== [],
            );

            return count($byModel);
        });
    }

    /**
     * The saved rows among $keys as committed now, keyed by id, leaving out a soft-deleted row.
     * Loaded through IndexQuery, so a model's searchIndexQuery() eager loads (the relations a
     * searchableText() hook reads) apply to single-row writes as well as to rebuilds.
     *
     * @param  list<int|string>    $keys
     * @return array<string, Model>
     */
    private function reload(string $modelType, array $keys): array
    {
        $rows = [];
        foreach (array_chunk($keys, 1000) as $chunk) {
            foreach (IndexQuery::for($modelType)->whereKey($chunk)->get() as $model) {
                if (!(method_exists($model, 'trashed') && $model->trashed())) {
                    $rows[(string) $model->getKey()] = $model;
                }
            }
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * The texts to index for a model: the searchableText() hook when the model defines one
     * (any keys, related data allowed), otherwise the searchable columns' values — through
     * accessors for declared columns, as raw attributes for auto-detected ones (SearchableColumns::value()).
     *
     * A hook value may be a Collection or array (e.g. `$this->tags->pluck('name')`) — its
     * scalar items are joined with a space so callers don't have to implode() themselves.
     * Any other non-scalar (an object without __toString) is a hook bug, not silently
     * indexable text, so it throws rather than being coerced into a warning-laden string.
     *
     * @return array<string, string> name => non-empty text
     * @throws \InvalidArgumentException if a hook value — or a declared column's attribute — is a
     *         non-scalar, non-stringable object (the message names whichever it was). An
     *         auto-detected column's value is skipped instead: nobody asked for it to be indexed.
     */
    private function searchableTexts(Model $model, array $columns): array
    {
        $fromHook = method_exists($model, 'searchableText');
        // Auto-detection is a heuristic: a value it lands on may still not be text. Skip such a
        // value rather than break the model's save. A column the caller declared (or a hook
        // value) is their choice and still gets the throw.
        $declared = SearchableColumns::declared($model);
        $texts    = $fromHook
            ? (array) $model->searchableText()
            : array_combine($columns, array_map(fn ($c) => SearchableColumns::value($model, $c), $columns));

        $clean = [];
        foreach ($texts as $name => $value) {
            if ($value instanceof \Illuminate\Support\Collection) {
                $value = $value->all();
            }

            if (is_array($value)) {
                $value = implode(' ', array_map('strval', array_filter($value, 'is_scalar')));
            } elseif (is_object($value) && !method_exists($value, '__toString')) {
                if (!$fromHook && !$declared) {
                    continue;
                }

                throw new \InvalidArgumentException(
                    'fuzzy-search: ' . ($fromHook ? 'searchableText() value for' : 'searchable column') .
                    ' "' . $name . '" on ' . get_class($model) .
                    ' is a ' . get_class($value) . ', which cannot be indexed as text. ' .
                    ($fromHook
                        ? 'Return a string, scalar, array, or Collection of scalars instead.'
                        : "Cast it to a string, expose it through searchableText(), or drop it from \$searchable['columns'].")
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
                if (DbDialect::varcharLength($stemmed) > 255) {
                    // Capped at 191 characters, but SQL Server's nvarchar(255) counts a character
                    // outside the BMP as two: skip rather than truncate silently.
                    continue;
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

    /**
     * Subtract term_id => count from doc_count, floored at 0 (unsigned column, concurrent
     * deletes): one UPDATE per 1,000 terms instead of a round-trip each. Ids and counts are
     * inlined as ints; they come from the database, never from user input.
     *
     * @param array<int, int|string> $counts
     */
    private function decrementDocCounts(array $counts): void
    {
        foreach (array_chunk($counts, 1000, true) as $chunk) {
            $cases = '';
            foreach ($chunk as $termId => $cnt) {
                $termId = (int) $termId;
                $cnt    = (int) $cnt;
                $cases .= " WHEN {$termId} THEN CASE WHEN doc_count >= {$cnt} THEN doc_count - {$cnt} ELSE 0 END";
            }
            $inList = implode(',', array_map('intval', array_keys($chunk)));
            DB::statement(
                'UPDATE ' . DbDialect::rawIdentifier('fuzzy_index_terms') . " SET doc_count = CASE id{$cases} ELSE doc_count END WHERE id IN ({$inList})"
            );
        }
    }

    /**
     * term => id for $terms, 1,000 bindings a query.
     *
     * @param  string[] $terms
     * @return array<string, int>
     */
    private function termIds(array $terms): array
    {
        $ids = [];
        foreach (array_chunk($terms, 1000) as $chunk) {
            $ids += DB::table('fuzzy_index_terms')->whereIn('term', $chunk)->pluck('id', 'term')->all();
        }

        return $ids;
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
                    'model_id'    => (string) $modelId,
                    'column_name' => (string) $column,
                    'frequency'   => $frequency,
                ];
            }
        }
        return $rows;
    }

    /**
     * Add $docs and $tokens (either may be negative, floored at 0) to the model type's meta row
     * and recompute avg_doc_length from the now-consistent totals (C11), inside the enclosing
     * transaction, so no BM25 read sees the totals and the average disagree. $ensure creates the
     * row first (race-safe, C10); a removal only updates a row that exists.
     */
    private function adjustMeta(string $modelType, int $docs, int $tokens, bool $ensure): void
    {
        if ($ensure) {
            $this->ensureMetaRow($modelType);
        }

        if ($docs === 0 && $tokens === 0) {
            return;
        }

        $add     = fn (string $column, int $delta) => DB::raw($delta >= 0
            ? "{$column} + {$delta}"
            : "CASE WHEN {$column} >= " . -$delta . " THEN {$column} - " . -$delta . ' ELSE 0 END');
        $updates = [];
        if ($docs !== 0) {
            $updates['total_docs'] = $add('total_docs', $docs);
        }
        if ($tokens !== 0) {
            $updates['total_tokens'] = $add('total_tokens', $tokens);
        }

        DB::table('fuzzy_index_meta')->where('model_type', $modelType)->update($updates);
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
