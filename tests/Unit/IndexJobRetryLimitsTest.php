<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * Index jobs declared no $tries / $backoff / $timeout, so a failing index write retried
 * on whatever the worker's defaults were (often forever) with no backoff between attempts.
 */
class IndexJobRetryLimitsTest extends TestCase
{
    public function test_index_model_job_reads_retry_limits_from_config(): void
    {
        config(['fuzzy-search.indexing.job' => ['tries' => 5, 'backoff' => [1, 2], 'timeout' => 30]]);

        $job = new IndexModelJob('App\\Models\\User', 1);

        $this->assertSame(5, $job->tries);
        $this->assertSame([1, 2], $job->backoff);
        $this->assertSame(30, $job->timeout);
    }

    public function test_rebuild_index_job_reads_retry_limits_from_config(): void
    {
        config(['fuzzy-search.indexing.job' => ['tries' => 2, 'backoff' => 15, 'timeout' => 600]]);

        $job = new RebuildIndexJob('App\\Models\\User', [1, 2, 3]);

        $this->assertSame(2, $job->tries);
        $this->assertSame(15, $job->backoff);
        $this->assertSame(600, $job->timeout);
    }

    public function test_jobs_fall_back_to_sane_defaults_when_config_is_missing(): void
    {
        config(['fuzzy-search.indexing.job' => null]);

        $job = new IndexModelJob('App\\Models\\User', 1);

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff);
        $this->assertSame(120, $job->timeout);
    }

    public function test_published_config_declares_the_job_limits(): void
    {
        $config = require __DIR__ . '/../../config/fuzzy-search.php';

        $this->assertSame(3, $config['indexing']['job']['tries']);
        $this->assertSame([10, 60, 300], $config['indexing']['job']['backoff']);
        $this->assertSame(120, $config['indexing']['job']['timeout']);
    }
}
