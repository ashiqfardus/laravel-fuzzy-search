<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A1 (round 8), ruling ER-106. On index tables PostgreSQL has never analyzed, the planner takes
 * about one posting per term, and the postings subquery of a constrained or ordered index search
 * ran as a nested loop over every posting for every row: 60–120 s at 50k rows, until autovacuum's
 * first ANALYZE. The first index write into such tables, and every rebuild, now analyze them. The
 * assertions read pg_class.reltuples, never a timing.
 */
class PostgresIndexStatisticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('pg_class.reltuples and this ANALYZE are PostgreSQL\'s; the CI PostgreSQL jobs run this.');
        }

        $rows = array_map(fn ($i) => ['name' => "Zebra {$i}", 'email' => "z{$i}@example.test"], range(1, 300));
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }
    }

    protected function tearDown(): void
    {
        if ($this->dbDriver === 'pgsql') {
            Schema::dropIfExists('job_batches');
        }

        parent::tearDown();
    }

    /** pg_class's row estimate for $table: -1 (PostgreSQL 14+) until it is analyzed, 0 when it was analyzed empty. */
    private function reltuples(string $table): float
    {
        return (float) DB::selectOne('select reltuples from pg_class where oid = to_regclass(?)', [DB::connection()->getQueryGrammar()->wrapTable($table)])->reltuples;
    }

    public function test_the_first_index_write_into_unanalyzed_tables_analyzes_them(): void
    {
        $this->assertLessThanOrEqual(0, $this->reltuples('fuzzy_index_postings'), 'the migrations leave the postings unanalyzed');

        app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', 'Zebra%')->get());

        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_postings'));
        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_terms'));
        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_documents'));
    }

    /** Statistics taken on a few rows would describe the rebuilt index badly: a rebuild refreshes them. */
    public function test_a_rebuild_analyzes_the_index_tables(): void
    {
        app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', 'Zebra 1%')->limit(5)->get());

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);

        // ANALYZE reads every row of a table this small, so the estimate is the count.
        $this->assertSame((float) DB::table('fuzzy_index_postings')->count(), $this->reltuples('fuzzy_index_postings'));
        $this->assertSame((float) DB::table('fuzzy_index_documents')->count(), $this->reltuples('fuzzy_index_documents'));
    }

    public function test_an_async_rebuild_analyzes_the_index_tables_once_its_batch_finishes(): void
    {
        config(['queue.batching.database' => config('database.default'), 'queue.default' => 'sync']);
        Schema::dropIfExists('job_batches');
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
        app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', 'Zebra 1%')->limit(5)->get());

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--async' => true])->assertExitCode(0);

        $this->assertSame((float) DB::table('fuzzy_index_postings')->count(), $this->reltuples('fuzzy_index_postings'));
    }
}
