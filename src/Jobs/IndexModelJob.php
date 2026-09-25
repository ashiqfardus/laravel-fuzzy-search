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
        // The row is read after its index entry is claimed, not before: a job that loaded it
        // ahead of a newer save and committed after that save's index write would index the
        // older text. A missing or soft-deleted row leaves the index (ER-68).
        $indexManager->syncModel($this->modelClass, $this->modelId);
    }
}
