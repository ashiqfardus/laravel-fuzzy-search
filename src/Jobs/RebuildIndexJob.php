<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
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
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $modelClass,
        public readonly array  $modelIds,
    ) {}

    public function handle(IndexManager $indexManager): void
    {
        $keyName = (new $this->modelClass)->getKeyName();
        $models  = $this->modelClass::whereIn($keyName, $this->modelIds)->get();
        $indexManager->indexBatch($models);
    }
}
