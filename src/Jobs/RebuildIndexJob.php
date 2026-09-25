<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\Concerns\ConfiguresRetryLimits;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Indexes a chunk of models. Dispatched in batches by RebuildCommand.
 */
class RebuildIndexJob implements ShouldQueue
{
    use Batchable, ConfiguresRetryLimits, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $modelClass,
        public readonly array  $modelIds,
    ) {
        $this->configureRetryLimits();
    }

    public function handle(IndexManager $indexManager): void
    {
        $keyName = (new $this->modelClass)->getKeyName();
        $models  = \Ashiqfardus\LaravelFuzzySearch\Support\IndexQuery::for($this->modelClass)
            ->whereIn($keyName, $this->modelIds)
            ->get();
        $indexManager->indexBatch($models);

        // Fill *_metaphone shadow columns for rows saved before they existed, as the sync rebuild does.
        $shadows = app(\Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver::class);
        $shadows->backfillShadowColumns($models);
    }
}
