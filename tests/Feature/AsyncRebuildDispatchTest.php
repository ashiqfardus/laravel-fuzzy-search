<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RC-2. rebuild --async handed every job to one Bus::batch()->dispatch(), and Laravel's database
 * queue inserts a batch's jobs in one statement, six bindings a job: past the driver's bound-parameter
 * limit (350 jobs on SQL Server, 5,461 on SQLite, 10,922 on MySQL, MariaDB and PostgreSQL) the
 * insert failed, after --fresh had flushed the index, with nothing queued. The jobs now go out in
 * slices that stay under SQL Server's 2,100 parameters, the lowest limit. With chunk_size 1 each row
 * is a job, so 351 rows are one job past SQL Server's limit; on the other drivers the test checks
 * that no statement binds more than SQL Server would take.
 */
class AsyncRebuildDispatchTest extends TestCase
{
    private const ROWS = 351;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['rc2_jobs', 'rc2_job_batches'] as $table) {
            Schema::dropIfExists($table);
        }
        // The schemas Laravel's make:queue-table and make:queue-batches-table migrations create.
        Schema::create('rc2_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('rc2_job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        $rows = array_map(fn ($i) => ['name' => "Zebra {$i}", 'email' => "z{$i}@example.test"], range(1, self::ROWS - User::count()));
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        config([
            'queue.default'                    => 'database',
            'queue.connections.database'       => ['driver' => 'database', 'table' => 'rc2_jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.batching'                   => ['database' => config('database.default'), 'table' => 'rc2_job_batches'],
            'fuzzy-search.indexing.chunk_size' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['rc2_jobs', 'rc2_job_batches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /** Run every queued job, as a worker would. */
    private function work(): void
    {
        $queue = app('queue')->connection('database');
        while ($job = $queue->pop('default')) {
            $job->fire();
        }
    }

    public function test_a_fresh_async_rebuild_queues_every_job_and_the_jobs_rebuild_the_index(): void
    {
        config(['queue.default' => 'sync', 'fuzzy-search.indexing.chunk_size' => 500]);
        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);
        $this->assertSame(self::ROWS, DB::table('fuzzy_index_documents')->count());
        config(['queue.default' => 'database', 'fuzzy-search.indexing.chunk_size' => 1]);

        $bindings = 0;
        DB::listen(function ($query) use (&$bindings) {
            $bindings = max($bindings, count($query->bindings));
        });

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true, '--async' => true])
            ->expectsOutputToContain('Jobs: ' . self::ROWS . ' × 1 records')
            ->assertExitCode(0);

        $this->assertLessThanOrEqual(2100, $bindings, 'a statement bound more parameters than SQL Server takes');
        $this->assertSame(self::ROWS, DB::table('rc2_jobs')->count());
        $batch = DB::table('rc2_job_batches')->first();
        $this->assertSame([self::ROWS, self::ROWS], [(int) $batch->total_jobs, (int) $batch->pending_jobs]);
        $this->assertSame(0, DB::table('fuzzy_index_documents')->count()); // flushed, nothing run yet

        $this->work();

        $this->assertSame(self::ROWS, DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
        $this->assertSame(self::ROWS, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
        $this->assertSame(0, (int) DB::table('rc2_job_batches')->value('pending_jobs'));
        $this->assertNotNull(DB::table('rc2_job_batches')->value('finished_at'));
    }

    /** A dispatch that fails part way (here the second slice's insert) says what was queued and how to recover. */
    public function test_a_dispatch_that_fails_part_way_says_how_to_recover(): void
    {
        $inserts = 0;
        DB::connection()->beforeExecuting(function (string $query) use (&$inserts) {
            if (preg_match('/^insert into \W?rc2_jobs\W? /i', $query) && ++$inserts === 2) {
                throw new \RuntimeException('the queue is gone');
            }
        });

        $code   = Artisan::call('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true, '--async' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('the queue is gone', $output);
        $this->assertStringContainsString('fuzzy-search:rebuild', $output);
        $this->assertSame(DB::table('rc2_jobs')->count(), (int) DB::table('rc2_job_batches')->value('total_jobs'));
        $this->assertGreaterThan(0, DB::table('rc2_jobs')->count());
        $this->assertLessThan(self::ROWS, DB::table('rc2_jobs')->count());
    }
}
