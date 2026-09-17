<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\Concerns\ConfiguresRetryLimits;
use Ashiqfardus\LaravelFuzzySearch\Support\IndexQuery;
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
        // just bulk rebuilds. A SoftDeletes model's global scope already hides trashed
        // rows, so find() returns null for one and it falls into the removeFromIndex()
        // branch below like any other missing row.
        $model = IndexQuery::for($this->modelClass)->find($this->modelId);

        if ($model === null || (method_exists($model, 'trashed') && $model->trashed())) {
            $indexManager->removeFromIndex($this->modelClass, $this->modelId);
            return;
        }

        $indexManager->indexModel($model);
    }
}
