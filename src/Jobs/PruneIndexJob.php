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
 * The last job of a plain `fuzzy-search:rebuild --async` batch: removes the documents of rows that
 * are gone (IndexManager::prune()), $chunks chunks of 1,000 documents at a time (CHUNKS by
 * default), each step within the job timeout, and adds the next step to the batch until every
 * document was read. A cancelled batch stops it.
 */
class PruneIndexJob implements ShouldQueue
{
    use Batchable, ConfiguresRetryLimits, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Chunks of 1,000 documents a step reads: 20,000 documents, a keyed read of the table per 1,000. */
    public const CHUNKS = 20;

    public function __construct(
        public readonly string  $modelClass,
        public readonly ?string $after = null,
        public readonly int     $chunks = self::CHUNKS,
    ) {
        $this->configureRetryLimits();
    }

    public function handle(IndexManager $indexManager): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $after = $this->after;
        $indexManager->prune($this->modelClass, $after, $this->chunks);

        if ($after !== null) {
            $next = new self($this->modelClass, $after, $this->chunks);
            $this->batch() !== null ? $this->batch()->add([$next]) : dispatch($next->onQueue($this->queue));
        }
    }
}
