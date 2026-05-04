<?php

namespace Ashiqfardus\LaravelFuzzySearch\Observers;

use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Illuminate\Database\Eloquent\Model;

class SearchableIndexingObserver
{
    public function saved(Model $model): void
    {
        if (!config('fuzzy-search.indexing.enabled', false)) {
            return;
        }

        if (empty($model->getSearchableColumns())) {
            return;
        }

        $searchableCols = method_exists($model, 'getSearchableColumns')
            ? $model->getSearchableColumns()
            : [];

        if (!empty($searchableCols) && !$model->wasRecentlyCreated && !$model->wasChanged($searchableCols)) {
            return; // none of the indexed columns changed — skip dispatch
        }

        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');

        if ($async) {
            IndexModelJob::dispatch($model::class, $model->getKey())->onQueue($queue);
        } else {
            app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)->indexModel($model);
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

        $async = config('fuzzy-search.indexing.async', true);
        $queue = config('fuzzy-search.indexing.queue', 'default');

        if ($async) {
            IndexModelJob::dispatch($model::class, $model->getKey())->onQueue($queue);
        } else {
            app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)
                ->removeFromIndex($model::class, $model->getKey());
        }
    }
}
