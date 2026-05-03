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
        // Use withTrashed() when available so SoftDeletes models are not silently
        // excluded by the global scope. A soft-deleted model must be removed from
        // the index, not re-indexed. A restored model (trashed=false) is re-indexed.
        $query = method_exists($this->modelClass, 'withTrashed')
            ? $this->modelClass::withTrashed()
            : $this->modelClass::query();

        $model = $query->find($this->modelId);

        if ($model === null || (method_exists($model, 'trashed') && $model->trashed())) {
            $indexManager->removeFromIndex($this->modelClass, $this->modelId);
            return;
        }

        $indexManager->indexModel($model);
    }
}
