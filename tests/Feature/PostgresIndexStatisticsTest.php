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

    /**
     * Ruling ER-110. A first index write of one row analyzed the tables at one row, and that was
     * cached: after 50k more rows were indexed without a rebuild, the planner still took every term
     * to have one posting, and every search took about 100 s (indexing 7× longer) until autovacuum.
     * The statistics are taken again once the postings table has doubled since they were.
     */
    public function test_statistics_taken_on_a_one_row_first_write_are_retaken_as_the_table_grows(): void
    {
        app(IndexManager::class)->indexBatch(User::query()->where('name', 'Zebra 1')->get());
        $one = $this->reltuples('fuzzy_index_postings');
        $this->assertGreaterThan(0, $one, 'the one-row write analyzed the tables');

        app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', 'Zebra%')->get());

        $postings = DB::table('fuzzy_index_postings')->count();
        $this->assertGreaterThan(20 * $one, $postings, 'the table grew well past the one-row statistics');
        $this->assertGreaterThanOrEqual($postings / 2, $this->reltuples('fuzzy_index_postings'), 'statistics taken again on the grown table');
    }

    /** Ruling ER-110: once the statistics describe at least 10,000 postings, an index write reads the catalog no more. */
    public function test_an_index_described_at_ten_thousand_postings_is_checked_no_more(): void
    {
        $rows = array_map(fn ($i) => ['name' => "Quagga {$i} alpha beta gamma delta", 'email' => "q{$i}@example.test"], range(1, 3000)); // about 27k postings: the last statistics, on at least half of them, hold 10,000
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        User::query()->orderBy('id')->chunk(250, fn ($users) => app(IndexManager::class)->indexBatch($users));
        app(IndexManager::class)->indexBatch(User::query()->where('name', 'Zebra 1')->get()); // the check that caches it

        $this->assertGreaterThanOrEqual(10000, $this->reltuples('fuzzy_index_postings'));

        $catalog = 0;
        DB::listen(function ($query) use (&$catalog) {
            $catalog += (int) str_contains($query->sql, 'pg_class') + (int) (stripos($query->sql, 'analyze') === 0);
        });
        foreach (['Zebra 2', 'Zebra 3', 'Zebra 4'] as $name) {
            app(IndexManager::class)->indexBatch(User::query()->where('name', $name)->get());
        }

        $this->assertSame(0, $catalog, 'no catalog read or ANALYZE once the statistics describe the table');
    }

    /** @return string[] the package's catalog reads (not reltuples()'s) and ANALYZE statements $call runs */
    private function checks(\Closure $call): array
    {
        $checks = [];
        DB::listen(function ($query) use (&$checks) {
            if (str_contains($query->sql, 'pg_relation_size') || stripos($query->sql, 'analyze') === 0) {
                $checks[] = $query->sql;
            }
        });

        try {
            $call();
        } finally {
            DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);
        }

        return $checks;
    }

    /**
     * Ruling ER-111. Inside a transaction an index write cannot analyze (ANALYZE would hold its lock
     * until the commit), and it skipped the check: a bulk import in one transaction, which Scout's
     * default config gives every engine write, left the tables unanalyzed after the commit. The check
     * now runs when the transaction commits, once however many index writes it held.
     */
    public function test_index_writes_inside_a_transaction_are_checked_once_when_it_commits(): void
    {
        $inside = null;
        $checks = $this->checks(function () use (&$inside) {
            DB::transaction(function () use (&$inside) {
                foreach (['Zebra 1%', 'Zebra 2%', 'Zebra 3%'] as $names) {
                    app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', $names)->get());
                }
                $inside = $this->reltuples('fuzzy_index_postings');
            });
        });

        $this->assertLessThanOrEqual(0, $inside, 'nothing analyzed inside the transaction');
        $this->assertCount(2, $checks, 'one catalog read and one ANALYZE, at the commit: ' . implode(' | ', $checks));
        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_postings'));
    }

    /** Ruling ER-111: a rolled-back transaction runs no check, and the next commit still runs its own. */
    public function test_a_rolled_back_transaction_runs_no_check(): void
    {
        $checks = $this->checks(function () {
            try {
                DB::transaction(function () {
                    app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', 'Zebra%')->get());

                    throw new \RuntimeException('roll back');
                });
            } catch (\RuntimeException) {
            }
        });

        $this->assertSame([], $checks);
        $this->assertLessThanOrEqual(0, $this->reltuples('fuzzy_index_postings'));

        DB::transaction(fn () => app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', 'Zebra%')->get()));

        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_postings'));
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
