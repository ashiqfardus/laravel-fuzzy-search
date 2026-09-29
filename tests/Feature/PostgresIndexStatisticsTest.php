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
 * first ANALYZE. The first index write, and every rebuild, that leaves the postings table at 16 pages
 * or more (ruling ER-112) now analyze them. The assertions read pg_class.reltuples, never a timing.
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

    /** A second schema test_a_search_path_switch_checks_the_other_schemas_index() switched to. */
    private ?string $tenantSchema = null;

    protected function tearDown(): void
    {
        if ($this->dbDriver === 'pgsql') {
            Schema::dropIfExists('job_batches');

            if ($this->tenantSchema !== null) {
                $this->switchSearchPath('public');
                DB::statement("drop schema if exists {$this->tenantSchema} cascade");
            }
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

    /** The postings table's size now, in pages: what the package compares with its 16-page minimum. */
    private function postingsPages(): int
    {
        return (int) DB::selectOne(
            "select pg_relation_size(to_regclass(?)) / current_setting('block_size')::int as pages",
            [DB::connection()->getQueryGrammar()->wrapTable('fuzzy_index_postings')]
        )->pages;
    }

    /**
     * Ruling ER-112. A one-row first write analyzed the tables at one row, and a bulk index inside one
     * transaction after it planned every posting's foreign-key check as a full scan of the terms table
     * (127 s at 50k rows, 10 s before). Under 16 pages the tables stay unanalyzed; once the table has
     * grown past that, a write analyzes it (ruling ER-110's doubling rule then takes over).
     */
    public function test_a_one_row_first_write_leaves_the_tables_unanalyzed(): void
    {
        app(IndexManager::class)->indexBatch(User::query()->where('name', 'Zebra 1')->get());

        $this->assertLessThanOrEqual(0, $this->reltuples('fuzzy_index_postings'), 'the one-row write left the postings unanalyzed');
        $this->assertLessThanOrEqual(0, $this->reltuples('fuzzy_index_terms'), 'and the terms');

        app(IndexManager::class)->indexBatch(User::query()->where('name', 'like', 'Zebra%')->get());

        $postings = DB::table('fuzzy_index_postings')->count();
        $this->assertGreaterThanOrEqual($postings / 2, $this->reltuples('fuzzy_index_postings'), 'statistics taken on the grown table');
    }

    /** Ruling ER-112: no write analyzes the tables while the postings table is under 16 pages, and the one that takes it there does. */
    public function test_the_write_that_takes_the_postings_table_to_sixteen_pages_analyzes_it(): void
    {
        foreach (User::query()->where('name', 'like', 'Zebra%')->orderBy('id')->get()->chunk(10) as $users) {
            app(IndexManager::class)->indexBatch($users);
            if ($this->postingsPages() >= 16) {
                break;
            }
            $this->assertLessThanOrEqual(0, $this->reltuples('fuzzy_index_postings'), 'unanalyzed at ' . $this->postingsPages() . ' pages');
        }

        $this->assertGreaterThanOrEqual(16, $this->postingsPages(), 'the 300 zebras take the table past the minimum');
        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_postings'));
        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_terms'));
        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_documents'));
    }

    /** Index past 10,000 postings, then the check that finds them described and caches it (ER-110). */
    private function indexPastTenThousandPostings(): void
    {
        $rows = array_map(fn ($i) => ['name' => "Quagga {$i} alpha beta gamma delta", 'email' => "q{$i}@example.test"], range(1, 3000)); // about 27k postings: the last statistics, on at least half of them, hold 10,000
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        User::query()->orderBy('id')->chunk(250, fn ($users) => app(IndexManager::class)->indexBatch($users));
        app(IndexManager::class)->indexBatch(User::query()->where('name', 'Zebra 1')->get()); // the check that caches it

        $this->assertGreaterThanOrEqual(10000, $this->reltuples('fuzzy_index_postings'));
    }

    /** Ruling ER-110: once the statistics describe at least 10,000 postings, an index write reads the catalog no more. */
    public function test_an_index_described_at_ten_thousand_postings_is_checked_no_more(): void
    {
        $this->indexPastTenThousandPostings();

        $catalog = 0;
        DB::listen(function ($query) use (&$catalog) {
            $catalog += (int) str_contains($query->sql, 'pg_class') + (int) (stripos($query->sql, 'analyze') === 0);
        });
        foreach (['Zebra 2', 'Zebra 3', 'Zebra 4'] as $name) {
            app(IndexManager::class)->indexBatch(User::query()->where('name', $name)->get());
        }

        $this->assertSame(0, $catalog, 'no catalog read or ANALYZE once the statistics describe the table');
    }

    /** Reconnect with $schema as the search_path, as stancl/tenancy's PostgreSQL schema manager does per tenant. */
    private function switchSearchPath(string $schema): void
    {
        config(['database.connections.' . DB::getDefaultConnection() . '.search_path' => $schema]);
        DB::purge();
    }

    /**
     * L11 (round 9). Schema-per-tenant PostgreSQL switches search_path on one connection name and
     * database. The check's cache of "described" was keyed by connection, database and table prefix,
     * so a worker that had indexed one tenant past 10,000 postings never analyzed another tenant's
     * tables: its searches ran on unanalyzed tables until autovacuum's first ANALYZE (ER-106).
     */
    public function test_a_search_path_switch_checks_the_other_schemas_index(): void
    {
        $this->indexPastTenThousandPostings(); // the public schema's index is described, and cached

        $this->tenantSchema = 'fuzzy_tenant_b';
        DB::statement("drop schema if exists {$this->tenantSchema} cascade");
        DB::statement("create schema {$this->tenantSchema}");
        $this->switchSearchPath($this->tenantSchema);
        $this->artisan('migrate', ['--path' => realpath(__DIR__ . '/../../database/migrations'), '--realpath' => true])->assertExitCode(0);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        DB::table('users')->insert(array_map(fn ($i) => ['name' => "Okapi {$i}", 'email' => "o{$i}@example.test"], range(1, 300)));

        foreach (User::query()->orderBy('id')->get()->chunk(100) as $users) {
            app(IndexManager::class)->indexBatch($users);
        }

        $this->assertSame(300, DB::table('fuzzy_index_documents')->count(), 'the tenant\'s own index tables');
        $this->assertGreaterThan(0, $this->reltuples('fuzzy_index_postings'), 'the tenant\'s postings were analyzed');
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
                // Three writes of 100 zebras: about 20 pages, past the 16-page minimum (ER-112).
                foreach (User::query()->where('name', 'like', 'Zebra%')->orderBy('id')->get()->chunk(100) as $users) {
                    app(IndexManager::class)->indexBatch($users);
                }
                $inside = $this->reltuples('fuzzy_index_postings');
            });
        });

        $this->assertLessThanOrEqual(0, $inside, 'nothing analyzed inside the transaction');
        $this->assertCount(3, $checks, 'the check\'s catalog read, analyzeIndex()\'s size read and one ANALYZE, at the commit: ' . implode(' | ', $checks));
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

    /**
     * Statistics taken part way through would describe the rebuilt index badly: a rebuild refreshes
     * them once every row is indexed (ER-106). L13 (round 9): four chunks of 100 (307 users). The
     * index writes' own check (ER-110) analyzes the table after the third, at 20 pages, and not again,
     * so the last chunk's postings are only in the rebuild's own statistics. On one chunk that check
     * took them all, and the test passed without the rebuild's ANALYZE.
     */
    public function test_a_rebuild_analyzes_the_index_tables(): void
    {
        config(['fuzzy-search.indexing.chunk_size' => 100]);

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);

        // ANALYZE reads every row of a table this small, so the estimate is the count.
        $this->assertSame((float) DB::table('fuzzy_index_postings')->count(), $this->reltuples('fuzzy_index_postings'));
        $this->assertSame((float) DB::table('fuzzy_index_documents')->count(), $this->reltuples('fuzzy_index_documents'));
    }

    /**
     * Ruling ER-112, for a rebuild: analyzed at one row by fuzzy-search:rebuild (a deploy step on a
     * fresh install), a bulk index inside one transaction after it took 127 s at 50k rows, as after a
     * one-row first write. A rebuild that leaves the postings table under 16 pages leaves it unanalyzed.
     */
    public function test_a_rebuild_that_leaves_the_postings_table_under_sixteen_pages_leaves_the_tables_unanalyzed(): void
    {
        DB::table('users')->where('name', 'like', 'Zebra%')->delete();

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);

        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count(), 'the rebuild indexed the remaining users');
        $this->assertLessThan(16, $this->postingsPages());
        $this->assertLessThanOrEqual(0, $this->reltuples('fuzzy_index_postings'));
        $this->assertLessThanOrEqual(0, $this->reltuples('fuzzy_index_terms'));
    }

    /**
     * As the sync rebuild, in four jobs. The batch is stored on a connection of its own: on the sync
     * queue the jobs run inside the batch store's transaction, and on the index's connection that
     * deferred their checks to its commit, where a worker runs each job outside any transaction.
     */
    public function test_an_async_rebuild_analyzes_the_index_tables_once_its_batch_finishes(): void
    {
        config([
            'database.connections.batches'     => config('database.connections.' . config('database.default')),
            'queue.batching.database'          => 'batches',
            'queue.default'                    => 'sync',
            'fuzzy-search.indexing.chunk_size' => 100,
        ]);
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

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--async' => true])->assertExitCode(0);

        $this->assertSame((float) DB::table('fuzzy_index_postings')->count(), $this->reltuples('fuzzy_index_postings'));
        $this->assertSame((float) DB::table('fuzzy_index_documents')->count(), $this->reltuples('fuzzy_index_documents'));
    }
}
