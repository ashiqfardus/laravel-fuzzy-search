<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\Concerns\ConfiguresRetryLimits;
use Ashiqfardus\LaravelFuzzySearch\Support\IndexQuery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class IndexModelJob implements ShouldQueue
{
    use ConfiguresRetryLimits, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string     $modelClass,
        public readonly int|string $modelId,
    ) {
        $this->configureRetryLimits();
    }

    /**
     * One run per model at a time. Two runs that overlap both read the model as not yet indexed
     * and both add it to doc_count and total_docs. A second run waits for the first, up to the
     * job's timeout, rather than being dropped: it reloads the row, so the latest save is what
     * ends up indexed. Held on the default cache store; one without locks (apc) runs unlocked.
     */
    public function handle(IndexManager $indexManager): void
    {
        if (!Cache::getStore() instanceof LockProvider) {
            $this->index($indexManager);
            return;
        }

        Cache::lock($this->lockKey(), $this->timeout)->block($this->timeout, fn () => $this->index($indexManager));
    }

    /** @internal The cache lock a run holds: one per model class and key. */
    public function lockKey(): string
    {
        return 'fuzzy-search:index:' . sha1($this->modelClass . '|' . $this->modelId);
    }

    private function index(IndexManager $indexManager): void
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
