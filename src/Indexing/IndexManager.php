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

    /**
     * Tries per index write. Laravel retries a top-level transaction that fails on a deadlock
     * or lock timeout (MySQL 1213/1205, PostgreSQL 40P01, SQL Server 1205, SQLite busy), and a
     * write is safe to repeat: it claims, re-reads and rewrites the rows from scratch (ER-71).
     */
    private const ATTEMPTS = 3;

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
            $old    = $this->claimDocuments($modelType, [$modelId]);
            $posted = $this->postedTerms($modelType, [$modelId]);

            $this->adjustDocCounts(array_map(fn (array $term) => -$term[1], array_column($posted, null, 0)));
            if ($posted !== []) { // see write()
                $this->deletePostings($modelType, [$modelId]);
            }

            // The document row, or the placeholder the claim just inserted.
            DB::table('fuzzy_index_documents')
                ->where('model_type', $modelType)
                ->where('model_id', $modelId)
                ->delete();

            $this->adjustMeta($modelType, -count($old), -array_sum($old), ensure: false);
        }, self::ATTEMPTS);
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
     * The dictionary terms the models' postings hold: term => [term id, how many of the models
     * hold it], COUNT(DISTINCT model_id) since a document has one posting per column a term is in.
     *
     * @param  array<int|string>              $modelIds
     * @return array<string, array{int, int}>
     */
    private function postedTerms(string $modelType, array $modelIds): array
    {
        $posted = [];
        foreach (array_chunk(array_map('strval', $modelIds), 1000) as $chunk) {
            $rows = DB::table('fuzzy_index_postings as p')
                ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
                ->where('p.model_type', $modelType)
                ->whereIn('p.model_id', $chunk)
                ->groupBy('t.id', 't.term')
                ->select('t.id', 't.term')
                ->selectRaw('COUNT(DISTINCT ' . DbDialect::rawIdentifier('p.model_id') . ') as cnt')
                ->get();

            foreach ($rows as $row) {
                $posted[(string) $row->term] = [(int) $row->id, ($posted[(string) $row->term][1] ?? 0) + (int) $row->cnt];
            }
        }

        return $posted;
    }

    /**
     * Delete the models' postings. Their doc_count share goes back through adjustDocCounts().
     *
     * @param array<int|string> $modelIds
     */
    private function deletePostings(string $modelType, array $modelIds): void
    {
        foreach (array_chunk(array_map('strval', $modelIds), 1000) as $chunk) {
            DB::table('fuzzy_index_postings')
                ->where('model_type', $modelType)
                ->whereIn('model_id', $chunk)
                ->delete();
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
                ->chunkById(1000, fn ($rows) => $this->adjustDocCounts($rows->pluck('cnt', 'term_id')->map(fn ($cnt) => -(int) $cnt)->all()), 'term_id');

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
     * chunk (see write()); unsaved instances are indexed as given. New dictionary terms go out
     * in chunked upserts, not one per term (on MySQL/MariaDB one per distinct doc_count
     * increment), and so do the postings and document rows.
     *
     * $scout: the Scout engine's update() (ER-72). The rows are re-read as Scout's own jobs read
     * them, without global scopes, and a trashed row stays indexed while scout.soft_delete is on,
     * so Scout's onlyTrashed() and withTrashed() find it. Everything else reads through the
     * model's query: its global scopes, and SoftDeletes, whose trashed rows leave the index.
     *
     * @param  iterable<Model> $models
     * @return int the number of models indexed
     */
    public function indexBatch(iterable $models, bool $scout = false): int
    {
        $modelType = null;
        $keys      = [];
        $unsaved   = [];

        foreach ($models as $model) {
            $modelType ??= get_class($model);
            if ($model->exists) {
                $keys[(string) $model->getKey()] = $model->getKey(); // once: an upsert may touch a row once
            } else {
                $unsaved[(string) $model->getKey()] = $model;
            }
        }

        return $modelType === null ? 0 : $this->write($modelType, array_values($keys), $unsaved, $scout);
    }

    /**
     * The one index write, in one transaction:
     *  1. claim the models' document rows (claimDocuments()), so a concurrent write for one of
     *     them waits for this one, or this one for it;
     *  2. reload the saved rows (reload()): the text indexed is the row as committed now, so
     *     whichever write commits last leaves the latest save indexed (ruling ER-68);
     *  3. change the doc_count of every term the dictionary holds in one statement per 1,000
     *     terms, in id order (giving back the old terms, C12; raising the new ones), and delete
     *     the old postings;
     *  4. insert the terms the dictionary lacks, sorted, then the postings, document rows and
     *     meta (C11) of the rows with text.
     * Nothing carries over between attempts, so a deadlock retry (ATTEMPTS) redoes it whole.
     * A row that is gone, soft-deleted or left without text leaves the index instead.
     *
     * @param  list<int|string>     $keys    saved models, reloaded here
     * @param  array<string, Model> $unsaved key => instance, indexed as given
     * @param  bool                 $scout   re-read with Scout's visibility (see indexBatch())
     * @return int the number of models indexed
     */
    private function write(string $modelType, array $keys, array $unsaved, bool $scout = false): int
    {
        return DB::transaction(function () use ($modelType, $keys, $unsaved, $scout) {
            $ids = array_map('strval', [...$keys, ...array_keys($unsaved)]);
            $old = $this->claimDocuments($modelType, $ids); // id => doc_length, for the indexed ones

            $byModel = []; // id => [column => [term => frequency]]
            foreach ($this->reload($modelType, $keys, $scout) + $unsaved as $id => $model) {
                if (!self::indexesModel($model)) {
                    continue;
                }
                $byColumn = $this->buildTokenFrequencyMap($model, $model->getSearchableColumns());
                if ($this->mergeColumnFrequencies($byColumn) !== []) {
                    $byModel[$id] = $byColumn;
                }
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

            // Every doc_count change to a term the dictionary already holds (the old terms given
            // back, the new ones raised) goes out as one UPDATE per 1,000 terms in id order, so a
            // write takes its locks on existing terms in the order every other write takes them
            // (ER-71): giving back old terms, then raising new ones in a second statement, let two
            // writes whose terms cross each hold a row the other needed.
            $posted   = $this->postedTerms($modelType, $ids);
            $existing = $this->termIds(array_map('strval', array_keys($counts)));
            $deltas   = [];
            foreach ($posted as [$termId, $holders]) {
                $deltas[$termId] = -$holders;
            }
            foreach ($counts as $term => $increment) {
                if (isset($existing[$term])) {
                    $deltas[$existing[$term]] = ($deltas[$existing[$term]] ?? 0) + $increment;
                }
            }
            // A word the locking read no longer finds was swept since the read above: a flush of
            // any model deletes every word no posting holds, which a word this write reuses after
            // re-indexing dropped it can be. It is inserted again below, as a missing one (ER-75).
            $gone     = array_flip($this->adjustDocCounts($deltas));
            $existing = array_filter($existing, fn ($id) => !isset($gone[$id]));

            // No DELETE when nothing is posted: on MySQL/MariaDB one that matches nothing still
            // gap-locks the end of postings_model_idx, where another first index's postings
            // insert then waits while that write waits on a new word this one inserted first: a
            // deadlock on every pair of first indexes sharing a new word. $posted is every
            // posting of these rows (the term foreign key cascades), and under the claim only a
            // flush (ER-69) can change them.
            if ($posted !== []) {
                $this->deletePostings($modelType, $ids);
            }

            // Rows leaving the index (gone, soft-deleted, no text left) lose their document row,
            // and rows never indexed lose the placeholder the claim inserted.
            $leaving = array_values(array_diff($ids, array_map('strval', array_keys($byModel))));
            foreach (array_chunk($leaving, 1000) as $chunk) {
                DB::table('fuzzy_index_documents')->where('model_type', $modelType)->whereIn('model_id', $chunk)->delete();
            }

            // PHP normalises numeric-string array keys (e.g. '10') to int keys, so a purely
            // numeric token comes back an int. Cast back to string wherever a key becomes a query
            // binding: SQL Server's MERGE ... USING (VALUES (...)) infers one type per column from
            // the batch of bindings, so a mixed int/string 'term' column fails with "Conversion
            // failed when converting the nvarchar value 'paginate' to data type int". $termIds[$term]
            // lookups still work because PHP normalises numeric-string keys the same way on read.
            //
            // Terms the dictionary lacks are inserted, sorted by term. A write that inserts one of
            // them first turns this insert into an update of its row: the conflict branch raises it
            // by this write's increment, the inserted row's own doc_count (EXCLUDED, or the MERGE
            // source). So every write takes its new words in one sorted order (ER-74); a
            // statement per increment, each sorted only within itself, let two batches take them
            // in opposite orders. MySQL/MariaDB keep a statement per increment with a literal raise
            // (VALUES() there is deprecated): InnoDB's duplicate-key gap locks cycle in any order.
            $missing = [];
            foreach ($counts as $term => $increment) {
                if (!isset($existing[$term])) {
                    $missing[(string) $term] = $increment;
                }
            }
            ksort($missing, SORT_STRING);

            $driver = DB::connection()->getDriverName();
            $source = match (true) { // the inserted row's doc_count, as the conflict branch sees it
                DbDialect::isMySqlFamily($driver) => null,
                $driver === 'sqlsrv'              => DbDialect::rawIdentifier('laravel_source.doc_count'), // Laravel's MERGE source alias
                default                           => 'excluded.doc_count',
            };
            $runs = []; // raise => term => increment
            foreach ($missing as $term => $increment) {
                $runs[$source ?? (string) $increment][$term] = $increment;
            }
            foreach ($runs as $raise => $terms) {
                foreach (array_chunk($terms, self::ROWS_PER_UPSERT, true) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $term => $increment) {
                        $rows[] = ['term' => (string) $term, 'doc_count' => $increment, 'term_length' => mb_strlen((string) $term)];
                    }
                    DB::table('fuzzy_index_terms')->upsert(
                        $rows,
                        ['term'],
                        // Table-qualified: PostgreSQL treats a bare "doc_count" as ambiguous inside
                        // ON CONFLICT DO UPDATE. The qualified form is valid on MySQL/MariaDB
                        // (ON DUPLICATE KEY UPDATE), SQLite, PostgreSQL and SQL Server (MERGE target).
                        ['doc_count' => DB::raw(DbDialect::rawIdentifier('fuzzy_index_terms.doc_count') . " + {$raise}")]
                    );
                }
            }

            // After a sweep, a current read: MySQL's snapshot still shows a swept word's old row
            // beside the one just inserted (ER-75). This write holds every one of these rows, so
            // it waits on none.
            $termIds = $existing + $this->termIds(array_map('strval', array_keys($missing)), current: $gone !== []);

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
        }, self::ATTEMPTS);
    }

    /**
     * The saved rows among $keys as committed now, keyed by id. Loaded through IndexQuery, so a
     * model's searchIndexQuery() eager loads (the relations a searchableText() hook reads) apply
     * to single-row writes as well as to rebuilds. The model's global scopes apply and a trashed
     * row is left out, unless $scout: then no global scope applies, as in Scout's own jobs, and
     * a trashed row is kept while scout.soft_delete is on (ER-72).
     *
     * @param  list<int|string>    $keys
     * @return array<string, Model>
     */
    private function reload(string $modelType, array $keys, bool $scout): array
    {
        $keepTrashed = $scout && config('scout.soft_delete', false);
        $rows        = [];

        foreach (array_chunk($keys, 1000) as $chunk) {
            $query = IndexQuery::for($modelType);
            if ($scout) {
                $query->withoutGlobalScopes();
            }

            foreach ($query->whereKey($chunk)->get() as $model) {
                if ($keepTrashed || !(method_exists($model, 'trashed') && $model->trashed())) {
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
     * Add term_id => delta (either sign; a result below 0 is floored at 0: unsigned column,
     * concurrent deletes) to doc_count: one UPDATE per 1,000 terms, after a locking read that takes
     * the rows in ascending id order, so every write locks the dictionary rows it touches in the
     * same order (ER-71). The UPDATE alone would lock them in scan order, which on PostgreSQL is
     * the rows' physical order and moves with every update. A delta of 0 still locks its row: a
     * posting inserted later checks its term row (MySQL's foreign-key S lock), and must find it
     * locked already rather than wait for it behind another write.
     * Ids and deltas are inlined as ints; they come from the database and the tokenizer.
     *
     * @param  array<int, int> $deltas
     * @return list<int> the ids the locking read did not find: terms deleted since the caller
     *                   read them, by a flush's orphan sweep (ER-75)
     */
    private function adjustDocCounts(array $deltas): array
    {
        ksort($deltas);
        $gone = [];

        foreach (array_chunk($deltas, 1000, true) as $chunk) {
            $found = DB::table('fuzzy_index_terms')->whereIn('id', array_keys($chunk))->orderBy('id')->lockForUpdate()->pluck('id')->all();
            $gone  = [...$gone, ...array_values(array_diff(array_keys($chunk), $found))];
            $chunk = array_intersect_key($chunk, array_flip($found));
            if ($chunk === []) {
                continue;
            }

            $cases = '';
            foreach ($chunk as $termId => $delta) {
                $termId = (int) $termId;
                $delta  = (int) $delta;
                $cases .= $delta >= 0
                    ? " WHEN {$termId} THEN doc_count + {$delta}"
                    : " WHEN {$termId} THEN CASE WHEN doc_count >= " . -$delta . ' THEN doc_count - ' . -$delta . ' ELSE 0 END';
            }
            $inList = implode(',', array_map('intval', array_keys($chunk)));
            DB::statement(
                'UPDATE ' . DbDialect::rawIdentifier('fuzzy_index_terms') . " SET doc_count = CASE id{$cases} ELSE doc_count END WHERE id IN ({$inList})"
            );
        }

        return $gone;
    }

    /**
     * term => id for $terms, 1,000 bindings a query. $current reads the rows as they are now,
     * with a locking read, rather than from MySQL/MariaDB's REPEATABLE READ snapshot.
     *
     * @param  string[] $terms
     * @return array<string, int>
     */
    private function termIds(array $terms, bool $current = false): array
    {
        $ids = [];
        foreach (array_chunk($terms, 1000) as $chunk) {
            $ids += DB::table('fuzzy_index_terms')->whereIn('term', $chunk)->when($current, fn ($q) => $q->lockForUpdate())->pluck('id', 'term')->all();
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
