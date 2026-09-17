<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\Concerns\ConfiguresRetryLimits;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexModelJob implements ShouldQueue
{
    use ConfiguresRetryLimits, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string     $modelClass,
        public readonly int|string $modelId,
    ) {
        $this->configureRetryLimits();
    }

    public function handle(IndexManager $indexManager): void
    {
        // Load through IndexQuery so a model's searchIndexQuery() eager loads (e.g. the
        // relations a searchableText() hook reads) apply to single-row reindexes too, not
        // just bulk rebuilds. Use withTrashed() when available so SoftDeletes models are not
        // silently excluded by the global scope. A soft-deleted model must be removed from
        // the index, not re-indexed. A restored model (trashed=false) is re-indexed.
        $query = \Ashiqfardus\LaravelFuzzySearch\Support\IndexQuery::for($this->modelClass);
        if (method_exists($this->modelClass, 'withTrashed')) {
            $query->withTrashed();
        }

        $model = $query->find($this->modelId);

        if ($model === null || (method_exists($model, 'trashed') && $model->trashed())) {
            $indexManager->removeFromIndex($this->modelClass, $this->modelId);
            return;
        }

        $indexManager->indexModel($model);
    }
}
