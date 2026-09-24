<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Two IndexModelJobs for one model must not overlap: both read the row as not yet indexed and
 * both added it to doc_count and total_docs. Each run holds a cache lock keyed by the model's
 * class and key; a second run waits for it (up to the job's timeout) instead of running alongside.
 */
class IndexModelJobOverlapTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'postings'  => DB::table('fuzzy_index_postings')->where('model_type', User::class)->orderBy('term_id')->orderBy('column_name')->get(['term_id', 'model_id', 'column_name', 'frequency'])->map(fn ($r) => (array) $r)->all(),
            'doc_count' => DB::table('fuzzy_index_terms')->orderBy('term')->pluck('doc_count', 'term')->map(fn ($c) => (int) $c)->all(),
            'meta'      => (array) DB::table('fuzzy_index_meta')->where('model_type', User::class)->first(['total_docs', 'total_tokens']),
        ];
    }

    public function test_running_the_job_twice_leaves_the_index_as_one_run_does(): void
    {
        $user = User::where('name', 'John Doe')->first();

        (new IndexModelJob(User::class, $user->getKey()))->handle(app(IndexManager::class));
        $once = $this->snapshot();

        (new IndexModelJob(User::class, $user->getKey()))->handle(app(IndexManager::class));
        $this->assertSame($once, $this->snapshot());
        $this->assertSame(1, (int) $once['meta']['total_docs']);
    }

    /**
     * The race itself, in two processes: each run pauses after reading whether the model is
     * already indexed, so both read "not yet" unless the second waits for the first.
     */
    public function test_two_concurrent_runs_for_one_model_count_it_once(): void
    {
        if (!$this->usingRealDatabase()) {
            $this->markTestSkipped('Two processes cannot share an in-memory SQLite database; CI runs this on MySQL, MariaDB, PostgreSQL and SQL Server.');
        }

        $dir = sys_get_temp_dir() . '/fuzzy-overlap-' . getmypid();
        config([
            'cache.default'           => 'file', // a lock both processes see
            'cache.stores.file.path'  => $dir,
            'database.connections.race' => config('database.connections.' . config('database.default')),
        ]);

        DB::listen(function ($query) {
            if (str_contains($query->sql, 'fuzzy_index_documents') && str_contains($query->sql, 'doc_length') && str_starts_with(strtolower($query->sql), 'select')) {
                usleep(600_000); // after the "already indexed?" read, before any write
            }
        });

        $id     = User::where('name', 'John Doe')->value('id');
        $failed = "{$dir}/child-failed";

        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::setDefaultConnection('race'); // its own connection: the parent's socket is not shared
                (new IndexModelJob(User::class, $id))->handle(app(IndexManager::class));
            } catch (\Throwable $e) {
                @mkdir($dir, 0777, true);
                file_put_contents($failed, (string) $e);
            }
            posix_kill(getmypid(), SIGKILL); // no destructors: they would close the parent's connection
        }

        usleep(50_000);
        (new IndexModelJob(User::class, $id))->handle(app(IndexManager::class));
        pcntl_waitpid($pid, $status);

        $childError = is_file($failed) ? file_get_contents($failed) : null;
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($dir);

        $this->assertNull($childError);
        $this->assertSame(1, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));
    }

    public function test_a_run_waits_while_another_run_holds_the_model_and_does_not_index_alongside_it(): void
    {
        config(['fuzzy-search.indexing.job.timeout' => 1]); // how long the second run waits
        $user = User::where('name', 'John Doe')->first();
        $job  = new IndexModelJob(User::class, $user->getKey());

        $held = Cache::lock($job->lockKey(), 10);
        $this->assertTrue($held->get()); // a first run is in progress

        try {
            $job->handle(app(IndexManager::class));
            $this->fail('the second run should have waited for the lock');
        } catch (LockTimeoutException) {
            // a queued job is retried later; nothing ran alongside the first run
        } finally {
            $held->release();
        }

        $this->assertSame(0, DB::table('fuzzy_index_postings')->count());

        // Another model is not held up by it, and the released lock lets the model through.
        $jane = User::where('name', 'Jane Doe')->first();
        $held = Cache::lock($job->lockKey(), 10);
        $held->get();
        (new IndexModelJob(User::class, $jane->getKey()))->handle(app(IndexManager::class));
        $held->release();
        $job->handle(app(IndexManager::class));

        $this->assertSame(2, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
    }
}
