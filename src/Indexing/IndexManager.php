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
     * six values (1,800 a statement), a model's word row five (1,500), a dictionary or document
     * row three (1,500).
     */
    private const POSTING_ROWS_PER_UPSERT = 300;
    private const ROWS_PER_UPSERT         = 500;

    /**
     * Tries per index write. Laravel retries a top-level transaction that fails on a deadlock
     * or lock timeout (MySQL 1213/1205, PostgreSQL 40P01, SQL Server 1205, SQLite busy), and a
     * write is safe to repeat: it claims, re-reads and rewrites the rows from scratch.
     */
    private const ATTEMPTS = 3;

    /**
     * Rows a MySQL/MariaDB flush deletes per statement, each in its own transaction, where it does
     * not truncate (deleteInBatches()). One DELETE of a large index's postings, 207M rows at 12M
     * documents, ran 52 minutes in one transaction, and the rebuild after it crawled behind the
     * purge of those rows. At the 65,000 rows a second that DELETE ran, 20,000 rows take about 0.3
     * s: a write of another model waits at most that for a batch's locks, a batch's undo is a few
     * MB, which purge clears as the batches go, and a commit's log flush is under 1% of a batch.
     */
    private const FLUSH_ROWS = 20_000;

    /** The truncate's tries on MySQL/MariaDB (truncateWhenFree()), and the pause between them in microseconds. */
    private const TRUNCATE_TRIES = 3;
    private const TRUNCATE_PAUSE = 2_000_000;

    // Every model id is bound as a string: model_id is a varchar, and an integer bound against it
    // makes MySQL/MariaDB compare numerically and SQL Server convert the column, so neither can
    // seek the key. The claim's FOR UPDATE then scans, and locks, every document row of the
    // model type: writes for different rows waited on each other and deadlocked (1213).

    private Pipeline $default;

    /** FLUSH_ROWS; the tests set it lower to see several batches. */
    private int $flushRows = self::FLUSH_ROWS;

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
     * The model_type $model's rows are indexed and searched under: its searchIndexType() (the
     * Searchable trait's, which a single-table-inheritance child overrides to name its parent, so
     * the hierarchy has one index), else its class, as for a Scout model without the trait. A class
     * name resolves the same way; one that no longer exists is its own type. Every index write,
     * delete, flush and search names the type through here, so a child's save and a rebuild of its
     * parent cannot write two indexes.
     *
     * The type is the class itself or a concrete Eloquent model it extends that reads the same table
     * on the same connection, else a LogicException names the hook: a write re-reads
     * the rows through the type's query and a search reads the type's postings, so a sibling's scope
     * left the model unindexed, an unrelated model's index was served, an abstract base or a morph
     * alias could not be read at all, and an ancestor on another table indexed its own rows in the
     * model's place. An ancestor resolves to its own type, so a grandchild whose hook names its
     * parent lands in the index the parent's rows are in, under the declared name however a
     * hook spells it.
     */
    public static function indexType(Model|string $model): string
    {
        $class = is_string($model) ? $model : $model::class;
        if (!method_exists($model, 'searchIndexType')) {
            return $class;
        }

        $type = $model::searchIndexType();
        if ($type === $class) {
            return $type;
        }

        // Memoized: a rebuild resolves every row's type, and the check builds two models.
        return self::$ancestorTypes[$class . "\0" . $type] ??= self::ancestorType($class, $type);
    }

    /** @var array<string, string> "class\0hook's value" => index type */
    private static array $ancestorTypes = [];

    private static function ancestorType(string $class, string $type): string
    {
        $parent = class_exists($type) ? new \ReflectionClass($type) : null;
        if ($parent === null || $parent->isAbstract() || !$parent->isSubclassOf(Model::class) || !is_a($class, $parent->getName(), true)
            || !self::sameTable($class, $parent->getName())) {
            $class = ltrim($class, '\\');

            throw new \LogicException("{$class}::searchIndexType() returned [{$type}], which is neither {$class} nor a concrete Eloquent model it extends on the same table and connection: return the class itself, or the single-table-inheritance parent whose index it shares.");
        }

        return self::indexType($parent->getName());
    }

    /** Whether the two model classes read the same table on the same connection (memoized: each builds a model). */
    private static function sameTable(string $class, string $other): bool
    {
        return self::$sameTables[$class . "\0" . $other] ??= (function () use ($class, $other) {
            $make    = fn (string $c) => ($r = new \ReflectionClass($c))->isInstantiable() ? new $c() : $r->newInstanceWithoutConstructor();
            [$a, $b] = [$make($class), $make($other)];

            return $a->getTable() === $b->getTable()
                && ($a->getConnectionName() ?? config('database.default')) === ($b->getConnectionName() ?? config('database.default'));
        })();
    }

    /** @var array<string, bool> "class\0other class" => sameTable() */
    private static array $sameTables = [];

    /**
     * The class a row with $model's attributes (or $attributes) hydrates as: its kind, as the
     * hierarchy's parent reads it. A single-table-inheritance parent's newFromBuilder()
     * (tightenco/parental and the like) builds each row as its kind's class, whichever instance
     * holds it, and a write files a re-read row under that class's type, so a row is filed
     * by this class, not the instance's. Asked of a child instance, newFromBuilder() builds a row of
     * the parent's kind as the child (its `new static`). A class that does not override
     * newFromBuilder() hydrates every row as itself. Built without model events.
     */
    public static function rowClass(Model $model, ?array $attributes = null): string
    {
        if (!self::hydratesOtherClasses($model::class)) {
            return $model::class;
        }

        $parent = self::hierarchyRoot($model::class, concrete: true);

        return Model::withoutEvents(fn () => (new $parent())->newFromBuilder($attributes ?? $model->getAttributes(), $model->getConnectionName())::class);
    }

    /**
     * The index types a save or delete of $model writes: the type of the row's kind (rowClass())
     * now and before the save, so a type change files the row under its new kind and takes it out
     * of its old one, and the type of the instance's own class unless that is the hierarchy's
     * parent, a class whose own query can also read the row (a subclass that is no kind of it, or
     * the kind the row has just left). Each write re-reads the row through its type's query, so it
     * files the row there only if that query reads it as one of the type's own.
     * Without single-table inheritance, the instance's type alone.
     *
     * @return list<string>
     */
    public static function rowTypes(Model $model): array
    {
        if (!self::hydratesOtherClasses($model::class)) {
            return [self::indexType($model)];
        }

        $original = $model->getRawOriginal();
        $classes  = [self::rowClass($model), ...($original === [] ? [] : [self::rowClass($model, $original)])];
        if ($model::class !== self::hierarchyRoot($model::class, concrete: true)) {
            $classes[] = $model::class;
        }

        return array_values(array_unique(array_map(fn (string $class) => self::indexType($class), $classes)));
    }

    /** Whether $class's query can build a row as another class: it overrides newFromBuilder() (single-table inheritance). */
    private static function hydratesOtherClasses(string $class): bool
    {
        return self::$hydrates[$class] ??= (new \ReflectionMethod($class, 'newFromBuilder'))->getDeclaringClass()->getName() !== Model::class;
    }

    /** @var array<string, bool> class => hydratesOtherClasses() */
    private static array $hydrates = [];

    /**
     * The topmost model class $class extends that dispatches the kinds (hydratesOtherClasses()) and
     * reads its table on its connection, or the topmost concrete one (memoized). A parent whose
     * newFromBuilder() is Eloquent's builds every row as itself: Laravel's default User extends
     * Illuminate\Foundation\Auth\User, concrete and on `users`, which read every row's kind as the
     * framework class, so a rebuild wrote each chunk under it and a save of a child row through
     * the parent filed it nowhere.
     */
    private static function hierarchyRoot(string $class, bool $concrete = false): string
    {
        $key = ($concrete ? 'concrete ' : '') . $class;
        if (!isset(self::$roots[$key])) {
            $root = $class;
            while (($parent = get_parent_class($root)) !== false && $parent !== Model::class && is_subclass_of($parent, Model::class)
                && self::hydratesOtherClasses($parent)
                && !($concrete && (new \ReflectionClass($parent))->isAbstract()) && self::sameTable($parent, $class)) {
                $root = $parent;
            }
            self::$roots[$key] = $root;
        }

        return self::$roots[$key];
    }

    /** @var array<string, string> class => hierarchyRoot() */
    private static array $roots = [];

    /**
     * The index types of $class's single-table-inheritance hierarchy that hold each of $keys: every
     * indexed type whose class extends the hierarchy's root (hierarchyRoot(): the topmost model $class
     * extends that dispatches the kinds on its table and connection), so the parent's, a sibling's
     * and a subclass's index alike. A key-only model (Scout's queued
     * delete) and a row re-read as its new kind cannot tell the type it was filed under before. A
     * class whose query hydrates every row as itself files them under its own type only: that type
     * is returned with every key, unread.
     *
     * @param  list<int|string> $keys
     * @return array<string, list<int|string>> index type => the keys it holds
     */
    public function filedUnder(string $class, array $keys): array
    {
        if (!self::hydratesOtherClasses($class)) {
            return $keys === [] ? [] : [self::indexType($class) => $keys];
        }

        $types = self::hierarchyTypes($class);
        $filed = [];
        foreach ($types === [] ? [] : array_chunk(array_map('strval', $keys), 1000) as $chunk) {
            $rows = DB::table('fuzzy_index_documents')->useWritePdo()
                ->whereIn('model_type', $types)->whereIn('model_id', $chunk)
                ->get(['model_type', 'model_id']);
            foreach ($rows as $row) {
                $row = (object) $row; // an app-wide array fetch mode returns arrays
                $filed[$row->model_type][] = (string) $row->model_id;
            }
        }

        return $filed;
    }

    /** @return list<string> the indexed types of $class's hierarchy (see filedUnder()) */
    private static function hierarchyTypes(string $class): array
    {
        $root = self::hierarchyRoot($class);

        return DB::table('fuzzy_index_meta')->useWritePdo()->pluck('model_type')
            ->map(fn ($type) => (string) $type)
            ->filter(fn (string $type) => class_exists($type) && is_a($type, $root, true) && self::sameTable($type, $root))
            ->values()->all();
    }

    /**
     * Take the rows that are gone from the table out of every index type of $class's
     * single-table-inheritance hierarchy but $class's own: `rebuild --fresh` flushes that type
     * alone and indexes the rows its query reads, so a child row deleted without model events (a
     * query-builder delete()) stayed in the child's index after a fresh rebuild of the parent.
     * One keyed read of the table per 1,000 documents of each other type. A class whose
     * query hydrates every row as itself has no other type: nothing is read.
     */
    public function unfileGone(string $class): void
    {
        if (!self::hydratesOtherClasses($class)) {
            return;
        }

        $root = self::hierarchyRoot($class, concrete: true);
        $own  = self::indexType($class);
        foreach (self::hierarchyTypes($class) as $type) {
            if ($type === $own) {
                continue;
            }
            DB::table('fuzzy_index_documents')->useWritePdo()->where('model_type', $type)->select('model_id')
                ->chunkById(1000, function ($rows) use ($root, $type) {
                    $ids  = $rows->map(fn ($row) => (string) ((object) $row)->model_id)->all(); // an app-wide array fetch mode returns arrays
                    $live = Model::withoutEvents(fn () => (new $root())->newModelQuery()->useWritePdo()->whereKey($ids)->pluck((new $root())->getKeyName()));
                    $gone = array_values(array_diff($ids, $live->map(fn ($key) => (string) $key)->all()));
                    if ($gone !== []) {
                        $this->write($type, $gone, [], refile: false); // the type's re-read finds none: they leave
                    }
                }, 'model_id');
        }
    }

    /**
     * Take out of $class's index type every document of a row that is gone, trashed or left out by
     * the model's searchIndexQuery(), or, under single-table inheritance, is now of a kind another
     * type indexes (rowClass(): read through the hierarchy's newFromBuilder()); and out of
     * the other types of its hierarchy every row gone from the table (unfileGone()). Such a row,
     * changed without model events (a query-builder delete(), a reseed, a failed index write, a
     * changed key), left its document in the index, counted by total_docs, a paginator's total and
     * the federated counts until a `rebuild --fresh`. A plain rebuild runs this after its walk.
     *
     * A trashed row of a model on Scout's trait is kept while scout.soft_delete is on: Scout indexes
     * it, for its withTrashed() and onlyTrashed().
     *
     * The rows are read without global scopes: a row a scope hides is no row gone. A single-database
     * multi-tenant app rebuilds each tenant under its tenant scope, and a read through the scope took
     * every other tenant's documents out. Such a row keeps its document until its next save (whose
     * write reads through the scopes) or a `rebuild --fresh`; so does a row whose kind a global
     * scope alone tells apart, with no newFromBuilder() of its class's to read it.
     *
     * One keyed read of the table per 1,000 documents. The documents found gone go through write(),
     * a chunk in one transaction, whose re-read under its claim removes them, or indexes a row saved
     * meanwhile under the same key, so it can run while the app indexes.
     *
     * Given $chunks, it stops after that many chunks and sets $after to the model_id to go on after
     * (rebuild --async runs one PruneIndexJob per $chunks, each within its job timeout); null once
     * the last document was read, when unfileGone() runs.
     *
     * @param  string|null $after the model_id to go on after; null from the first
     * @return int the documents of $class's type found gone
     */
    public function prune(string $class, ?string &$after = null, ?int $chunks = null): int
    {
        $type   = self::indexType($class);
        $model  = new $type();
        $int    = in_array($model->getKeyType(), ['int', 'integer'], true);
        $kinds  = self::hydratesOtherClasses($type);
        // A trashed row is gone, but on a model on Scout's trait under scout.soft_delete: Scout keeps
        // it indexed, for withTrashed().
        $trashedGone = method_exists($model, 'getQualifiedDeletedAtColumn')
            && !(config('scout.soft_delete', false) && in_array(\Laravel\Scout\Searchable::class, class_uses_recursive($type), true));
        $pruned = 0;
        $read   = 0;
        $last   = null;

        $complete = DB::table('fuzzy_index_documents')->useWritePdo()->where('model_type', $type)->select('model_id')
            ->when($after !== null, fn ($query) => $query->where('model_id', '>', $after))
            ->chunkById(1000, function ($rows) use ($type, $model, $int, $kinds, $trashedGone, $chunks, &$pruned, &$read, &$last) {
                $ids  = $rows->map(fn ($row) => (string) ((object) $row)->model_id)->all(); // an app-wide array fetch mode returns arrays
                // model_id holds strval() of the key: one no integer key gives names no row.
                $keys = $int ? array_values(array_filter($ids, fn (string $id) => (string) (int) $id === $id)) : $ids;
                // searchIndexQuery() applied with the scopes registered (it may call withTrashed() or
                // withoutGlobalScope()), then every global scope dropped; trashed rows left out by hand.
                $query = fn () => IndexQuery::for($type)->withoutGlobalScopes()->useWritePdo()->setEagerLoads([])
                    ->when($trashedGone, fn ($query) => $query->whereNull($model->getQualifiedDeletedAtColumn()))
                    ->whereKey($keys);
                $live = $keys === [] ? collect() : Model::withoutEvents(fn () => $kinds
                    ? $query()->get()->filter(fn (Model $row) => self::indexType(self::rowClass($row)) === $type)->map(fn (Model $row) => $row->getKey())
                    : $query()->pluck($model->getQualifiedKeyName()));
                $gone = array_values(array_diff($ids, $live->map(fn ($key) => (string) $key)->all()));
                if ($gone !== []) {
                    $this->write($type, $gone, [], refile: false);
                    $pruned += count($gone);
                }

                $last = end($ids);

                // A short chunk is the last: chunkById() ends there, and the read is complete.
                return $chunks === null || ++$read < $chunks || count($ids) < 1000;
            }, 'model_id');

        $after = $complete ? null : $last;
        if ($complete) {
            $this->unfileGone($class);
        }

        return $pruned;
    }

    /**
     * After indexBatch() filed each row under its kind's type, re-sync it under every other type
     * that may hold it: each type of its hierarchy that does (filedUnder()), and the instance's own
     * class's type unless that is the hierarchy's parent (see rowTypes()). The write re-reads the row
     * through that type's query, with its global scopes, so it leaves the type unless that query
     * reads it as one of the type's own: a kind it has left drops it, a subclass that is no kind
     * (whose own query reads the row) keeps it. A type change through Scout's update, whose queued
     * job re-reads the row as its new kind, or one made outside the app that a rebuild catches up,
     * left the row filed under its old kind too.
     *
     * @param array<string, array<int|string, string>> $filed instance class => key => the type it is filed under
     */
    private function unfileElsewhere(array $filed): void
    {
        foreach ($filed as $class => $current) {
            if (!self::hydratesOtherClasses($class)) {
                continue;
            }

            $byType = $this->filedUnder($class, array_keys($current));
            if ($class !== self::hierarchyRoot($class, concrete: true)) {
                $own          = self::indexType($class);
                $byType[$own] = array_values(array_unique([...($byType[$own] ?? []), ...array_map('strval', array_keys($current))]));
            }

            foreach ($byType as $type => $keys) {
                $stale = array_values(array_filter($keys, fn ($key) => ($current[$key] ?? null) !== $type));
                if ($stale !== []) {
                    $this->write($type, $stale, [], refile: false);
                }
            }
        }
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
        $this->removeManyFromIndex($modelType, [$modelId]);
    }

    /**
     * Remove the index entries of several models of one type, ROWS_PER_UPSERT of them to a
     * transaction: one claim, one postings read, one pass over the dictionary, the postings, the
     * documents and the meta counts for the chunk, as write() has, where a removal per model took
     * a transaction and a dozen statements each. A key listed twice, or never indexed, is
     * removed once, or not at all.
     *
     * @param array<int|string> $modelIds
     */
    public function removeManyFromIndex(string $modelType, array $modelIds): void
    {
        // Bound as strings: see the note at the top of the class.
        $modelIds = array_values(array_unique(array_map('strval', $modelIds)));

        foreach (array_chunk($modelIds, self::ROWS_PER_UPSERT) as $chunk) {
            DB::transaction(function () use ($modelType, $chunk) {
                // Waits for a write to these rows in flight; empty = none was indexed.
                $old     = $this->claimDocuments($modelType, $chunk);
                $typeId  = self::modelTypeId($modelType);
                $current = $this->currentPostings($typeId, $chunk, $old);
                $posted  = self::holders($current);

                $words = $this->adjustDocCounts(array_map(fn (int $holders) => -$holders, $posted));
                if ($posted !== []) {
                    $this->adjustModelTerms($typeId, self::termDeltas($posted, [], [], $words));
                    $this->deletePostings($typeId, $current);
                }

                // The document rows, or the placeholders the claim just inserted.
                DB::table('fuzzy_index_documents')
                    ->where('model_type', $modelType)
                    ->whereIn('model_id', $chunk)
                    ->delete();

                $this->adjustMeta($modelType, -count($old), -array_sum($old), ensure: false);
            }, self::ATTEMPTS);
        }
    }

    /**
     * SQL Server: take the index's write lock, an application lock owned by the enclosing
     * transaction, so its index writes run one at a time. Called first in every
     * index write's transaction (claimDocuments(), which write() and removeFromIndex() start
     * with), before any other lock: the claim, the dictionary rows and the model types' and
     * columns' inserts all come after it; every flush takes it first too (flushing()).
     * Parallel writers deadlocked there until they lost all
     * their attempts: the claim's MERGE takes HOLDLOCK range locks on the varchar model_id key,
     * whose string order interleaves id ranges ('1000' < '10000' < '1001'); the dictionary's
     * locking read took a range lock past the newest word, which every other writer's new word
     * waited on; the doc_count UPDATE and the dictionary MERGE crossed; fuzzy_index_terms
     * escalated to a table lock. Three immediate retries could not break such a sustained cycle,
     * so rebuild workers died and left the index partial.
     *
     * One lock per index (database and table prefix), not per model type: every model type
     * shares fuzzy_index_terms. The commit or rollback releases it, so each of write()'s
     * attempts takes it again; inside a transaction of yours it is held until yours ends. The
     * wait is the connection's LOCK_TIMEOUT, as for every other lock the write takes: none by
     * default. A lock not granted throws, naming the lock and sp_getapplock's code.
     *
     * Since no other write can insert or change an index row while it is held, a write under it
     * sends no MERGE: it inserts the rows it adds (insertMissing() where a row may be there
     * already, a plain INSERT where the write knows it is not) and updates the ones it changes by
     * key (updateByKey()). The MERGEs (claim, words, model terms, postings, documents, meta) held a
     * rebuild past 1M rows to about 120 documents a second.
     *
     * ponytail: one writer per index on SQL Server, the whole write transaction (the claim, the
     * re-read, tokenizing and the SQL) at a time; only what runs before it, such as a rebuild's
     * chunk load, stays parallel. To lift it, the writes need upserts that tolerate another
     * writer again (MERGE), and the cycles fixed one by one: no HOLDLOCK on adjustDocCounts()'
     * locking read, MERGE hints of our own for the claim, LOCK_ESCALATION off on
     * fuzzy_index_terms, and the doc_count UPDATE in the order the dictionary MERGE locks.
     *
     * @internal public for 2026_10_13_000002, whose backfill ranges take it too (V1-2)
     */
    public function lockIndexForWrite(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== DbDialect::SQLSRV) {
            return;
        }

        $resource = 'fuzzy-search:write:' . $connection->getDatabaseName() . ':' . $connection->getTablePrefix();
        if (DbDialect::varcharLength($resource) > 255) { // sp_getapplock's limit
            $resource = 'fuzzy-search:write:' . sha1($connection->getDatabaseName() . ':' . $connection->getTablePrefix());
        }

        $result = (int) $connection->scalar(
            "DECLARE @result int; EXEC @result = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = @@LOCK_TIMEOUT; SELECT @result AS result",
            [$resource],
            false
        );

        if ($result < 0) {
            // -3's words are those of SQL Server's own deadlock error, so Laravel handles it as
            // one: retried at the top level, thrown as a DeadlockException in a transaction of yours.
            $reason = [-1 => 'the wait timed out', -2 => 'the request was cancelled', -3 => 'the request has been chosen as the deadlock victim'][$result] ?? 'a parameter or call error';
            throw new \RuntimeException(sprintf('fuzzy-search: the index write lock [%s] was not granted: sp_getapplock returned %d (%s).', $resource, $result, $reason));
        }
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
     * On SQL Server the index's write lock comes first (lockIndexForWrite()), so no other write
     * claims a row meanwhile: the missing placeholders are inserted (insertMissing()), no MERGE.
     *
     * @param  array<int|string>  $modelIds
     * @return array<int|string, int> model id => doc_length, for the models already indexed
     */
    private function claimDocuments(string $modelType, array $modelIds): array
    {
        $this->lockIndexForWrite();

        $modelIds = array_map('strval', $modelIds);
        sort($modelIds, SORT_STRING);
        $indexed = [];
        $driver  = DB::connection()->getDriverName();

        foreach (array_chunk($modelIds, self::ROWS_PER_UPSERT) as $chunk) {
            if ($driver === DbDialect::SQLSRV) {
                self::insertMissing('fuzzy_index_documents', ['model_type', 'model_id', 'doc_length'], array_map(fn ($id) => [$modelType, $id, 0], $chunk), ['model_type', 'model_id']);
            } else {
                DB::table('fuzzy_index_documents')->upsert(
                    array_map(fn ($id) => ['model_type' => $modelType, 'model_id' => $id, 'doc_length' => 0], $chunk),
                    ['model_type', 'model_id'],
                    ['model_type'] // a no-op update: the conflict branch only takes the row lock
                );
            }

            $claim = DB::table('fuzzy_index_documents')->where('model_type', $modelType);

            if (count($chunk) > 1 && DbDialect::isMySqlFamily($driver)) {
                // MySQL/MariaDB: one point read per id, in the sorted order above, sent as one
                // UNION ALL. Each is a const plan, which locks its own row only, whatever the
                // table's statistics say. The IN list's plan followed them: while the table or
                // the model type had few rows, MySQL scanned the table, and MariaDB (from about 12
                // ids) the model type's key prefix, locking every row and gap they passed. So
                // parallel batches for different rows waited on each other's claims, and on
                // MariaDB deadlocked until they lost all their attempts. One statement:
                // a round trip per id added 30% to 300% to a 500-row write.
                $point    = '(' . $claim->select('model_id', 'doc_length')->where('model_id', '')->lockForUpdate()->toSql() . ')';
                $bindings = [];
                foreach ($chunk as $id) {
                    array_push($bindings, $modelType, $id); // the point read's two placeholders, in order
                }
                $rows = collect(DB::select(implode(' union all ', array_fill(0, count($chunk), $point)), $bindings, false))
                    ->pluck('doc_length', 'model_id');
            } else {
                // SQL Server: UPDLOCK holds the row locks to commit; the rows exist (the insert
                // just wrote the missing ones), so Laravel's HOLDLOCK only added key-range locks,
                // which reach the neighbouring keys: other writes' rows. FORCESEEK: for an IN list
                // on a small table SQL Server scanned the model type's rows instead, locking them
                // all. Either way parallel batches for different rows deadlocked.
                $rows = $claim
                    ->whereIn('model_id', $chunk)
                    ->lock($driver === 'sqlsrv' ? 'with(rowlock,updlock,forceseek)' : true)
                    ->pluck('doc_length', 'model_id');
            }

            foreach ($rows as $id => $length) {
                if ((int) $length > 0) {
                    $indexed[$id] = (int) $length;
                }
            }
        }

        return $indexed;
    }

    /**
     * The models' postings as they are now: model id => term id => column id => [frequency,
     * doc_length]. None for a model type without an id (modelTypeId()): nothing is posted under it.
     * The write's claim holds every other write to them off, so a plain read finds them as the last
     * write committed them, but for one case: on MySQL/MariaDB at REPEATABLE READ a plain read sees
     * the transaction's snapshot, which starts at its first plain read. In a transaction of its own
     * the write's first one comes after its claim. Inside a transaction of yours that read anything
     * before it indexed (User::find() then save(), Scout with after_commit off), the snapshot can
     * predate a write of the same row another process committed before the claim was granted: the
     * read found the words the row held before that write, so the counts were given back for those
     * and the ones that write added kept their doc_count for good. There the
     * read is checked against the rows as they are now (postingsCurrent()), and read again with FOR
     * UPDATE where it differs.
     *
     * The plain read is first because a locking one through postings_model_idx takes next-key locks
     * (the gaps beside the rows' entries, which a neighbouring row's new postings are inserted into)
     * before the write takes its dictionary rows: a write holding a gap and waiting for a word then
     * crosses one holding the word and inserting into the gap.
     *
     * @param  array<int|string>                                  $modelIds
     * @param  array<int|string, int>                             $old      the claim's: id => doc_length, for the indexed ones
     * @return array<int|string, array<int, array<int, array{int, int}>>>
     */
    private function currentPostings(?int $typeId, array $modelIds, array $old): array
    {
        $read = function (bool $lock) use ($typeId, $modelIds): array {
            $postings = [];
            foreach ($typeId === null ? [] : array_chunk(array_map('strval', $modelIds), 1000) as $chunk) {
                $rows = DB::table('fuzzy_index_postings')
                    ->where('model_type_id', $typeId)
                    ->whereIn('model_id', $chunk)
                    ->when($lock, fn ($query) => $query->lockForUpdate())
                    ->get(['model_id', 'term_id', 'column_id', 'frequency', 'doc_length']);

                foreach ($rows as $row) {
                    $row = (object) $row; // an app-wide array fetch mode returns arrays
                    $postings[(string) $row->model_id][(int) $row->term_id][(int) $row->column_id] = [(int) $row->frequency, (int) $row->doc_length];
                }
            }

            return $postings;
        };

        $postings = $read(false);
        if ($typeId !== null && DB::transactionLevel() > 1 && DbDialect::isMySqlFamily(DB::connection()->getDriverName())
            && !$this->postingsCurrent($typeId, $postings, $modelIds, $old)) {
            $postings = $read(true);
        }

        return $postings;
    }

    /**
     * MySQL/MariaDB: whether $postings, read from the transaction's snapshot, are the models'
     * postings now. Each is read again by its whole primary key with FOR UPDATE, one point read
     * per posting sent as one UNION ALL (as the claim reads its rows): a const plan, which locks its
     * own row only, a row this write deletes or rewrites anyway. The snapshot missed a write of
     * these rows when a posting is gone or holds another frequency or doc_length, or when a model
     * is indexed now ($old) and had no posting in it, or the reverse. A write that only added
     * postings raised the document's length, which every posting of it carries.
     *
     * @param array<int|string, array<int, array<int, array{int, int}>>> $postings see currentPostings()
     * @param array<int|string>                                          $modelIds
     * @param array<int|string, int>                                     $old
     */
    private function postingsCurrent(int $typeId, array $postings, array $modelIds, array $old): bool
    {
        foreach ($modelIds as $id) {
            if (isset($old[$id]) !== isset($postings[$id])) {
                return false;
            }
        }

        $points = [];
        foreach ($postings as $id => $terms) {
            foreach ($terms as $termId => $columns) {
                foreach ($columns as $columnId => $values) {
                    $points[] = [(int) $termId, (string) $id, (int) $columnId, $values];
                }
            }
        }

        $point = '(' . DB::table('fuzzy_index_postings')
            ->select('model_id', 'term_id', 'column_id', 'frequency', 'doc_length')
            ->where('term_id', 0)->where('model_type_id', $typeId)->where('model_id', '')->where('column_id', 0)
            ->lockForUpdate()->toSql() . ')';
        foreach (array_chunk($points, self::ROWS_PER_UPSERT) as $chunk) {
            $bindings = [];
            foreach ($chunk as [$termId, $id, $columnId]) {
                array_push($bindings, $termId, $typeId, $id, $columnId); // the point read's four placeholders, in order
            }

            $found = 0;
            foreach (DB::select(implode(' union all ', array_fill(0, count($chunk), $point)), $bindings, false) as $row) {
                $row = (object) $row;
                if (($postings[(string) $row->model_id][(int) $row->term_id][(int) $row->column_id] ?? null) !== [(int) $row->frequency, (int) $row->doc_length]) {
                    return false;
                }
                $found++;
            }
            if ($found !== count($chunk)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many of the models hold each word their postings hold: term id => models (a document has
     * one posting per column a word is in).
     *
     * @param  array<int|string, array<int, array<int, array{int, int}>>> $postings see currentPostings()
     * @return array<int, int>
     */
    private static function holders(array $postings): array
    {
        $holders = [];
        foreach ($postings as $terms) {
            foreach (array_keys($terms) as $termId) {
                $holders[$termId] = ($holders[$termId] ?? 0) + 1;
            }
        }

        return $holders;
    }

    /**
     * Delete postings by their whole primary key (term_id, model_type_id, model_id, column_id),
     * given as currentPostings() returns them. Their doc_count share goes back through
     * adjustDocCounts() and adjustModelTerms().
     *
     * Not by model: on MySQL/MariaDB at REPEATABLE READ a DELETE through postings_model_idx (or the
     * scan of the clustered key MySQL and MariaDB chose for it while the table was small) takes
     * next-key locks, the gaps beside the rows' entries included. A neighbouring row's write inserts
     * its new postings into such a gap, and its own DELETE held the gap that write's insert went
     * into: each write's insert waited on the other's, and one of two single writes in transactions
     * of yours was the deadlock victim. One point per posting locks that posting only, a
     * row only this write's claim reaches. MySQL/MariaDB are told to read the primary key
     * (FORCE INDEX): their plan otherwise followed postings_model_idx, or scanned. A statement holds
     * up to 1,000 postings, grouped by row and column (MySQL's range optimizer abandons a range of
     * more points than it can hold, for a scan), and up to 500 rows (SQL Server's 2,100 bindings).
     *
     * @param array<int|string, array<int, array<int, mixed>>> $postings model id => term id => column id => …
     */
    private function deletePostings(int $typeId, array $postings): void
    {
        $groups = []; // [model id, column id, term ids]
        foreach ($postings as $id => $terms) {
            $byColumn = [];
            foreach ($terms as $termId => $columns) {
                foreach (array_keys($columns) as $columnId) {
                    $byColumn[(int) $columnId][] = (int) $termId;
                }
            }
            foreach ($byColumn as $columnId => $termIds) {
                foreach (array_chunk($termIds, 1000) as $chunk) {
                    $groups[] = [(string) $id, $columnId, $chunk];
                }
            }
        }

        $table  = DbDialect::rawIdentifier('fuzzy_index_postings');
        $delete = DbDialect::isMySqlFamily(DB::connection()->getDriverName())
            ? "delete p from {$table} as p force index (primary)"
            : "delete from {$table}";
        $send   = function (array $statement) use ($typeId, $delete): void {
            $ids    = array_values(array_unique(array_column($statement, 0)));
            $points = implode(' or ', array_map(
                fn (array $group) => "(model_id = ? and column_id = {$group[1]} and term_id in (" . implode(',', $group[2]) . '))',
                $statement
            ));
            // The IN list of rows lets PostgreSQL, SQLite and SQL Server read postings_model_idx.
            DB::delete(
                "{$delete} where model_type_id = {$typeId} and model_id in (" . implode(',', array_fill(0, count($ids), '?')) . ") and ({$points})",
                [...$ids, ...array_column($statement, 0)]
            );
        };

        $statement = [];
        $size      = 0;
        foreach ($groups as $group) {
            if ($statement !== [] && ($size + count($group[2]) > 1000 || count($statement) === 500)) {
                $send($statement);
                [$statement, $size] = [[], 0];
            }
            $statement[] = $group;
            $size       += count($group[2]);
        }
        if ($statement !== []) {
            $send($statement);
        }
    }

    /**
     * Run a flush's DELETEs (or flushAll()'s TRUNCATE) in one transaction that holds every index
     * write off until it commits (flush() and flushAll()).
     *
     * PostgreSQL: lock first. A write for a new row waited on nothing the
     * DELETEs lock: it committed between them, so its document row stayed and its meta increment
     * went into the meta row deleted next, a model with documents and no meta (BM25 found nothing)
     * that no rebuild repaired. SHARE ROW EXCLUSIVE waits for the writes in flight and holds new
     * ones at their claim (claimDocuments()' insert) until the commit; searches only read, and a
     * second flush waits its turn. MySQL/MariaDB get the same from the DELETEs' gap locks, SQLite
     * from its one writer, each held to the commit: `clear --all` ran its DELETEs autocommitted,
     * one by one, and lost the meta the same way. flushAll() keeps it on every path it takes; the
     * batched DELETEs of MySQL/MariaDB outside a transaction of yours (clearInBatches(),
     * flushInBatches()) from their last transaction, which deletes what a write between the batches left.
     *
     * SQL Server: the index write lock first (lockIndexForWrite()), as every index write
     * takes it, so the flush waits for the writes in flight and new ones wait for its commit. Its
     * DELETEs ran against the writers' claims and dictionary locks without it: the cycles the write lock removes
     * removed between writers, with no retry on the flush's side.
     */
    private function flushing(\Closure $deletes): void
    {
        DB::transaction(function () use ($deletes) {
            $this->lockIndexForWrite();
            if (DB::connection()->getDriverName() === DbDialect::PGSQL) {
                DB::statement('LOCK TABLE ' . DbDialect::rawIdentifier('fuzzy_index_documents') . ' IN SHARE ROW EXCLUSIVE MODE');
            }

            $deletes();
        });
    }

    /**
     * Flush (delete) the index of every model: `fuzzy-search:clear --all`. The model types and
     * columns stay: their ids never change (see dictionaryIds()). Returns false when it deleted the
     * big tables row by row (the command says so: on a large index that is slow).
     *
     * The big tables are truncated where that takes no waiting: a DELETE per table in
     * one transaction logged every row, and on a large index filled the log (SQL Server: from 1 GB
     * to 5.4 GB in two minutes at 1M rows), the undo (MySQL) or the WAL (PostgreSQL). Atomic against
     * index writes on each path (see flushing()):
     * - PostgreSQL: in flushing()'s transaction, after its lock, in a savepoint, LOCK TABLE the five
     *   … NOWAIT, then one TRUNCATE of them (the model terms with them: their foreign key to the
     *   terms refuses a TRUNCATE of the terms alone, 0A000). Its ACCESS EXCLUSIVE holds index searches off to the
     *   commit too (they read the old rows during the DELETEs). No CASCADE: a table of the app's
     *   with a foreign key to one of them makes it fail and the DELETEs run, rather than emptying
     *   that table. No RESTART IDENTITY.
     * - SQL Server: after flushing()'s index write lock, TRUNCATE the postings, the documents and the
     *   model terms, which no foreign key references, in its transaction. Their schema locks hold searches off to the
     *   commit. A reader holds no lock there past its statement.
     * - MariaDB: TRUNCATE commits on its own, so no transaction: LOCK TABLES … WRITE on the five
     *   holds index writes and searches off from before the first TRUNCATE to UNLOCK TABLES, and a
     *   session may truncate a table it has write-locked: the postings, the documents and the model
     *   terms, then the terms, with foreign_key_checks off for that statement, unless a table of the
     *   app's references them. Laravel 10 connects to it as mysql.
     * - MySQL: as MariaDB, but its LOCK TABLES has no NOWAIT,
     *   so each try waits one second at most, and only while no other session holds one of the
     *   tables (truncateWhenFree()).
     * MySQL and MariaDB try 3 times, 2 s apart; where they still cannot truncate, they delete in
     * bounded transactions (clearInBatches()) rather than in one.
     * SQL Server, MySQL and MariaDB then delete the meta rows (one per model), and SQL Server the
     * terms, as MySQL/MariaDB do where a table of the app's references them: the DELETE is quick
     * once the postings are gone (about 2 s per 100,000 terms on MySQL). Every DELETE path
     * deletes the model terms before the terms, which would otherwise cascade into them row by
     * row. SQLite: DELETE without WHERE is its truncate.
     *
     * The exclusive table locks are taken with NOWAIT, never waited for: an app's
     * transaction that had read one index table (a search) held its shared lock to its end, the
     * clear waited for it holding the tables it had already locked, and the app's next statement on
     * one of those (a search, a sync IndexModelJob) closed the cycle. MySQL and MariaDB killed the
     * app's transaction (1213), its own rows with it; PostgreSQL the app's or the clear's (40P01,
     * which Laravel's savepoint handler turned into a DeadlockException past the fallback). Searches
     * hold their locks for a moment, so a refusal is tried again (withoutWaiting(), truncateWhenFree()).
     *
     * The DELETEs run instead inside a transaction of the caller's on MySQL and MariaDB (TRUNCATE and
     * LOCK TABLES would commit it), while the tables stay busy, and wherever TRUNCATE is refused: a
     * database user without the privilege it needs beyond DELETE (DROP, and LOCK TABLES, on MariaDB;
     * TRUNCATE on PostgreSQL; ALTER on SQL Server), or an app table with a foreign key to one of these
     * tables. A reader does not hold them off. If the DELETEs fail too, their error is thrown. On
     * PostgreSQL a refusal rolls back to a savepoint (the transaction would be aborted otherwise);
     * SQL Server rolls back the whole transaction on one, so it checks both first. On MySQL and
     * MariaDB outside a transaction of yours the DELETEs run in bounded transactions
     * (clearInBatches()); one interrupted part way is finished by running it again.
     *
     * ponytail: on MySQL/MariaDB each TRUNCATE commits on its own, under the table locks: no other session
     * sees the index half cleared, but a clear interrupted part way leaves it so until it is run
     * again. A crash-atomic cheap clear there, and a cheap one on MySQL, would swap in empty copies
     * of the tables (RENAME TABLE), at the cost of rebuilding their foreign keys.
     */
    public function flushAll(): bool
    {
        return self::readingTheWriteConnection(DB::connection(), fn () => $this->clearIndex(null));
    }

    /**
     * Run $run with every read of $connection on its write session (Laravel's
     * useWriteConnectionWhenReading()), and the connection's own setting put back after. The
     * clears run so: on a read/write split without sticky, MySQL/MariaDB's reads outside a
     * transaction went to the read connection, another session. Pointed at a replica behind the
     * primary, it told the clear that no other model had a document, and the TRUNCATE emptied that
     * model's index; it hid another session's lock on the index tables and the app's foreign key to
     * the words, listed fewer rows for the batches than the primary held, and gave the write session
     * the other session's foreign_key_checks and lock_wait_timeout back. Pointed at the primary, its
     * read waited behind the write session's own LOCK TABLES, for lock_wait_timeout (a year by
     * default). A rebuild --fresh reads the rows it indexes so too (RebuildCommand).
     *
     * @internal public for RebuildCommand
     */
    public static function readingTheWriteConnection(\Illuminate\Database\Connection $connection, \Closure $run): mixed
    {
        $before = (fn () => $this->readOnWriteConnection)->call($connection);
        $connection->useWriteConnectionWhenReading();
        try {
            return $run();
        } finally {
            $connection->useWriteConnectionWhenReading($before);
        }
    }

    /**
     * flushAll()'s clear, or flush()'s when $only names the one model type that is left to clear:
     * then only that type's meta row goes and the other types' stay, and the big tables are
     * truncated only if no other type has a document, which is checked after the lock that holds
     * index writes off (the table locks on MySQL/MariaDB, flushing()'s lock and the table lock on
     * PostgreSQL, the index write lock on SQL Server), so no write of another type lands between
     * the check and the TRUNCATE. Otherwise $byType (flush()'s DELETE by type) runs in flushing(),
     * or on MySQL/MariaDB outside a transaction of yours flushInBatches() does.
     *
     * Its callers run it with every read on the write session (readingTheWriteConnection()).
     */
    private function clearIndex(?string $only, ?\Closure $byType = null): bool
    {
        $connection = DB::connection();
        $driver     = $connection->getDriverName();
        // Each model's words, once 2026_10_13_000001 has created them: clear --all also
        // empties an index before the upgrade's migrations run (UPGRADE: A large index).
        $own        = $connection->getSchemaBuilder()->hasTable('fuzzy_index_model_terms') ? ['fuzzy_index_model_terms'] : [];
        $tables     = ['fuzzy_index_postings', 'fuzzy_index_documents', 'fuzzy_index_meta', ...$own, 'fuzzy_index_terms'];
        $big        = ['fuzzy_index_postings', 'fuzzy_index_documents', ...$own];
        $deletes    = function (array $truncated) use ($tables, $only, $byType) {
            if ($only !== null) {
                if ($truncated === []) {
                    $byType();

                    return;
                }
                // Nothing else is indexed: the words go whole, this type's meta row with them.
                DB::table('fuzzy_index_meta')->where('model_type', $only)->delete();
                DB::table('fuzzy_index_terms')->delete();

                return;
            }
            foreach (array_diff($tables, $truncated) as $table) {
                DB::table($table)->delete();
            }
        };
        $alone      = fn () => $only === null || !DB::table('fuzzy_index_documents')->where('model_type', '<>', $only)->exists();

        // Laravel 10 connects to MariaDB as mysql; the server tells them apart, without a query.
        $mariadb = $driver === DbDialect::MARIADB
            || ($driver === DbDialect::MYSQL && stripos((string) $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'mariadb') !== false);

        if (($mariadb || $driver === DbDialect::MYSQL) && DB::transactionLevel() === 0) {
            $ran    = false;
            $locked = function () use ($connection, $tables, $big, $deletes, $alone, $mariadb, &$ran) {
                $connection->unprepared('LOCK TABLES ' . implode(', ', array_map(fn ($table) => $connection->getQueryGrammar()->wrapTable($table) . ' WRITE', $tables)) . ($mariadb ? ' NOWAIT' : ''));
                try {
                    if ($alone()) {
                        foreach ($big as $table) {
                            DB::table($table)->truncate();
                        }
                        // The postings and model terms are empty and every index table is locked: the
                        // words can go by TRUNCATE, which their foreign key refuses while it is checked.
                        // Not where a table of the app's references the words: its rows would be left
                        // pointing at nothing, where the DELETE cascades.
                        $left = $big;
                        if (!$this->wordsReferencedByTheApp()) {
                            $checks = (int) $connection->scalar('select @@session.foreign_key_checks');
                            try {
                                $connection->unprepared('SET SESSION foreign_key_checks = 0');
                                DB::table('fuzzy_index_terms')->truncate();
                                $left[] = 'fuzzy_index_terms';
                            } finally {
                                $connection->unprepared("SET SESSION foreign_key_checks = {$checks}");
                            }
                        }
                        $deletes($left);
                        $ran = true;
                    }
                } finally {
                    $connection->unprepared('UNLOCK TABLES');
                }
            };
            // Another type has documents: nothing to truncate, so no table lock to take either (the
            // check under the lock stays: this read holds nothing).
            if ($alone()) {
                $this->truncateWhenFree($locked, $tables, $mariadb);
            }
            if ($ran) {
                return true;
            }
            $only === null ? $this->clearInBatches($tables) : $this->flushInBatches($only);

            return false;
        }

        $truncated = [];
        $this->flushing(function () use ($connection, $driver, $tables, $big, $deletes, $alone, $only, &$truncated) {
            if ($driver === DbDialect::PGSQL) {
                $pgTables = $only === null ? $tables : array_diff($tables, ['fuzzy_index_meta']);
                $list     = implode(', ', array_map([DbDialect::class, 'rawIdentifier'], $pgTables));
                $ran      = false;
                if ($this->withoutWaiting(function () use ($list, $alone, &$ran) {
                    DB::transaction(function () use ($list, $alone, &$ran) { // a savepoint
                        DB::statement("LOCK TABLE {$list} IN ACCESS EXCLUSIVE MODE NOWAIT");
                        if ($alone()) {
                            DB::statement("TRUNCATE TABLE {$list}");
                            $ran = true;
                        }
                    });
                }) && $ran) {
                    $truncated = $pgTables;
                }
            } elseif ($driver === DbDialect::SQLSRV) {
                // A refused TRUNCATE rolls the whole transaction back there, the index write lock
                // with it (1088, 4712: tested), so ask first: ALTER on each, no foreign key to them.
                $names = array_map(fn ($table) => $connection->getTablePrefix() . $table, $big);
                $ids   = implode(', ', array_fill(0, count($names), 'object_id(?)'));
                $alter = implode(' * ', array_fill(0, count($names), "has_perms_by_name(?, 'OBJECT', 'ALTER')"));
                if ($alone() && (int) $connection->scalar("select case when exists (select 1 from sys.foreign_keys where referenced_object_id in ({$ids})) then 0 else {$alter} end", [...$names, ...$names], false) === 1) {
                    foreach ($big as $table) {
                        DB::table($table)->truncate();
                    }
                    $truncated = $big;
                }
            }

            $deletes($truncated);
        });

        return $truncated !== [] || $driver === DbDialect::SQLITE;
    }

    /** Whether a table other than the index's own has a foreign key to the words (MySQL/MariaDB). */
    private function wordsReferencedByTheApp(): bool
    {
        $connection = DB::connection();
        $own        = array_map(fn ($table) => $connection->getTablePrefix() . $table, ['fuzzy_index_postings', 'fuzzy_index_model_terms']);

        return (int) $connection->scalar(
            'select count(*) from information_schema.referential_constraints where constraint_schema = database() and referenced_table_name = ?'
            . ' and table_name not in (' . implode(',', array_fill(0, count($own), '?')) . ')',
            [$connection->getTablePrefix() . 'fuzzy_index_terms', ...$own]
        ) > 0;
    }

    /**
     * Whether another MySQL session holds a metadata lock on one of $tables: a transaction of
     * the app's that has read one keeps its shared lock to its end. LOCK TABLES would queue behind
     * it, and the app's next statement on another index table then queues behind LOCK TABLES: a
     * deadlock the server breaks by killing the app's transaction. So the clear does not ask. One
     * race stays, accepted: a transaction that starts reading one index table between this check and
     * LOCK TABLES, then reads another during the wait of at most a second, can be chosen as the
     * victim (an explicit transaction across two index tables, in a window of microseconds). Null
     * when the lock table cannot be read (performance_schema off, no privilege, MySQL < 8.0.16, any
     * error): the caller then counts the tables as in use, as a LOCK TABLES it cannot see clear of a
     * reader could cost the reader's transaction.
     *
     * @param  list<string>  $tables
     */
    private function tablesInUse(array $tables): ?bool
    {
        $connection = DB::connection();
        $names      = array_map(fn ($table) => $connection->getTablePrefix() . $table, $tables);
        try {
            return (int) $connection->scalar(
                "select count(*) from performance_schema.metadata_locks where object_type = 'TABLE' and lock_status = 'GRANTED'"
                . ' and object_schema = database() and object_name in (' . implode(',', array_fill(0, count($names), '?')) . ')'
                . ' and owner_thread_id <> ps_current_thread_id()',
                $names
            ) > 0;
        } catch (\Illuminate\Database\QueryException) {
            return null;
        }
    }

    /**
     * Run clearIndex()'s $truncate on MySQL/MariaDB, TRUNCATE_TRIES times TRUNCATE_PAUSE apart at
     * most, until it ran: a reader's transaction mostly ends within them. MariaDB takes its table
     * locks with NOWAIT, refused at once (1205) while another session holds one. MySQL's LOCK
     * TABLES has no NOWAIT: a try waits one second at most (the least lock_wait_timeout allows; the
     * session's own is put back after), and is made only while no other session holds one of the
     * tables (tablesInUse()). Where that cannot be read, the tables count as in use and no try is
     * made: clearIndex() deletes at once. So an app's transaction waits at most a second a try. A
     * refusal other than a lock wait (a privilege, a foreign key) ends the tries.
     *
     * @param  list<string>  $tables
     */
    private function truncateWhenFree(\Closure $truncate, array $tables, bool $mariadb): void
    {
        $connection = DB::connection();
        $wait       = $mariadb ? null : (int) $connection->scalar('select @@session.lock_wait_timeout');
        try {
            if ($wait !== null) {
                $connection->unprepared('SET SESSION lock_wait_timeout = 1');
            }
            for ($try = 1; $try <= self::TRUNCATE_TRIES; $try++) {
                $busy = $mariadb ? false : $this->tablesInUse($tables);
                if ($busy === null) {
                    return;
                }
                if (!$busy) {
                    try {
                        $truncate();

                        return;
                    } catch (\Illuminate\Database\QueryException $e) {
                        if (($e->errorInfo[1] ?? null) !== 1205) {
                            return;
                        }
                    }
                }
                if ($try < self::TRUNCATE_TRIES) {
                    usleep(self::TRUNCATE_PAUSE);
                }
            }
        } finally {
            if ($wait !== null) {
                $connection->unprepared("SET SESSION lock_wait_timeout = {$wait}");
            }
        }
    }

    /**
     * Run $truncate, whose table locks are taken with NOWAIT (PostgreSQL), and tell whether it ran.
     * Refused because the tables are busy (55P03), it is tried again: a search holds its shared
     * lock only while it runs, so under traffic one try mostly meets one. Any other refusal (a
     * privilege, a foreign key) is final. Between tries it holds flushing()'s lock, so index writes
     * wait those moments too, as they wait for the clear. Anything else goes up, a
     * DeadlockException too: Laravel rolls back no savepoint for one, so flushing()'s transaction
     * rolls back whole and the index stays as it was.
     *
     * ponytail: 5 tries 200 ms apart, then the DELETEs; make them configurable if an index under
     * constant load keeps deleting row by row.
     */
    private function withoutWaiting(\Closure $truncate): bool
    {
        for ($try = 1; ; $try++) {
            try {
                $truncate();

                return true;
            } catch (\Illuminate\Database\QueryException $e) {
                if ($try === 5 || $e->getCode() !== '55P03') {
                    return false;
                }
                usleep(200_000);
            }
        }
    }

    /**
     * clearIndex()'s DELETE of every row on MySQL/MariaDB outside a transaction of yours, in
     * bounded transactions as flushInBatches() deletes a model's (see there): the postings, the
     * models' words and the documents, each model's FLUSH_ROWS at a time, the meta rows, then the
     * words. Then one transaction deletes what a write between the batches left, the documents
     * first, as flushInBatches() ends: the index ends empty, as one DELETE would leave it. A search
     * meanwhile finds it partly emptied, and an interrupted one leaves it so; run it again, when the
     * index is idle, so it truncates.
     *
     * @param  list<string>  $tables  the index tables there are (no model terms before 2026_10_13_000001)
     */
    private function clearInBatches(array $tables): void
    {
        // Before the upgrade's migrations the postings name their model by class (UPGRADE: A large index).
        $posted  = DB::connection()->getSchemaBuilder()->hasColumn('fuzzy_index_postings', 'model_type_id') ? 'model_type_id' : 'model_type';
        $byModel = [
            'fuzzy_index_postings'    => [$posted, 'model_id'],
            'fuzzy_index_model_terms' => ['model_type_id', 'term_id'],
            'fuzzy_index_documents'   => ['model_type', 'model_id'],
        ];
        foreach (array_intersect_key($byModel, array_flip($tables)) as $table => [$model, $key]) {
            foreach (DB::table($table)->distinct()->pluck($model) as $type) {
                $this->deleteInBatches($table, [$model => $type], $key);
            }
        }
        DB::table('fuzzy_index_meta')->delete();
        $this->deleteInBatches('fuzzy_index_terms', [], 'id');

        self::atRepeatableRead(fn () => DB::transaction(function () use ($tables) {
            foreach (['fuzzy_index_documents', ...array_diff($tables, ['fuzzy_index_documents'])] as $table) {
                DB::table($table)->delete();
            }
        }, self::ATTEMPTS));
    }

    /**
     * Run $run, a batched flush's or clear's final transaction, at REPEATABLE READ, and put the
     * session's own level back after. Its argument is that the DELETE of the type's documents,
     * which comes first, holds every write of the type off its claim (claimDocuments()) with the
     * gap locks it takes; at READ COMMITTED it takes none, so a write could claim a new document
     * after the words to count again were read, and leave its postings deleted and its words
     * counted. It is the session's level, not the next transaction's, so a deadlock's retry keeps
     * it. In a transaction of yours (its level above 0) nothing changes: the batched path does not
     * run there.
     */
    private static function atRepeatableRead(\Closure $run): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0) {
            return $run();
        }
        try {
            $was = $connection->scalar('select @@session.transaction_isolation');
        } catch (\Illuminate\Database\QueryException) {
            $was = $connection->scalar('select @@session.tx_isolation'); // MariaDB before 11.1, MySQL before 8.0
        }
        $was = str_replace('-', ' ', strtoupper((string) $was));
        if ($was === 'REPEATABLE READ' || !in_array($was, ['READ UNCOMMITTED', 'READ COMMITTED', 'SERIALIZABLE'], true)) {
            return $run();
        }
        $connection->unprepared('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        try {
            return $run();
        } finally {
            $connection->unprepared("SET SESSION TRANSACTION ISOLATION LEVEL {$was}");
        }
    }

    /**
     * flush()'s DELETE by type on MySQL/MariaDB outside a transaction of yours, in bounded
     * transactions: one DELETE of a large model's postings carried their whole undo (207M rows at
     * 12M documents ran 52 minutes), and the rebuild after it crawled behind their purge. In order:
     * the postings, FLUSH_ROWS a transaction by model_id; the model's words, 1,000 a transaction in
     * id order, each word's row locked as every write locks it (adjustDocCounts()), the model's
     * count of it deleted and doc_count recounted from the other models' counts
     * (recountDictionary()); the documents by model_id; then, in one transaction, what is left of
     * the type and its meta row (below); then the words no posting holds any more
     * (deleteUnpostedWords()).
     *
     * doc_count is recounted once a word, after the postings, not adjusted per batch of them: a
     * write of another model changes its own count of a word and doc_count together, in one
     * transaction, holding the word's row, so the recount, which holds the row too, reads its change
     * whole or not at all, and a write waiting for the row adds its change to the recounted value:
     * none is lost or counted twice. Adjusting per batch would read every deleted posting's words
     * and lock them while the postings go.
     *
     * A write of the model between two batches (a queue worker's) kept the rows the later batches had
     * passed and raised the counts for good, where 2.0.1's one transaction held it off. So the flush
     * ends with one transaction: it deletes the type's documents first, as every write of the type
     * claims them (claimDocuments()), so the DELETE waits for the writes in flight and its locks hold
     * new ones off to its commit; then the postings and model terms those writes left, their words
     * locked in id order and recounted; then the meta row. Its plain reads come after that DELETE,
     * so they see every write it waited for. The type ends empty and every count exact, as after
     * one DELETE; the transaction touches only what was written meanwhile. The DELETE's gap locks
     * are what hold the new writes off, and READ COMMITTED takes none, so the transaction runs at
     * REPEATABLE READ whatever the session is set to (atRepeatableRead()). A search during the
     * flush still finds the model partly emptied. Interrupted part way, it is finished by running
     * it again, before any rebuild: each step goes on from what is left.
     */
    private function flushInBatches(string $modelType): void
    {
        $typeId = self::modelTypeId($modelType);
        if ($typeId !== null) {
            $this->deleteInBatches('fuzzy_index_postings', ['model_type_id' => $typeId], 'model_id');

            $after = 0;
            while (($ids = DB::table('fuzzy_index_model_terms')->where('model_type_id', $typeId)->where('term_id', '>', $after)
                ->orderBy('term_id')->limit(1000)->pluck('term_id')->map(fn ($id) => (int) $id)->all()) !== []) {
                $after = end($ids);
                DB::transaction(function () use ($typeId, $ids) {
                    DB::table('fuzzy_index_terms')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
                    DB::table('fuzzy_index_model_terms')->where('model_type_id', $typeId)->whereIn('term_id', $ids)->delete();
                    self::recountDictionary(fn ($words) => $words->whereIn('id', $ids));
                }, self::ATTEMPTS);
            }
        }
        $this->deleteInBatches('fuzzy_index_documents', ['model_type' => $modelType], 'model_id');

        self::atRepeatableRead(fn () => DB::transaction(function () use ($modelType) {
            DB::table('fuzzy_index_documents')->where('model_type', $modelType)->delete();
            $typeId = self::modelTypeId($modelType);
            if ($typeId !== null) {
                $words = DB::table('fuzzy_index_model_terms')->where('model_type_id', $typeId)->pluck('term_id')
                    ->merge(DB::table('fuzzy_index_postings')->where('model_type_id', $typeId)->distinct()->pluck('term_id'))
                    ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
                foreach (array_chunk($words, 1000) as $ids) {
                    DB::table('fuzzy_index_terms')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
                }
                DB::table('fuzzy_index_postings')->where('model_type_id', $typeId)->delete();
                DB::table('fuzzy_index_model_terms')->where('model_type_id', $typeId)->delete();
                foreach (array_chunk($words, 1000) as $ids) {
                    self::recountDictionary(fn ($terms) => $terms->whereIn('id', $ids));
                }
            }
            DB::table('fuzzy_index_meta')->where('model_type', $modelType)->delete();
        }, self::ATTEMPTS));

        $this->deleteUnpostedWords();
    }

    /**
     * Delete the rows of $table that $where selects, $flushRows a statement in $key order, each
     * statement in its own transaction (a deadlock retries it). The key of a batch's last row is
     * read first, from an index alone, then the rows up to it are deleted, so each batch starts past
     * the rows the one before deleted rather than over them while purge has yet to clear them. A
     * batch takes the rows of its last key whole: for the postings, at most one document's more.
     *
     * @param  array<string, int|string>  $where
     */
    private function deleteInBatches(string $table, array $where, string $key): void
    {
        $after = null;
        do {
            $rows = fn () => DB::table($table)->where($where)->when($after !== null, fn ($query) => $query->where($key, '>', $after));
            $last = $rows()->orderBy($key)->offset($this->flushRows - 1)->value($key);
            DB::transaction(fn () => $rows()->when($last !== null, fn ($query) => $query->where($key, '<=', $last))->delete(), self::ATTEMPTS);
            $after = $last;
        } while ($last !== null);
    }

    /**
     * flush()'s orphan sweep on MySQL/MariaDB outside a transaction of yours: delete the words no
     * posting holds, 1,000 a transaction. They are found by a plain read, then locked in id order,
     * as every write locks them, and read again: that read is the transaction's first plain one,
     * so it sees every posting committed before the lock, and none can be added while the lock is
     * held (a posting's foreign key check locks its word). A word a write of another model has
     * just posted stays; no posting's gap is locked.
     */
    private function deleteUnpostedWords(): void
    {
        $unposted = fn () => DB::table('fuzzy_index_terms')->whereNotExists(fn ($query) => $query->selectRaw('1')
            ->from('fuzzy_index_postings')->whereColumn('fuzzy_index_postings.term_id', 'fuzzy_index_terms.id'));

        $after = 0;
        while (($ids = $unposted()->where('id', '>', $after)->orderBy('id')->limit(1000)->pluck('id')->map(fn ($id) => (int) $id)->all()) !== []) {
            $after = end($ids);
            DB::transaction(function () use ($unposted, $ids) {
                $locked = DB::table('fuzzy_index_terms')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();
                $gone   = $locked === [] ? [] : $unposted()->whereIn('id', $locked)->pluck('id')->all();
                if ($gone !== []) {
                    DB::table('fuzzy_index_terms')->whereIn('id', $gone)->delete();
                }
            }, self::ATTEMPTS);
        }
    }

    /**
     * Flush (delete) the entire index for a model class. When no other model type has a document,
     * the big tables are truncated as flushAll() does, on every database it truncates on (see
     * clearIndex()); this type's meta row goes and the others' stay. Otherwise, or when the tables are
     * busy, its rows are deleted by type: in one transaction, but on MySQL/MariaDB outside a
     * transaction of yours in bounded ones (flushInBatches()), the last of which deletes what a write
     * between them left: a search meanwhile finds the type partly emptied, and a flush interrupted
     * part way is finished by running it again.
     */
    public function flush(string $modelClass): void
    {
        // The DELETE by type, where another model type is indexed (clearIndex()).
        $byType = function () use ($modelClass) {
            // None: nothing is posted under the type. Its row stays (see dictionaryIds()).
            $typeId = self::modelTypeId($modelClass);

            if ($typeId !== null) {
                // Count each word this model held again from the other models' own counts, once its
                // rows of them are gone. A word another model still uses survives the orphan sweep
                // below, and it kept counting this model's documents: every rebuild --fresh inflated
                // it. Taking this model's share off instead took off twice a document the dictionary
                // had already let go of, one whose postings an upgrade's copy kept, so the
                // rebuild the migration's warning names left the word's count short for good.
                // One chunk of words at a time, their dictionary rows locked in id order first, as
                // every write takes them (adjustDocCounts()).
                DB::table('fuzzy_index_postings')
                    ->where('model_type_id', $typeId)
                    ->groupBy('term_id')
                    ->selectRaw('term_id')
                    ->chunkById(1000, function ($rows) use ($typeId) {
                        $ids = $rows->pluck('term_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                        DB::table('fuzzy_index_terms')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
                        DB::table('fuzzy_index_model_terms')->where('model_type_id', $typeId)->whereIn('term_id', $ids)->delete();
                        self::recountDictionary(fn ($words) => $words->whereIn('id', $ids));
                    }, 'term_id');

                // Bulk delete index data for this model type — DB-side, no PHP memory load. Its own
                // word counts go whole.
                DB::table('fuzzy_index_postings')->where('model_type_id', $typeId)->delete();
                DB::table('fuzzy_index_model_terms')->where('model_type_id', $typeId)->delete();
            }
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
                    'WHERE p.term_id IS NULL'
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
        };
        // The only model type indexed: the index is truncated as clear --all does (clearIndex()).
        self::readingTheWriteConnection(DB::connection(), fn () => $this->clearIndex($modelClass, $byType));
    }

    /** @var array<string, Pipeline|false> model class → resolved override pipeline, or false = "uses the default" */
    private static array $pipelines = [];

    /** @var array<string, true> SearchableColumns::connectionKey()|search_path => its postings table's statistics describe it (PostgreSQL) */
    private static array $analyzed = [];

    /** @var array<string, true> SearchableColumns::connectionKey() => a check waits for the transaction's commit */
    private static array $pendingChecks = [];

    /** @var array<string, array<string, int>> SearchableColumns::connectionKey()|table => name => id, of the model types and columns (see dictionaryIds()) */
    private static array $dictionary = [];

    /** @var array<string, true> connection names whose statements are watched to forget $dictionary (see dictionaryIds()) */
    private static array $watched = [];

    /**
     * Forget the per-process caches: the pipelines, which PostgreSQL index tables have statistics,
     * the model types' and columns' ids, and the column types and collations the BM25 scorer reads
     * (Bm25Scorer::resetColumnCache()). The migrations that create those tables call this, and so
     * does the service provider for each new application (each test of an app's suite) and
     * after migrations or a schema dump ran in the process.
     */
    public static function resetPipelineCache(): void
    {
        self::$pipelines     = [];
        self::$analyzed      = [];
        self::$pendingChecks = [];
        self::$dictionary    = [];
        self::$watched       = [];
        Bm25Scorer::resetColumnCache();
    }

    /**
     * The id of $modelType in fuzzy_index_model_types, which its postings are written under; null
     * when it has none, so nothing is posted under it. $insert adds the row on first need (write()).
     */
    public static function modelTypeId(string $modelType, bool $insert = false): ?int
    {
        return self::dictionaryIds('fuzzy_index_model_types', 'model_type', [$modelType], $insert)[$modelType] ?? null;
    }

    /**
     * name => id of the column names $names in fuzzy_index_columns, for those that have one: a
     * column without one has no posting. $insert adds the missing ones (write()).
     *
     * @param  array<int|string>  $names
     * @return array<string, int>
     */
    public static function columnIds(array $names, bool $insert = false): array
    {
        return self::dictionaryIds('fuzzy_index_columns', 'name', $names, $insert);
    }

    /**
     * name => id of $names in $table, one of the two dictionaries the postings name their model type
     * and their column through, each name as the column's collation compares it: a
     * case-insensitive one finds Title for title, as the earlier layout's string columns compared
     * them. Remembered per process and connection, since a row is never deleted and its id never
     * changes; but only outside a transaction, once the one that read it has committed: a write that
     * rolls back takes the rows it inserted with it, and under RefreshDatabase or
     * DatabaseTransactions Laravel runs after-commit callbacks inside the test's own transaction,
     * which it then rolls back. On PostgreSQL a statement on the connection that sets
     * search_path (a runtime SET of schema-per-tenant) forgets them: each tenant schema numbers its
     * own, and the config, which connectionKey() reads, does not change. So does a truncate
     * or delete of either dictionary, on any connection: a test that empties the index tables
     * between two writes numbers the names again from 1, and a remembered id then named another
     * model's type or column, whose search served the first model's rows. Watched
     * rather than read on every lookup, which added a query to every search.
     *
     * $insert adds the missing names, sorted, so concurrent first writes take them in one order: a
     * write waits for another's uncommitted insert of the same name, then finds it, through a current
     * read on MySQL/MariaDB, whose snapshot may predate it. write() calls this before it locks any
     * word, so a write waiting here holds no lock another write needs, in the order every write takes them.
     *
     * @param  array<int|string>  $names
     * @return array<string, int>
     */
    private static function dictionaryIds(string $table, string $column, array $names, bool $insert): array
    {
        $connection = DB::connection();
        $key        = SearchableColumns::connectionKey($connection) . '|' . $table;
        $ids        = [];

        // ponytail: a search_path changed, or a dictionary emptied, outside Laravel's query events
        // (a raw PDO exec, a server-side function, a restore, DatabaseTruncation, which unsets the
        // dispatcher: the provider's reset per application covers that one) is not seen; read
        // current_setting() per lookup if that matters.
        if (!isset(self::$watched[$connection->getName()])) {
            self::$watched[$connection->getName()] = true;
            $pgsql = $connection->getDriverName() === DbDialect::PGSQL;
            $connection->listen(function (\Illuminate\Database\Events\QueryExecuted $query) use ($connection, $pgsql) {
                // Runs for every query: two stripos() before any regex. Laravel's truncate() is a
                // `truncate [table]` on the servers, a `delete from` on SQLite; a prefix and a schema
                // may come before the name.
                if ((stripos($query->sql, 'fuzzy_index_model_types') !== false || stripos($query->sql, 'fuzzy_index_columns') !== false)
                    && preg_match('/^\s*(?:truncate|delete\s+from)\s+(?:table\s+)?(?:\S+\.)?["`\[]?\w*fuzzy_index_(?:model_types|columns)\b/i', $query->sql) === 1) {
                    self::$dictionary = [];
                }
                // A SET [SESSION|LOCAL] search_path or set_config('search_path', …), not a read of it.
                if ($pgsql && $query->connectionName === $connection->getName() && preg_match("/\\bset\\s+(session\\s+|local\\s+)?search_path\\b|set_config\\s*\\(\\s*'search_path'/i", $query->sql) === 1) {
                    self::$dictionary = [];
                }
            });
        }
        $missing    = []; // name => name
        foreach ($names as $name) {
            $name = (string) $name;
            if (isset(self::$dictionary[$key][$name])) {
                $ids[$name] = self::$dictionary[$key][$name];
            } else {
                $missing[$name] = $name;
            }
        }

        if ($missing === []) {
            return $ids;
        }

        $found = self::readDictionary($table, $column, $missing, current: false);
        if ($insert && count($found) < count($missing)) {
            self::insertDictionary($table, $column, array_diff_key($missing, $found));
            $found = self::readDictionary($table, $column, $missing, current: true);

            // MySQL's INSERT IGNORE stores a name too long for the column cut short.
            if (count($found) < count($missing)) {
                throw new \RuntimeException(sprintf('fuzzy-search: %s could not record [%s]: is it longer than %s.%s allows?', self::class, implode('], [', array_diff_key($missing, $found)), $table, $column));
            }
        }

        $remember = function () use ($connection, $key, $found) {
            if ($connection->transactionLevel() === 0) {
                self::$dictionary[$key] = $found + (self::$dictionary[$key] ?? []);
            }
        };
        $connection->transactionLevel() > 0 ? $connection->afterCommit($remember) : $remember();

        return $ids + $found;
    }

    /**
     * name => id of the rows of $table whose $column the database finds equal to one of $names: by
     * the stored name itself, or, for a name not stored as it is spelt (letter case, trailing
     * spaces) when the read found anything, one name at a time: two spellings in one call find the
     * one row a case-insensitive collation keeps for both. $current reads past MySQL's
     * snapshot.
     *
     * @param  array<string, string> $names name => name
     * @return array<string, int>
     */
    private static function readDictionary(string $table, string $column, array $names, bool $current): array
    {
        $lock   = $current && DbDialect::isMySqlFamily(DB::connection()->getDriverName());
        $read   = fn () => DB::table($table)->when($lock, fn ($query) => $query->sharedLock());
        $stored = [];
        foreach (array_chunk(array_values($names), 1000) as $chunk) {
            foreach ($read()->whereIn($column, $chunk)->get(['id', $column]) as $row) {
                $row = (object) $row; // an app-wide array fetch mode returns arrays
                $stored[(string) $row->{$column}] = (int) $row->id;
            }
        }

        $found = array_intersect_key($stored, $names);
        if ($stored !== [] && count($found) < count($names)) {
            foreach (array_diff_key($names, $found) as $name) {
                $id = $read()->where($column, $name)->value('id');
                if ($id !== null) {
                    $found[$name] = (int) $id;
                }
            }
        }

        return $found;
    }

    /** Insert $names into $table, sorted; a name another write inserted first is left as it is. */
    private static function insertDictionary(string $table, string $column, array $names): void
    {
        $names = array_map('strval', array_values($names));
        sort($names, SORT_STRING);
        $rows = array_map(fn (string $name) => [$column => $name], $names);

        if (DB::connection()->getDriverName() !== DbDialect::SQLSRV) {
            DB::table($table)->insertOrIgnore($rows);

            return;
        }

        // SQL Server has no INSERT IGNORE: a name another write inserted first fails its own
        // statement only (duplicateKey()), so one name a statement.
        foreach ($rows as $row) {
            try {
                DB::table($table)->insert($row);
            } catch (\Illuminate\Database\QueryException $e) {
                if (!self::duplicateKey($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * PostgreSQL only: ANALYZE the index tables. On tables it has never analyzed, PostgreSQL takes
     * about one posting per term, and the postings subquery that constrained and ordered searches
     * run (Bm25Scorer::whereRanked()) became a nested loop over every posting for every row: 60–120 s
     * at 50k rows until autovacuum's first ANALYZE. A rebuild ends with this, and an
     * index write runs it while the statistics do not describe the table ($whenStale; see
     * analyzeUnanalyzedIndex()). A user that does not own a table gets a WARNING, which skips that
     * table and throws nothing.
     *
     * Not while the postings table is under 16 pages (128 kB, about 1,000 postings).
     * Statistics from a tiny first write or rebuild planned the writes after it as if the index held
     * a few rows: inside one transaction, where nothing re-plans until the commit, every posting's
     * foreign-key check scanned the whole terms table, and a 50k-row index took 127 s instead of
     * 10 s. PostgreSQL costs a never-analyzed table at 10 pages at least, which plans them well.
     *
     * Best-effort: statistics only make searches faster, and the index they describe is
     * written already, so a failure here, in the check's catalog read as in the ANALYZE (a
     * lock_timeout while autovacuum holds the table, a lost connection), is report()ed, not thrown
     * (a lost connection inside a transaction aside, below). Thrown from the check at a commit, it
     * reached the caller of a DB::transaction() that had committed, and Laravel skipped the
     * transaction's later after-commit callbacks; thrown at the end of a rebuild, it failed a
     * rebuild that had indexed every row. Inside the caller's transaction (a rebuild run in one, or
     * an --async rebuild's finally() on the sync queue, inside the batch store's transaction) it
     * runs under a savepoint, rolled back on any failure: on PostgreSQL a failed statement aborts
     * the whole transaction, so the caller's next statement failed instead, and a batch's COMMIT
     * rolled back the whole rebuilt index. A lost connection there is thrown: the caller's
     * transaction went with it, and the rollback to the savepoint fails too.
     */
    public function analyzeIndex(bool $whenStale = false, bool $vacuum = false): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== DbDialect::PGSQL) {
            return;
        }

        $analyze = function () use ($connection, $whenStale, $vacuum) {
            if ($whenStale && !$this->statisticsStale($connection)) {
                return;
            }

            $grammar = $connection->getQueryGrammar();
            // scalar(): an app-wide array fetch mode makes selectOne() return an array.
            $pages   = $connection->scalar(
                "select pg_relation_size(to_regclass(?)) / current_setting('block_size')::int as pages",
                [$grammar->wrapTable('fuzzy_index_postings')]
            );

            if ((int) $pages < 16) {
                return;
            }

            // The two dictionaries too: a few rows each, which autovacuum's threshold never reaches,
            // and a read joined to them is planned on a guess otherwise. And each model's
            // words, which a model's typo, prefix and suggestion reads walk.
            $tables = array_map(fn (string $table) => $grammar->wrapTable($table), ['fuzzy_index_postings', 'fuzzy_index_terms', 'fuzzy_index_documents', 'fuzzy_index_columns', 'fuzzy_index_model_types', 'fuzzy_index_model_terms']);

            // $vacuum (a synchronous rebuild's end): VACUUM the postings too, outside any
            // transaction (VACUUM cannot run in one), so the visibility map lets the ranking's
            // index-only read of the postings' primary key skip the heap. Not an async
            // rebuild's finally(): it runs inside the last job, and the VACUUM of a large index
            // outran the job timeout; autovacuum sets the map there. Unprepared: VACUUM runs
            // only as a statement of its own.
            if ($vacuum && $connection->transactionLevel() === 0) {
                $connection->unprepared('VACUUM (ANALYZE) ' . array_shift($tables));
            }

            $connection->statement('ANALYZE ' . implode(', ', $tables));
        };

        $level = $connection->transactionLevel();

        try {
            if ($level > 0) {
                $connection->beginTransaction(); // a savepoint
            }
            $analyze();
            if ($level > 0) {
                $connection->commit();
            }
        } catch (\Throwable $e) {
            // Back to the savepoint before report(), which may write through this connection. Not
            // transaction(): on a deadlock or a serialization failure it drops the level without
            // ROLLBACK TO SAVEPOINT (MySQL has rolled the whole transaction back by then), and
            // PostgreSQL's stayed aborted. Nothing to undo when the savepoint itself failed.
            try {
                if ($connection->transactionLevel() > $level) {
                    $connection->rollBack($level);
                }
            } catch (\Throwable) {
                // A lost connection: the caller's transaction went with it, and Laravel has reset
                // the level to 0. Thrown, as the caller's own next statement would have been:
                // swallowed, the caller carried on at level 0, autocommitting what followed.
                throw $e;
            }

            report($e);
        }
    }

    /**
     * After an index write on PostgreSQL: analyzeIndex() while the statistics do not describe the
     * postings table (statisticsStale()), and not before it reaches analyzeIndex()'s 16 pages.
     *
     * Inside the caller's transaction ANALYZE would hold its lock until the commit, so the check
     * waits for the commit: once, however many index writes the transaction held,
     * and not at all when it rolls back, which drops the callback. A bulk import in one transaction
     * (Scout's default config gives every engine write the app's transaction) otherwise left the
     * tables unanalyzed. The check never runs inside a transaction: under DatabaseTransactions,
     * Laravel's testing manager runs the callback at once, and it would defer itself again without
     * end.
     */
    private function analyzeUnanalyzedIndex(): void
    {
        $connection = DB::connection();

        if ($connection->getDriverName() !== DbDialect::PGSQL) {
            return;
        }

        if ($connection->transactionLevel() > 0) {
            // Each write registers a callback (a rollback drops the ones it held); the first to run
            // at the commit checks, and the others find nothing pending.
            $id = SearchableColumns::connectionKey($connection);
            self::$pendingChecks[$id] = true;
            $connection->afterCommit(function () use ($connection, $id) {
                if ($connection->transactionLevel() === 0 && isset(self::$pendingChecks[$id])) {
                    unset(self::$pendingChecks[$id]);
                    $this->analyzeUnanalyzedIndex();
                }
            });

            return;
        }

        $this->analyzeIndex(whenStale: true);
    }

    /**
     * Whether the statistics do not describe the postings table: it has none (pg_class.reltuples
     * <= 0: -1, never analyzed, on PostgreSQL 14+; 0, analyzed or indexed while empty) or has more
     * than doubled since they were taken (its pages against relpages). Statistics taken on a small
     * table stood for the table it grew into: after a one-row first write and 50k more rows, every
     * search took about 100 s. This process stops reading the catalog once they
     * describe 10,000 postings or more: autovacuum's 10% scale factor keeps them in proportion from
     * there. It remembers that per search_path too, which it reads on every check: schema-per-tenant
     * PostgreSQL switches it on one connection and database, and a worker that had indexed one
     * tenant past 10,000 postings never analyzed another's tables.
     */
    private function statisticsStale(\Illuminate\Database\Connection $connection): bool
    {
        // Where the connection points (connectionKey(): host, port, the configured search_path) and the
        // search_path in effect now, which a runtime SET can change.
        $id = SearchableColumns::connectionKey($connection) . '|' . $connection->scalar("select current_setting('search_path')");

        if (isset(self::$analyzed[$id])) {
            return false;
        }

        // As an object whatever the fetch mode: an app-wide array mode makes it an array.
        $table = $connection->selectOne(
            "select reltuples, relpages, pg_relation_size(oid) / current_setting('block_size')::int as pages from pg_class where oid = to_regclass(?)",
            [$connection->getQueryGrammar()->wrapTable('fuzzy_index_postings')]
        );
        $table = $table === null ? null : (object) $table;

        $described = $table !== null && (float) $table->reltuples > 0 && (int) $table->pages <= 2 * max(1, (int) $table->relpages);

        if ($described && (float) $table->reltuples >= 10000) {
            self::$analyzed[$id] = true;
        }

        return !$described;
    }

    /**
     * The pipeline (tokenizer, stemmer, stop words, accent folding) for one model class.
     * Resolves $searchable['tokenizer'|'stemmer'|'stemmer_language'|'locale'] overrides,
     * cached per class; non-Searchable classes and null get the default pipeline.
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
     * $modelClass selects the pipeline (its per-model overrides); null uses the default.
     *
     * @return string[]
     */
    public function processTerms(string $text, ?array $stopWords = null, ?string $modelClass = null): array
    {
        return array_values(array_unique($this->pipelineFor($modelClass)->tokens($text, $stopWords ?? [])));
    }

    /**
     * Index a collection of models in a single transaction per index type (see below):
     * RebuildCommand's and RebuildIndexJob's per-chunk write. Saved models are reloaded inside it,
     * one query for the chunk (see write()); unsaved instances are indexed as given. New dictionary terms go out
     * in chunked upserts, not one per term (on MySQL/MariaDB one per distinct doc_count
     * increment), and so do the postings and document rows.
     *
     * $scout: the Scout engine's update(). The rows are re-read as Scout's own jobs read
     * them, without global scopes, and a trashed row stays indexed while scout.soft_delete is on,
     * so Scout's onlyTrashed() and withTrashed() find it. Everything else reads through the
     * model's query: its global scopes, and SoftDeletes, whose trashed rows leave the index.
     *
     * Each model is indexed under its own index type (indexType()), and re-read through that type's
     * query, one write per type: a single-table-inheritance parent's query hydrates child instances,
     * so a chunk can mix classes. Under the first model's class, a chunk that began with a child was
     * indexed under the child, and the child's global scope dropped the chunk's parent rows.
     * A saved model's type is that of its row's kind (rowClass()), and the row is re-synced under
     * the other types that may hold it (unfileElsewhere()): Scout's update of a parent instance
     * holding a child row, a row Scout's queued job re-reads as its new kind after a type change,
     * and a rebuild that finds a row's type changed outside the app.
     *
     * @param  iterable<Model> $models
     * @return int the number of models indexed
     */
    public function indexBatch(iterable $models, bool $scout = false): int
    {
        $byType = []; // index type => ['keys' => key => key, 'unsaved' => key => model]
        $filed  = []; // class => key => the type it is filed under

        foreach ($models as $model) {
            if ($model->exists) {
                // Under its row's kind: an instance of the parent can hold a child row. The
                // instance's own type is resolved first, so a hook that names no valid type throws
                // before anything is written.
                self::indexType($model);
                $type = self::indexType(self::rowClass($model));
                $byType[$type]['keys'][(string) $model->getKey()] = $model->getKey(); // once: an upsert may touch a row once
                $filed[$model::class][(string) $model->getKey()] = $type;
            } else {
                $byType[self::indexType($model)]['unsaved'][(string) $model->getKey()] = $model;
            }
        }

        $indexed = 0;
        foreach ($byType as $type => $group) {
            $indexed += $this->write($type, array_values($group['keys'] ?? []), $group['unsaved'] ?? [], $scout);
        }

        $this->unfileElsewhere($filed);

        return $indexed;
    }

    /**
     * The one index write, in one transaction:
     *  1. claim the models' document rows (claimDocuments()), so a concurrent write for one of
     *     them waits for this one, or this one for it;
     *  2. reload the saved rows (reload()): the text indexed is the row as committed now, so
     *     whichever write commits last leaves the latest save indexed; on
     *     MySQL/MariaDB inside a transaction of yours, with a locking read before step 1;
     *  3. change the doc_count of every term the dictionary holds in one statement per 1,000
     *     terms, in id order (giving back the old terms, read by currentPostings(); raising
     *     the new ones);
     *  4. insert the terms the dictionary lacks, sorted; apply the same changes to the model
     *     type's own counts (adjustModelTerms()); then the postings (those the rows no
     *     longer hold deleted by key, the changed and new ones upserted), document rows and
     *     meta of the rows with text. On SQL Server, under the index write lock, the new rows
     *     are inserted and the changed ones updated by key instead (lockIndexForWrite()).
     * Nothing carries over between attempts, so a deadlock retry (ATTEMPTS) redoes it whole.
     * A row that is gone, soft-deleted or left without text leaves the index instead.
     *
     * A re-read row of another index type leaves $modelType's index too: a single-table-inheritance
     * parent's query hydrates a child row as the child, which is indexed under its own type. Under
     * $modelType, the parent's reindexRelated(), a job named with the parent class and a child saved
     * through a parent instance filed child rows where the child's own saves never reached them.
     * Such rows are written under their own type after the commit, one level deep: a row
     * that type's query hydrates as yet another type is not followed further.
     *
     * @param  list<int|string>     $keys    saved models, reloaded here
     * @param  array<string, Model> $unsaved key => instance, indexed as given
     * @param  bool                 $scout   re-read with Scout's visibility (see indexBatch())
     * @param  bool                 $refile  write the rows of another index type under it (see above)
     * @return int the number of models indexed
     */
    private function write(string $modelType, array $keys, array $unsaved, bool $scout = false, bool $refile = true): int
    {
        [$indexed, $elsewhere] = self::yieldingDeadlocks(fn () => DB::transaction(function () use ($modelType, $keys, $unsaved, $scout) {
            $ids = array_map('strval', [...$keys, ...array_keys($unsaved)]);

            // MySQL/MariaDB inside a transaction of yours: the rows are re-read before the claim, with
            // a locking read. A plain read there sees your transaction's REPEATABLE
            // READ snapshot, which starts at its first plain read: a row another process deleted
            // since went back into the index, and one it saved and indexed since went back to its
            // older text. A locking read reads the rows as they are now, and its shared locks hold
            // every other change to them off until your transaction ends, so a later save is indexed
            // after it. Before the claim, so the write never holds a claim while it waits for another
            // transaction's lock on your row. A write in a transaction of its own reads after its
            // claim (its snapshot starts there) and locks none of your rows. The rows' connection is
            // the model's, which may not be the index's: this write's own transaction is on the index's.
            $source = (new $modelType())->getConnection();
            $lock   = DbDialect::isMySqlFamily($source->getDriverName()) && $source->transactionLevel() > ($source === DB::connection() ? 1 : 0);
            $old    = $lock ? null : $this->claimDocuments($modelType, $ids); // id => doc_length, for the indexed ones

            $byModel   = []; // id => [column => [term => frequency]]
            $elsewhere = []; // index type => keys of the rows hydrated as a class indexed under it
            $reloaded  = $this->reload($modelType, $keys, $scout, $lock);

            // A key the type's own re-read missed, under single-table inheritance: the caller named
            // the type from an instance, not the row (a stale child instance saved or deleted after
            // another request moved the row to another kind, a delete through a parent instance
            // loaded without the type column, IndexModelJob named with the parent or the child after
            // a query-builder write). Read through the hierarchy's root without global scopes, a
            // live row goes to its kind's type, and a gone one leaves every other type that holds it
            // (filedUnder()), both through the refile below, whose write re-reads with that type's
            // scopes. A write that does not refile (the refile's own, unfileElsewhere()'s)
            // drops what this finds.
            if (count($reloaded) < count($keys) && self::hydratesOtherClasses($modelType)) {
                $missed = array_values(array_diff(array_map('strval', $keys), array_map('strval', array_keys($reloaded))));
                $root   = self::hierarchyRoot($modelType, concrete: true);
                $live   = [];
                foreach (Model::withoutEvents(fn () => (new $root())->newModelQuery()->useWritePdo()->when($lock, fn ($query) => $query->sharedLock())->whereKey($missed)->get()) as $row) {
                    $live[(string) $row->getKey()] = true;
                    if (($type = self::indexType($row)) !== $modelType) {
                        $elsewhere[$type][] = $row->getKey();
                    }
                }
                $gone = array_values(array_filter($missed, fn (string $key) => !isset($live[$key])));
                foreach ($gone === [] ? [] : $this->filedUnder($modelType, $gone) as $type => $typeKeys) {
                    if ($type !== $modelType) {
                        $elsewhere[$type] = [...($elsewhere[$type] ?? []), ...$typeKeys];
                    }
                }
            }

            $old ??= $this->claimDocuments($modelType, $ids);

            foreach ($reloaded + $unsaved as $id => $model) {
                // indexBatch() groups the unsaved instances by type, so only a re-read row can differ.
                if (($type = self::indexType($model)) !== $modelType) {
                    $elsewhere[$type][] = $model->getKey();
                    continue;
                }
                if (!self::indexesModel($model)) {
                    continue;
                }
                // A Scout model may have only the searchableText() hook, which is enough to be
                // indexed: getSearchableColumns() is the package trait's.
                $columns  = method_exists($model, 'getSearchableColumns') ? $model->getSearchableColumns() : [];
                $byColumn = $this->buildTokenFrequencyMap($model, $columns);
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

            // The model type's id, inserted with its first posting, and the columns', each inserted
            // with its first posting, before any word is locked (see dictionaryIds()).
            $typeId  = self::modelTypeId($modelType, insert: $byModel !== []);
            $columns = [];
            foreach ($byModel as $byColumn) {
                foreach (array_keys($byColumn) as $column) {
                    $columns[(string) $column] = true;
                }
            }
            $columnIds = self::columnIds(array_keys($columns), insert: true);

            // Every doc_count change to a term the dictionary already holds (the old terms given
            // back, the new ones raised) goes out as one UPDATE per 1,000 terms in id order, so a
            // write takes its locks on existing terms in the order every other write takes them:
            // giving back old terms, then raising new ones in a second statement, let two
            // writes whose terms cross each hold a row the other needed.
            $current  = $this->currentPostings($typeId, $ids, $old);
            $posted   = self::holders($current); // term id => rows holding it
            $existing = $this->termIds(array_map('strval', array_keys($counts)));
            $driver   = DB::connection()->getDriverName();

            // PostgreSQL: the words the read found missing go in first, at doc_count 0, sorted by
            // term, ON CONFLICT DO NOTHING; their ids are re-read, and their increments join the
            // id-ordered UPDATE below. A word another write committed between
            // two writes' reads was existing to one, locked in id order, and new to the other,
            // upserted in term order: the two crossed, and at 16 writers the deadlocks chained until
            // writes lost all three attempts. Here a write holds only rows it inserted itself, which
            // no other write can see, and waits only on another's uncommitted insert of the same
            // word, in the same sorted order; every lock after that is taken in id order. MySQL/
            // MariaDB keep the upsert below (an INSERT IGNORE there takes gap locks, and measured
            // far worse). SQL Server inserts them below, under its index write lock.
            if ($driver === DbDialect::PGSQL) {
                $new = [];
                foreach ($counts as $term => $increment) {
                    if (!isset($existing[$term])) {
                        $new[] = (string) $term;
                    }
                }
                sort($new, SORT_STRING);
                foreach (array_chunk($new, self::ROWS_PER_UPSERT) as $chunk) {
                    DB::table('fuzzy_index_terms')->insertOrIgnore(array_map(fn (string $term) => ['term' => $term, 'doc_count' => 0, 'term_length' => mb_strlen($term)], $chunk));
                }
                $existing += $this->termIds($new);
            }

            $deltas   = array_map(fn (int $holders) => -$holders, $posted);
            foreach ($counts as $term => $increment) {
                if (isset($existing[$term])) {
                    $deltas[$existing[$term]] = ($deltas[$existing[$term]] ?? 0) + $increment;
                }
            }
            // A word the locking read no longer finds was swept since the read above: a flush of
            // any model deletes every word no posting holds, which a word this write reuses after
            // re-indexing dropped it can be. It is inserted again below, as a missing one.
            $words    = $this->adjustDocCounts($deltas);
            $existing = array_filter($existing, fn ($id) => isset($words[$id]));

            // Rows leaving the index (gone, soft-deleted, no text left) lose their document row,
            // and rows never indexed lose the placeholder the claim inserted.
            $leaving = array_values(array_diff($ids, array_map('strval', array_keys($byModel))));
            foreach (array_chunk($leaving, 1000) as $chunk) {
                DB::table('fuzzy_index_documents')->where('model_type', $modelType)->whereIn('model_id', $chunk)->delete();
            }

            // PHP normalises numeric-string array keys (e.g. '10') to int keys, so a purely
            // numeric token comes back an int. Cast back to string wherever a key becomes a query
            // binding: SQL Server's multi-row VALUES (...) infers one type per column from
            // the batch of bindings, so a mixed int/string 'term' column fails with "Conversion
            // failed when converting the nvarchar value 'paginate' to data type int". $termIds[$term]
            // lookups still work because PHP normalises numeric-string keys the same way on read.
            //
            // Terms the dictionary lacks are inserted, sorted by term (on PostgreSQL, only a word
            // swept since its insert above). On SQL Server no other write inserts a word while this
            // one holds the index write lock, so a plain INSERT, each word at its increment.
            // Elsewhere a write that inserts one of them first turns
            // this insert into an update of its row: the conflict branch raises it by this write's
            // increment, the inserted row's own doc_count (EXCLUDED). So every
            // write takes its new words in one sorted order; a statement per increment, each
            // sorted only within itself, let two batches take them in opposite orders. MySQL/MariaDB
            // keep a statement per increment with a literal raise: the raise cannot name the
            // inserted row there on both (MySQL deprecates VALUES(), MariaDB has no row alias). Two
            // batches there can still take a shared new word in opposite orders, and a word one
            // read as existing and the other as new still crosses: retried, but at 16
            // parallel writers a few lose all three attempts, so queued imports need job tries.
            $missing = [];
            foreach ($counts as $term => $increment) {
                if (!isset($existing[$term])) {
                    $missing[(string) $term] = $increment;
                }
            }
            ksort($missing, SORT_STRING);

            $sqlsrv = $driver === DbDialect::SQLSRV;
            $source = DbDialect::isMySqlFamily($driver) ? null : 'excluded.doc_count'; // the inserted row's doc_count, as the conflict branch sees it
            $runs   = []; // raise => term => increment
            foreach ($missing as $term => $increment) {
                $runs[$sqlsrv ? '' : ($source ?? (string) $increment)][$term] = $increment; // SQL Server: one run
            }
            foreach ($runs as $raise => $terms) {
                foreach (array_chunk($terms, self::ROWS_PER_UPSERT, true) as $chunk) {
                    $rows = [];
                    foreach ($chunk as $term => $increment) {
                        $rows[] = ['term' => (string) $term, 'doc_count' => $increment, 'term_length' => mb_strlen((string) $term)];
                    }
                    if ($sqlsrv) {
                        DB::table('fuzzy_index_terms')->insert($rows);
                        continue;
                    }
                    DB::table('fuzzy_index_terms')->upsert(
                        $rows,
                        ['term'],
                        // Table-qualified: PostgreSQL treats a bare "doc_count" as ambiguous inside
                        // ON CONFLICT DO UPDATE. The qualified form is valid on MySQL/MariaDB
                        // (ON DUPLICATE KEY UPDATE), SQLite and PostgreSQL.
                        ['doc_count' => DB::raw(DbDialect::rawIdentifier('fuzzy_index_terms.doc_count') . " + {$raise}")]
                    );
                }
            }

            // After a sweep, a current read: MySQL's snapshot still shows a swept word's old row
            // beside the one just inserted. This write holds every one of these rows, so
            // it waits on none.
            $termIds = $existing + $this->termIds(array_map('strval', array_keys($missing)), current: count($words) < count($deltas));

            // The model type's own counts follow the dictionary's, now that this write holds every
            // word it changes.
            if ($typeId !== null) {
                $this->adjustModelTerms($typeId, self::termDeltas($posted, $counts, $termIds, $words));
            }

            // One posting per (term, column), with the document's length; a term missing from
            // $termIds means a pre-migration collation collapsed it into a variant: MySQL/MariaDB
            // *_ci, SQL Server's default.
            // Only the postings that change are written: a posting the row still holds at the same
            // frequency and document length is left as it is. What is left in $current, the
            // postings the rows no longer hold, is deleted by its primary key first (deletePostings()).
            $postingRows  = [];
            $changedRows  = []; // SQL Server: the postings held at another frequency or length, as updateByKey() rows
            $documentRows = [];
            foreach ($byModel as $id => $byColumn) {
                foreach ($this->postingRows($byColumn, $termIds, $typeId, $columnIds, $id, $lengths[$id]) as $row) {
                    [$termId, $columnId] = [(int) $row['term_id'], (int) $row['column_id']];
                    $held                = $current[$id][$termId][$columnId] ?? null;
                    unset($current[$id][$termId][$columnId]);
                    if ($held === [$row['frequency'], $row['doc_length']]) {
                        continue;
                    }
                    if ($held !== null && $sqlsrv) {
                        $changedRows[] = [$termId, $typeId, $row['model_id'], $columnId, $row['frequency'], $row['doc_length']];
                    } else {
                        $postingRows[] = $row;
                    }
                }
                if (!$sqlsrv || $lengths[$id] !== ($old[$id] ?? 0)) {
                    $documentRows[] = ['model_type' => $modelType, 'model_id' => (string) $id, 'doc_length' => $lengths[$id]];
                }
            }
            if ($posted !== []) {
                $this->deletePostings($typeId, $current);
            }

            if ($sqlsrv) {
                // Under the index write lock no other write has a posting of these rows, and every
                // document row is there since the claim: the new postings are inserted, the changed
                // ones and the lengths that change updated by key.
                foreach (array_chunk($postingRows, self::POSTING_ROWS_PER_UPSERT) as $chunk) {
                    DB::table('fuzzy_index_postings')->insert($chunk);
                }
                self::updateByKey('fuzzy_index_postings', ['term_id', 'model_type_id', 'model_id', 'column_id', 'frequency', 'doc_length'], $changedRows, ['term_id', 'model_type_id', 'model_id', 'column_id'], ['frequency' => 'v.frequency', 'doc_length' => 'v.doc_length']);
                self::updateByKey('fuzzy_index_documents', ['model_type', 'model_id', 'doc_length'], array_map('array_values', $documentRows), ['model_type', 'model_id'], ['doc_length' => 'v.doc_length']);
            } else {
                // Upserts, not inserts: a concurrent worker's row on the unique key updates instead of failing.
                foreach (array_chunk($postingRows, self::POSTING_ROWS_PER_UPSERT) as $chunk) {
                    DB::table('fuzzy_index_postings')->upsert($chunk, ['term_id', 'model_type_id', 'model_id', 'column_id'], ['frequency', 'doc_length']);
                }
                foreach (array_chunk($documentRows, self::ROWS_PER_UPSERT) as $chunk) {
                    DB::table('fuzzy_index_documents')->upsert($chunk, ['model_type', 'model_id'], ['doc_length']);
                }
            }

            $this->adjustMeta(
                $modelType,
                count(array_diff_key($byModel, $old)) - count(array_diff_key($old, $byModel)),
                array_sum($lengths) - array_sum($old),
                ensure: $byModel !== [],
            );

            return [count($byModel), $elsewhere];
        }, self::ATTEMPTS));

        $this->analyzeUnanalyzedIndex();

        foreach ($refile ? $elsewhere : [] as $type => $typeKeys) {
            $indexed += $this->write($type, $typeKeys, [], $scout, refile: false);
        }

        return $indexed;
    }

    /**
     * The saved rows among $keys as committed now, keyed by id. Loaded through IndexQuery, so a
     * model's searchIndexQuery() eager loads (the relations a searchableText() hook reads) apply
     * to single-row writes as well as to rebuilds. The model's global scopes apply and a trashed
     * row is left out, unless $scout: then no global scope applies, as in Scout's own jobs, and
     * a trashed row is kept while scout.soft_delete is on.
     *
     * $lock reads the rows with shared locks (LOCK IN SHARE MODE): as they are now, not the
     * transaction's snapshot (write()). The relations it eager loads are read without one:
     * a shared read of them took next-key and gap locks in the app's own tables, so an insert of a
     * related row near them (a comment for that post, or its neighbour) waited for the app's commit,
     * for related text that reindexRelated() and a rebuild bring up to date.
     *
     * @param  list<int|string>    $keys
     * @return array<string, Model>
     */
    private function reload(string $modelType, array $keys, bool $scout, bool $lock = false): array
    {
        $keepTrashed = $scout && config('scout.soft_delete', false);
        $rows        = [];

        foreach (array_chunk($keys, 1000) as $chunk) {
            // From the write connection, as are the relations searchIndexQuery() eager loads:
            // under a read/write split this read went to the replica outside a transaction on the
            // model's connection (a queue worker; in-process without `sticky`), and a replica behind
            // the commit gave a deleted row back, or the old text, which this write then indexed.
            $query = IndexQuery::for($modelType)->useWritePdo()->when($lock, fn ($query) => $query->sharedLock());
            $query->setEagerLoads(array_map(fn (\Closure $constraints) => function ($relation) use ($constraints) {
                $constraints($relation->useWritePdo());
            }, $query->getEagerLoads()));
            if ($scout) {
                $query->withoutGlobalScopes();
            }

            // Every read of the model's connection from the write side while the chunk loads: a
            // morphTo eager load runs a fresh query per type, which never sees the relation's
            // useWritePdo() above, and indexed the replica's old parent. The app's own
            // setting comes back after it.
            $connection = $query->getQuery()->getConnection();
            $previous   = $connection instanceof \Illuminate\Database\Connection ? (fn () => $this->readOnWriteConnection)->call($connection) : null;
            if ($previous !== null) {
                $connection->useWriteConnectionWhenReading();
            }
            try {
                $models = $query->whereKey($chunk)->get();
            } finally {
                if ($previous !== null) {
                    $connection->useWriteConnectionWhenReading($previous);
                }
            }

            foreach ($models as $model) {
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
     * Set the dictionary's doc_count of the words $words selects to the sum of the model types' own
     * counts (fuzzy_index_model_terms), where the two differ: the index writes keep that sum
     * and doc_count equal, so this writes only a count something else left wrong. flush()
     * calls it with the flushed type's rows of the words gone, and 2026_10_13_000002 on each range of
     * words it counts, so an index 2.0 or an upgrade's copy left with a count too high or too low, or
     * with a word no posting holds any more, comes out exact. The caller holds the words' rows.
     *
     * @internal public for 2026_10_13_000002
     *
     * @param \Closure(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder $words
     */
    public static function recountDictionary(\Closure $words): void
    {
        $own   = DbDialect::rawIdentifier('fuzzy_index_model_terms');
        $terms = DbDialect::rawIdentifier('fuzzy_index_terms');
        $sum   = "COALESCE((SELECT SUM({$own}.doc_count) FROM {$own} WHERE {$own}.term_id = {$terms}.id), 0)";

        $words(DB::table('fuzzy_index_terms'))->whereRaw("doc_count <> {$sum}")->update(['doc_count' => DB::raw($sum)]);
    }

    /**
     * SQL Server: run an index write the package owns (no transaction of yours open) at
     * DEADLOCK_PRIORITY LOW, and give the session its own priority back after it.
     * Under locking read committed (READ_COMMITTED_SNAPSHOT off), the write's re-read of a row waits
     * on a transaction of yours that updated the row and waits in turn for the index write lock,
     * to index inside it (Scout with after_commit off, IndexModelJob::dispatchSync() in
     * DB::transaction()): SQL Server chose that transaction, or a read of yours in the cycle, as the
     * deadlock victim. At LOW the package's write is the victim, and its attempt is retried
     * (ATTEMPTS) once yours has committed, so it reads the committed row. Inside a transaction of
     * yours the priority is left as it is: the package cannot retry that transaction. Only write()
     * reads your rows (the re-read, its eager loads, a hierarchy's root); removeFromIndex() and the
     * flushes read the index's own tables, which only index writes lock, one at a time.
     * SET runs unprepared: a prepared batch's SET would end with it.
     */
    private static function yieldingDeadlocks(\Closure $write): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== DbDialect::SQLSRV || $connection->transactionLevel() > 0) {
            return $write();
        }

        $own = (int) $connection->scalar('SELECT deadlock_priority FROM sys.dm_exec_sessions WHERE session_id = @@SPID', [], false);
        $connection->unprepared('SET DEADLOCK_PRIORITY LOW');
        try {
            $result = $write();
        } catch (\Throwable $e) {
            try {
                $connection->unprepared("SET DEADLOCK_PRIORITY {$own}");
            } catch (\Throwable) {
                // The write's error is the one to report; a connection that lost this too is gone.
            }
            throw $e;
        }
        $connection->unprepared("SET DEADLOCK_PRIORITY {$own}");

        return $result;
    }

    /**
     * Add term_id => delta (either sign; a result below 0 is floored at 0: unsigned column,
     * concurrent deletes) to doc_count: one UPDATE per 1,000 terms, after a locking read that takes
     * the rows in ascending id order, so every write locks the dictionary rows it touches in the
     * same order. The UPDATE alone would lock them in scan order, which on PostgreSQL is
     * the rows' physical order and moves with every update. A delta of 0 still locks its row: a
     * posting inserted later checks its term row (MySQL's foreign-key S lock), and must find it
     * locked already rather than wait for it behind another write. It is not updated, though: a
     * re-index nets 0 for every word a row keeps, and `doc_count + 0` still wrote a new row
     * version on PostgreSQL.
     * Ids and deltas are inlined as ints; they come from the database and the tokenizer. SQL
     * Server binds them instead (updateByKey()).
     *
     * @param  array<int, int> $deltas
     * @return array<int, string> the words the locking read found, id => term, as they are now
     *                            (termDeltas() copies them); an id it did not find is a term deleted
     *                            since the caller read it, by a flush's orphan sweep
     */
    private function adjustDocCounts(array $deltas): array
    {
        ksort($deltas);
        $words = [];

        foreach (array_chunk($deltas, 1000, true) as $chunk) {
            $found  = DB::table('fuzzy_index_terms')->whereIn('id', array_keys($chunk))->orderBy('id')->lockForUpdate()->pluck('term', 'id')->all();
            $words += $found;
            $chunk  = array_filter(array_intersect_key($chunk, $found)); // locked above; a 0 is not updated
            if ($chunk === []) {
                continue;
            }
            if (DB::connection()->getDriverName() === DbDialect::SQLSRV) {
                self::updateByKey('fuzzy_index_terms', ['id', 'delta'], array_map(fn (int $termId, int $delta) => [$termId, $delta], array_keys($chunk), $chunk), ['id'], ['doc_count' => 'CASE WHEN t.doc_count + v.delta < 0 THEN 0 ELSE t.doc_count + v.delta END']);
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

        return $words;
    }

    /**
     * The changes to a model type's word counts a write makes: term id => [the word as the
     * dictionary stores it, the change], from the words its rows held before ($posted, given back)
     * and hold now ($counts, raised, through $termIds). A word with no id in $termIds gets no
     * posting either (see postingRows()), so it is left out here too, and so is a word held before
     * that a flush's orphan sweep deleted since (not in $words): its rows went with it.
     *
     * @param  array<int, int>    $posted  term id => rows holding it (holders())
     * @param  array<string, int> $counts  term => documents holding it
     * @param  array<string, int> $termIds the dictionary's term => id
     * @param  array<int, string> $words   the dictionary's id => term, for $posted (adjustDocCounts())
     * @return array<int, array{string, int}>
     */
    private static function termDeltas(array $posted, array $counts, array $termIds, array $words): array
    {
        $deltas = [];
        foreach ($posted as $termId => $holders) {
            if (isset($words[$termId])) {
                $deltas[$termId] = [(string) $words[$termId], -$holders];
            }
        }
        $stored = array_flip($termIds);
        foreach ($counts as $term => $increment) {
            if (isset($termIds[$term])) {
                $termId          = $termIds[$term];
                $deltas[$termId] = [(string) $stored[$termId], ($deltas[$termId][1] ?? 0) + $increment];
            }
        }

        return $deltas;
    }

    /**
     * Apply a write's changes to its model type's rows in fuzzy_index_model_terms,
     * as adjustDocCounts() applies them to the dictionary's doc_count: a new row for a word the type
     * did not hold, a change to one it did (floored at 0), and a row that reaches 0 deleted. One
     * upsert per 300 words in term id order (a row binds five values), whatever the changes: the
     * conflict branch picks each row's change by its term_id, `CASE term_id WHEN 7 THEN doc_count + 3
     * WHEN 9 THEN (floored doc_count - 2) … END`, ids and changes inlined as ints, as adjustDocCounts()
     * does. It reads only the existing row, so it needs no reference to the inserted one, which
     * MySQL/MariaDB cannot name alike (see the dictionary's upsert in write()). An upsert per
     * distinct change sent 97 to 100 of them for a 500-row rebuild batch, a fifth of its database
     * time. The inserted row carries the change, or 0 for a decrease; then one DELETE per
     * 1,000 words of the rows a decrease brought to 0.
     *
     * Called once the write holds the dictionary row of every word in $deltas (adjustDocCounts()'s
     * locking read, the dictionary upsert, or its own insert), and every write that changes a type's
     * row of a word holds that word's row first: no two writes change one row at once, and none
     * waits on another here. Every statement finds its rows by the whole primary key: the upsert's
     * conflict and the DELETE's rows (all of them exist once the upsert ran) take record locks only,
     * no gap lock on MySQL/MariaDB, where another write's new row of the type would otherwise wait.
     *
     * SQL Server, under the index write lock: the rows the type holds change by key (updateByKey()),
     * then the raised ones it lacks are inserted (insertMissing()), at the change. A decrease of a
     * row it lacks inserts nothing: elsewhere that row goes in at 0, and the DELETE below takes it.
     *
     * @param array<int, array{string, int}> $deltas see termDeltas()
     */
    private function adjustModelTerms(int $typeId, array $deltas): void
    {
        ksort($deltas);
        $deltas  = array_filter($deltas, fn (array $change) => $change[1] !== 0);
        $lowered = array_keys(array_filter($deltas, fn (array $change) => $change[1] < 0));

        if (DB::connection()->getDriverName() === DbDialect::SQLSRV) {
            $changes = $rows = [];
            foreach ($deltas as $termId => [$term, $delta]) {
                $changes[] = [$typeId, (int) $termId, (int) $delta];
                if ($delta > 0) {
                    $rows[] = [$typeId, (int) $termId, (string) $term, mb_strlen((string) $term), (int) $delta];
                }
            }
            self::updateByKey('fuzzy_index_model_terms', ['model_type_id', 'term_id', 'delta'], $changes, ['model_type_id', 'term_id'], ['doc_count' => 'CASE WHEN t.doc_count + v.delta < 0 THEN 0 ELSE t.doc_count + v.delta END']);
            self::insertMissing('fuzzy_index_model_terms', ['model_type_id', 'term_id', 'term', 'term_length', 'doc_count'], $rows, ['model_type_id', 'term_id']);
        } else {
            // Qualified: see the dictionary's upsert in write().
            $count = DbDialect::rawIdentifier('fuzzy_index_model_terms.doc_count');
            $word  = DbDialect::rawIdentifier('fuzzy_index_model_terms.term_id');
            foreach (array_chunk($deltas, self::POSTING_ROWS_PER_UPSERT, true) as $chunk) {
                $rows  = [];
                $cases = '';
                foreach ($chunk as $termId => [$term, $delta]) {
                    $termId = (int) $termId;
                    $delta  = (int) $delta;
                    $rows[] = ['model_type_id' => $typeId, 'term_id' => $termId, 'term' => $term, 'term_length' => mb_strlen($term), 'doc_count' => max($delta, 0)];
                    $cases .= $delta > 0
                        ? " WHEN {$termId} THEN {$count} + {$delta}"
                        : " WHEN {$termId} THEN CASE WHEN {$count} >= " . -$delta . " THEN {$count} - " . -$delta . ' ELSE 0 END';
                }
                DB::table('fuzzy_index_model_terms')->upsert($rows, ['model_type_id', 'term_id'], ['doc_count' => DB::raw("CASE {$word}{$cases} ELSE {$count} END")]);
            }
        }

        foreach (array_chunk($lowered, 1000) as $chunk) {
            DB::table('fuzzy_index_model_terms')->where('model_type_id', $typeId)->whereIn('term_id', $chunk)->where('doc_count', 0)->delete();
        }
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
     * One posting row per (term, column), each with the document's length, so the ranking reads no
     * document row. Terms missing from $termIds are skipped (an un-migrated *_ci
     * dictionary, or SQL Server's before 2026_09_30_000001, collapsed them into a variant).
     *
     * @param array<string, int> $columnIds column name => id (columnIds())
     */
    private function postingRows(array $byColumn, $termIds, int $typeId, array $columnIds, int|string $modelId, int $length): array
    {
        $rows = [];
        foreach ($byColumn as $column => $terms) {
            foreach ($terms as $term => $frequency) {
                if (!isset($termIds[$term])) {
                    continue;
                }
                $rows[] = [
                    'term_id'       => $termIds[$term],
                    'model_type_id' => $typeId,
                    'model_id'      => (string) $modelId,
                    'column_id'     => $columnIds[(string) $column],
                    'frequency'     => $frequency,
                    'doc_length'    => $length,
                ];
            }
        }
        return $rows;
    }

    /**
     * Add $docs and $tokens (either may be negative, floored at 0) to the model type's meta row
     * and recompute avg_doc_length from the now-consistent totals, inside the enclosing
     * transaction, so no BM25 read sees the totals and the average disagree. $ensure creates the
     * row first (race-safe); a removal only updates a row that exists.
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
     * SQL Server, under the index write lock (lockIndexForWrite()), which every caller holds: insert
     * those of $rows whose $key $table does not hold, in one INSERT … SELECT … WHERE NOT EXISTS per
     * 2,000 bindings (SQL Server takes 2,100), where an upsert sent a MERGE. No other write
     * inserts one of these keys meanwhile. The database compares the keys, in each column's own
     * collation: a case-insensitive model_id finds ABC for abc, as the MERGE did.
     *
     * @param list<string>           $columns the columns of each row, in order
     * @param list<list<int|string>> $rows
     * @param list<string>           $key     the columns that find a row, each one of $columns
     */
    private static function insertMissing(string $table, array $columns, array $rows, array $key): void
    {
        $wrapped = DbDialect::rawIdentifier($table);
        $list    = implode(', ', $columns);
        $match   = implode(' AND ', array_map(fn (string $column) => "t.{$column} = v.{$column}", $key));
        $values  = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, intdiv(2000, count($columns))) as $chunk) {
            DB::insert(
                "INSERT INTO {$wrapped} ({$list}) SELECT {$list} FROM (VALUES " . implode(', ', array_fill(0, count($chunk), $values))
                    . ") AS v ({$list}) WHERE NOT EXISTS (SELECT 1 FROM {$wrapped} AS t WHERE {$match})",
                array_merge(...$chunk)
            );
        }
    }

    /**
     * SQL Server, under the index write lock (lockIndexForWrite()), which every caller holds: set the
     * rows of $table that $rows find by $key, one UPDATE … FROM (VALUES …) per 2,000 bindings. Bound,
     * where the other databases' statements inline a CASE (adjustDocCounts(), adjustModelTerms()):
     * the statement's text depends on its row count only, so SQL Server compiles it once per count,
     * not once per statement.
     *
     * @param list<string>           $columns the columns of each row, in order: v.<column> in $set
     * @param list<list<int|string>> $rows
     * @param list<string>           $key     the columns that find a row, each one of $columns
     * @param array<string, string>  $set     column => its new value, over t (the row) and v
     */
    private static function updateByKey(string $table, array $columns, array $rows, array $key, array $set): void
    {
        $assign = implode(', ', array_map(fn (string $column, string $value) => "{$column} = {$value}", array_keys($set), $set));
        $match  = implode(' AND ', array_map(fn (string $column) => "t.{$column} = v.{$column}", $key));
        $values = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, intdiv(2000, count($columns))) as $chunk) {
            DB::update(
                "UPDATE t SET {$assign} FROM " . DbDialect::rawIdentifier($table) . ' AS t INNER JOIN (VALUES ' . implode(', ', array_fill(0, count($chunk), $values))
                    . ') AS v (' . implode(', ', $columns) . ") ON {$match}",
                array_merge(...$chunk)
            );
        }
    }

    /**
     * SQL Server: whether $e is a duplicate key (2601/2627) that failed its own statement only, as
     * it does while XACT_ABORT is off (@@OPTIONS & 16384 = 0, SQL Server's default): then only the
     * failed statement rolls back, and the write keeps its transaction and its locks. With
     * XACT_ABORT on, the error has rolled back the whole write, and the driver silently opens a new
     * transaction at the next statement, so going on would commit the rest of the write without its
     * start: the error is rethrown instead (insertDictionary()), the write fails and rolls back
     * whole, and the queue retries it.
     */
    private static function duplicateKey(\Throwable $e): bool
    {
        return $e instanceof \Illuminate\Database\QueryException
            && in_array($e->errorInfo[1] ?? null, [2601, 2627], true)
            && DB::connection()->getDriverName() === 'sqlsrv'
            && (int) DB::scalar('select @@options & 16384') === 0;
    }

    /**
     * Guarantee the meta row exists without clobbering its counters.
     *
     * upsert() on MySQL/MariaDB (ON DUPLICATE KEY), PostgreSQL and SQLite (ON CONFLICT); on SQL
     * Server, under the index write lock, insertMissing(). insertOrIgnore() is not portable:
     * Laravel's SqlServerGrammar throws. Updating model_type to itself makes the conflict branch a no-op.
     */
    private function ensureMetaRow(string $modelType): void
    {
        if (DB::connection()->getDriverName() === DbDialect::SQLSRV) {
            self::insertMissing('fuzzy_index_meta', ['model_type', 'total_docs', 'total_tokens', 'avg_doc_length'], [[$modelType, 0, 0, 0]], ['model_type']);

            return;
        }

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
