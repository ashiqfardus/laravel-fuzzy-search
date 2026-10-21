<?php

namespace Ashiqfardus\LaravelFuzzySearch\Observers;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\LaravelFuzzySearchException;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

class SearchableIndexingObserver
{
    /** @var \WeakMap<Model, int|string> the key a row had before a save that changes it (read at updating, since saved() sees the new one as the original) */
    private static ?\WeakMap $oldKeys = null; // static: "Class@method" listeners get a new observer per event

    public function updating(Model $model): void
    {
        if ($model->isDirty($model->getKeyName()) && $model->getOriginal($model->getKeyName()) !== null) {
            self::$oldKeys ??= new \WeakMap();
            self::$oldKeys[$model] = $model->getOriginal($model->getKeyName());
        }
    }

    public function saved(Model $model): void
    {
        if (!config('fuzzy-search.indexing.enabled', false)) {
            return;
        }

        $columns = $model->getSearchableColumns();

        if (empty($columns)) {
            return;
        }

        // A save that changes the primary key indexes the new key, and syncs the old one too: its
        // sync finds no row under that key and takes the document and postings out, which nothing
        // else would (a rebuild reads only the rows that exist).
        $oldKey     = self::$oldKeys[$model] ?? null;
        $keyChanged = $oldKey !== null && $model->wasChanged($model->getKeyName());
        unset(self::$oldKeys[$model]);

        if (!$model->wasRecentlyCreated && !$keyChanged && !$this->needsReindex($model, $columns)) {
            return; // nothing the index depends on changed — skip dispatch
        }

        $this->reindex($model);

        if ($keyChanged) {
            $this->reindex($model, $oldKey);
        }
    }

    public function deleted(Model $model): void
    {
        if (!config('fuzzy-search.indexing.enabled', false)) {
            return;
        }

        if (empty($model->getSearchableColumns())) {
            return;
        }

        // What a save runs: the job removes the row only if it is still gone or trashed when it reads
        // it under its claim. A removal that did not read it, run after a restore had committed and
        // indexed the row, took the live row out of the index.
        $this->reindex($model);
    }

    /**
     * Decide whether an update touched anything the index is built from.
     *
     * Searchable fields are often accessors (a brand name read through a relation). Those
     * never appear in wasChanged(), so a model that only lists computed fields would never
     * reindex after an update. Two rules cover this:
     *
     *  - If the model declares `$searchable['reindex_on']`, those real columns are gated
     *    alongside the searchable columns: `wasChanged(columns + reindex_on)`.
     *  - Otherwise, if any searchable field is not a loaded attribute (accessor, or a
     *    column excluded by select()), the observer cannot tell whether it changed and
     *    reindexes on every save.
     */
    protected function needsReindex(Model $model, array $columns): bool
    {
        // restore() saves the model: a soft delete removed it from the index, so put it back.
        // Ask the model for the column — DELETED_AT can be renamed.
        if (method_exists($model, 'getDeletedAtColumn') && $model->wasChanged($model->getDeletedAtColumn())) {
            return true;
        }

        // A save that changes the class the row hydrates as (single-table inheritance's type column)
        // moves it to another index type, though no searchable column changed.
        if (IndexManager::rowClass($model) !== IndexManager::rowClass($model, $model->getRawOriginal())) {
            return true;
        }

        $triggers   = method_exists($model, 'getReindexTriggers') ? $model->getReindexTriggers() : [];
        $attributes = $model->getAttributes();

        $hasComputedField = false;
        foreach ($columns as $column) {
            if (!array_key_exists($column, $attributes)) {
                $hasComputedField = true;
                break;
            }
        }

        if ($hasComputedField && empty($triggers)) {
            return true;
        }

        return $model->wasChanged(array_values(array_unique(array_merge($columns, $triggers))));
    }

