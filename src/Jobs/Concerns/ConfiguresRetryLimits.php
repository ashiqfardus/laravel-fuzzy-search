<?php

namespace Ashiqfardus\LaravelFuzzySearch\Jobs\Concerns;

/**
 * Reads $tries / $backoff / $timeout for the indexing jobs from
 * config('fuzzy-search.indexing.job') so a failing index write is retried a bounded
 * number of times with a delay between attempts instead of on the worker's defaults.
 */
trait ConfiguresRetryLimits
{
    /** Number of attempts before the job is marked failed. */
    public int $tries;

    /** Seconds to wait before retrying; an array gives per-attempt delays. */
    public int|array $backoff;

    /** Seconds the job may run before the worker kills it. */
    public int $timeout;

    protected function configureRetryLimits(): void
    {
        $job = config('fuzzy-search.indexing.job') ?? [];

        $this->tries   = (int) ($job['tries'] ?? 3);
        $this->backoff = $job['backoff'] ?? [10, 60, 300];
        $this->timeout = (int) ($job['timeout'] ?? 120);
    }
}
