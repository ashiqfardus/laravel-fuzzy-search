<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class IndexModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string     $modelClass,
        public readonly int|string $modelId,
    ) {}

    public function handle(IndexManager $indexManager): void
    {
        $model = $this->modelClass::find($this->modelId);

        if ($model === null) {
            $indexManager->removeFromIndex($this->modelClass, $this->modelId);
            return;
        }

        $indexManager->indexModel($model);
    }
}