    protected function reindex(Model $model, int|string|null $key = null): void
    {
        $row = clone $model; // as saved or deleted: a later save can change this instance before the commit

        // The types are resolved after the commit, so a searchIndexType() that names no valid type
        // is reported like any failed index write, not thrown from the save. Named by the
        // row's kind, now and before the save, not by the instance's class alone: under single-table
        // inheritance a parent instance can hold a child row, which was filed under the child
        // and stayed there when the instance was deleted, and a type change through the
        // child's instance left the child's index and joined no other.
        self::indexAfterCommit($model->getConnection(), fn () => IndexManager::rowTypes($row), [$key ?? $model->getKey()]); // $key: the old key of a key change
    }

    /**
     * Index each of $keys under each type $types() returns, once $connection's transaction commits
     * (see afterCommit()): queued when indexing.async is on, else run in-process. A failed write
     * (a deadlock, an unreachable queue) is reported naming the model and key, and the rest go on.
     *
     * @internal Searchable::reindexRelated() schedules its rows through it too.
     *
     * @param \Closure(): iterable<int, string> $types
     * @param array<int, int|string>            $keys
     */
    public static function indexAfterCommit(Connection $connection, \Closure $types, array $keys): void
    {
        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');

        self::afterCommit($connection, function () use ($types, $keys, $async, $queue) {
            foreach ($types() as $type) {
                foreach ($keys as $key) {
                    try {
                        if ($async) {
                            IndexModelJob::dispatch($type, $key)->onQueue($queue);
                            continue;
                        }

                        // Run the job in-process rather than indexing a model directly: the job reloads
                        // the row from the database, so accessors that read relations see the current
                        // related rows instead of whatever was already loaded on an instance before the
                        // change, and a deleted row leaves the index only if it is still gone or trashed.
                        (new IndexModelJob($type, $key))->handle(app(IndexManager::class));
                    } catch (\Throwable $e) {
                        // Named, so the row can be repaired on its own, without a rebuild of the whole
                        // model: a deadlock's error names neither.
                        report(new LaravelFuzzySearchException(
                            "Indexing {$type} {$key} failed: {$e->getMessage()} IndexModelJob::dispatch() of that type and key repairs its index entry, and removes it if the row is gone.",
                            ['model' => $type, 'key' => $key],
                            0,
                            $e,
                        ));
                    }
                }
            }
        });
    }

    /**
     * Run $callback once $connection's transaction commits, at once when it has none open.
     * A rolled-back transaction discards it, so a rolled-back row is never indexed (the index may
     * sit on another connection, whose writes the rollback would not undo), and a queued job is
     * never pushed before the row it reloads is committed, where a worker could run it first.
     *
     * Only a transaction of $connection's own: Laravel attaches an after-commit callback to the
     * newest open transaction of any connection, so a save committed at once on this one, while
     * another connection had a transaction open, was indexed only when that one committed, and
     * never if it rolled back; and a save inside this connection's transaction, with another
     * connection's opened inside it, was indexed when that inner one committed, while the save's
     * own could still roll back. The callback goes to the newest transaction of $connection that
     * Laravel's manager applies callbacks to (callbackApplicableTransactions(), Laravel 10.0 on:
     * RefreshDatabase's wrapping transaction is not one, so a test runs it at once, as Laravel
     * does), and runs at once when there is none.
     *
     * An error in it (a deadlock or lock-wait timeout on the sync path, an unreachable queue) is
     * reported, not thrown: the caller's write has committed, and a throw here would also skip
     * the application's own after-commit callbacks. indexAfterCommit() reports a failed write
     * naming the model and key, which IndexModelJob::dispatch() repairs.
     */
    private static function afterCommit(Connection $connection, \Closure $callback): void
    {
        $run = function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e);
            }
        };

        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;

        if ($manager === null || !method_exists($manager, 'callbackApplicableTransactions')) {
            $connection->transactionLevel() > 0 ? $connection->afterCommit($run) : $run();

            return;
        }

        $transaction = $manager->callbackApplicableTransactions()->where('connection', $connection->getName())->last();
        $transaction === null ? $run() : $transaction->addCallback($run);
    }
}
