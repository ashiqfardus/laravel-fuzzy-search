<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** A job of a cancelled rebuild batch writes nothing: cancelling stops nothing by itself, each job checks. */
class CancelledRebuildBatchTest extends TestCase
{
    public function test_a_job_of_a_cancelled_batch_indexes_nothing(): void
    {
        [$job] = (new RebuildIndexJob(User::class, User::pluck('id')->all()))
            ->withFakeBatch(cancelledAt: CarbonImmutable::now());

        $job->handle(app(IndexManager::class));

        $this->assertSame(0, DB::table('fuzzy_index_documents')->count());
        $this->assertSame(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_a_job_of_a_live_batch_or_of_none_indexes_its_rows(): void
    {
        [$job] = (new RebuildIndexJob(User::class, User::pluck('id')->all()))->withFakeBatch();
        $job->handle(app(IndexManager::class));
        $this->assertSame(User::count(), DB::table('fuzzy_index_documents')->count());

        $this->app->make(IndexManager::class)->flush(User::class);
        (new RebuildIndexJob(User::class, User::pluck('id')->all()))->handle(app(IndexManager::class));
        $this->assertSame(User::count(), DB::table('fuzzy_index_documents')->count());
    }
}
