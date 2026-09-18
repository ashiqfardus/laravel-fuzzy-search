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

        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');

        if ($async) {
            IndexModelJob::dispatch($model::class, $model->getKey())->onQueue($queue);
        } else {
            app(IndexManager::class)->removeFromIndex($model::class, $model->getKey());
        }
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
        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');

        if ($async) {
            IndexModelJob::dispatch($model::class, $model->getKey())->onQueue($queue);
            return;
        }

        // Run the job in-process rather than indexing $model directly: the job reloads the
        // row from the database, so accessors that read relations see the current related
        // rows instead of whatever was already loaded on this instance before the change.
        (new IndexModelJob($model::class, $model->getKey()))->handle(app(IndexManager::class));
    }
}
