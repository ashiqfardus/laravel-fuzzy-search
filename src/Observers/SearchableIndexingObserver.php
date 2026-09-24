<?php

namespace Ashiqfardus\LaravelFuzzySearch\Observers;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Illuminate\Database\Eloquent\Model;

class SearchableIndexingObserver
{
    public function saved(Model $model): void
    {
        if (!config('fuzzy-search.indexing.enabled', false)) {
            return;
        }

        $columns = $model->getSearchableColumns();

        if (empty($columns)) {
            return;
        }

        if (!$model->wasRecentlyCreated && !$this->needsReindex($model, $columns)) {
            return; // nothing the index depends on changed — skip dispatch
        }

        $this->reindex($model);
    }

    public function deleted(Model $model): void
    {
        if (!config('fuzzy-search.indexing.enabled', false)) {
            return;
        }

        if (empty($model->getSearchableColumns())) {
            return;
        }

        $class = $model::class;
        $key   = $model->getKey();
        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');

        $this->afterCommit($model, function () use ($class, $key, $async, $queue) {
            if ($async) {
                IndexModelJob::dispatch($class, $key)->onQueue($queue);
            } else {
                app(IndexManager::class)->removeFromIndex($class, $key);
            }
        });
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

    protected function reindex(Model $model): void
    {
        $class = $model::class;
        $key   = $model->getKey();
        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');

        $this->afterCommit($model, function () use ($class, $key, $async, $queue) {
            if ($async) {
                IndexModelJob::dispatch($class, $key)->onQueue($queue);
                return;
            }

            // Run the job in-process rather than indexing $model directly: the job reloads the
            // row from the database, so accessors that read relations see the current related
            // rows instead of whatever was already loaded on this instance before the change.
            (new IndexModelJob($class, $key))->handle(app(IndexManager::class));
        });
    }

    /**
     * Run $callback once the model's transaction commits, at once outside a transaction.
     * A rolled-back transaction discards it, so a rolled-back row is never indexed (the index may
     * sit on another connection, whose writes the rollback would not undo), and a queued job is
     * never pushed before the row it reloads is committed, where a worker could run it first.
     *
     * An error in it (a deadlock or lock-wait timeout on the sync path, an unreachable queue) is
     * reported, not thrown: the caller's write has committed, and a throw here would also skip
     * the application's own after-commit callbacks. fuzzy-search:rebuild repairs the index.
     */
    private function afterCommit(Model $model, \Closure $callback): void
    {
        $model->getConnection()->afterCommit(function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
