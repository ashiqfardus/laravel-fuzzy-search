<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * rebuild --async dispatches a job batch, which Laravel stores in the job_batches table. Without
 * it the command fails before touching the index (--fresh included) and says how to create it.
 */
class RebuildAsyncBatchTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel keeps batches on queue.batching.database; point it at the test connection.
        config(['queue.batching.database' => config('database.default')]);
        Schema::dropIfExists('job_batches');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('job_batches');

        parent::tearDown();
    }

    public function test_async_rebuild_without_the_job_batches_table_fails_and_leaves_the_index_alone(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $postings = DB::table('fuzzy_index_postings')->count();

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--async' => true, '--fresh' => true])
            ->expectsOutputToContain('php artisan make:queue-batches-table (Laravel 10: php artisan queue:batches-table)')
            ->assertExitCode(1);

        $this->assertSame($postings, DB::table('fuzzy_index_postings')->count()); // --fresh did not flush
    }

    public function test_async_rebuild_runs_once_the_table_exists(): void
    {
        // The schema Laravel's make:queue-batches-table migration creates.
        Schema::create('job_batches', function (Blueprint $table) {
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
        config(['queue.default' => 'sync']); // the batch's jobs run as it is dispatched

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--async' => true])->assertExitCode(0);

        $this->assertSame(User::count(), DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
    }
}
