<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M2 (round 9), ruling D9. The package's ANALYZE of its index tables (rulings ER-106 to ER-112) only
 * makes searches faster, and it runs on an index that is already written: it is best-effort. A
 * failure (here a lock_timeout while another session holds the lock autovacuum and ANALYZE take) is
 * reported through the exception handler and never thrown. Thrown from the check deferred to the
 * commit, it reached the caller of a DB::transaction() that had committed, and Laravel skipped the
 * transaction's later after-commit callbacks; thrown at the end of a rebuild, it failed a rebuild
 * that had indexed everything.
 */
class PostgresAnalyzeFailureTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    private ?Connection $locker = null;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('The package runs ANALYZE on PostgreSQL only; the CI PostgreSQL jobs run this.');
        }

        // 300 zebras: about 20 pages of postings, past the 16 pages under which nothing is analyzed (ER-112).
        $rows = array_map(fn ($i) => ['name' => "Zebra {$i}", 'email' => "z{$i}@example.test"], range(1, 300));
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $reported = &$this->reported;
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler {
            public function __construct(private array &$reported) {}
            public function report(\Throwable $e) { $this->reported[] = $e; }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw $e; }
            public function renderForConsole($output, \Throwable $e) { throw $e; }
        });

        config(['database.connections.locker' => config('database.connections.integration')]);
        $this->locker = DB::connection('locker');
        DB::statement("set lock_timeout = '100ms'");
    }

    protected function tearDown(): void
    {
        if ($this->dbDriver === 'pgsql') {
            $this->unlock(); // before the parent drops the tables, which waits on the lock
            DB::purge('locker');
            Schema::dropIfExists('job_batches');
        }

        parent::tearDown();
    }

    /** Another session takes $mode on the postings table and holds it until unlock(). */
    private function lock(string $mode = 'share update exclusive'): void
    {
        $this->locker->beginTransaction();
        $this->locker->statement('lock table ' . DB::connection()->getQueryGrammar()->wrapTable('fuzzy_index_postings') . " in {$mode} mode");
    }

    private function unlock(): void
    {
        if ($this->locker?->transactionLevel() > 0) {
            $this->locker->rollBack();
        }
    }

    /** Every failure reported is a lock timeout (55P03), and there was one at least. */
    private function assertLockTimeoutsReported(): void
    {
        $this->assertNotEmpty($this->reported, 'the failure was reported');
        foreach ($this->reported as $e) {
            $this->assertSame('55P03', $e->getCode(), $e->getMessage());
        }
    }

    private function zebras()
    {
        return User::query()->where('name', 'like', 'Zebra%')->get();
    }

    /** The scenario of the report: Scout's default config indexes inside the app's order transaction. */
    public function test_a_failed_analyze_at_the_commit_is_reported_and_the_apps_later_callbacks_still_run(): void
    {
        $this->lock(); // what autovacuum, VACUUM or another ANALYZE holds
        $ran = false;

        DB::transaction(function () use (&$ran) {
            DB::table('products')->insert(['title' => 'Order 1', 'price' => 10]);
            app(IndexManager::class)->indexBatch($this->zebras());
            DB::afterCommit(function () use (&$ran) { $ran = true; });
        });

        $this->unlock();
        $this->assertTrue($ran, 'the app\'s after-commit callback ran');
        $this->assertSame(1, DB::table('products')->where('title', 'Order 1')->count());
        $this->assertSame(300, DB::table('fuzzy_index_documents')->count());
        $this->assertLockTimeoutsReported();
    }

    /** The check's own catalog read, before any ANALYZE, fails the same way. */
    public function test_a_failed_catalog_read_at_the_commit_is_reported_and_the_apps_later_callbacks_still_run(): void
    {
        $ran = false;

        DB::transaction(function () use (&$ran) {
            // Registered first, so it runs before the check: its read of the table's size waits on this lock.
            DB::afterCommit(fn () => $this->lock('access exclusive'));
            app(IndexManager::class)->indexBatch($this->zebras());
            DB::afterCommit(function () use (&$ran) { $ran = true; });
        });

        $this->unlock();
        $this->assertTrue($ran, 'the app\'s after-commit callback ran');
        $this->assertCount(1, $this->reported);
        $this->assertStringContainsString('pg_class', $this->reported[0]->getMessage());
        $this->assertLockTimeoutsReported();
    }

    public function test_a_failed_analyze_after_a_write_outside_a_transaction_is_reported(): void
    {
        $this->lock();

        $indexed = app(IndexManager::class)->indexBatch($this->zebras());

        $this->unlock();
        $this->assertSame(300, $indexed);
        $this->assertLockTimeoutsReported();
    }

    public function test_a_rebuild_whose_analyze_fails_exits_zero(): void
    {
        $this->lock();

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class])->assertExitCode(0);

        $this->unlock();
        $this->assertSame(User::count(), DB::table('fuzzy_index_documents')->count());
        $this->assertLockTimeoutsReported();
    }

    public function test_an_async_rebuild_whose_analyze_fails_exits_zero(): void
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
        $this->lock();

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--async' => true])->assertExitCode(0);

        $this->unlock();
        $this->assertSame(User::count(), DB::table('fuzzy_index_documents')->count());
        $this->assertLockTimeoutsReported();
    }

    /**
     * A rebuild run inside the caller's transaction (an app test under RefreshDatabase) analyzes
     * inside it. On PostgreSQL a failed statement aborts the whole transaction, so the ANALYZE runs
     * under a savepoint: the caller's next statement and its commit still work.
     */
    public function test_a_failed_analyze_inside_the_callers_transaction_leaves_it_usable(): void
    {
        $this->lock();

        DB::transaction(function () {
            $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => User::class]));
            DB::table('products')->insert(['title' => 'After the rebuild', 'price' => 10]);
        });

        $this->unlock();
        $this->assertSame(1, DB::table('products')->where('title', 'After the rebuild')->count());
        $this->assertSame(User::count(), DB::table('fuzzy_index_documents')->count());
        $this->assertLockTimeoutsReported();
    }

    /**
     * L5 (round 10). When the connection is lost during the ANALYZE, the rollback to the savepoint
     * fails too, and Laravel has by then reset the transaction level to 0: the caller's transaction
     * went with the connection. Swallowed, the caller's closure carried on at level 0, its later
     * statements autocommitted, and DB::transaction() returned normally over an empty index. The
     * failure is thrown when the rollback to the savepoint fails; D9 holds while the transaction survives.
     */
    public function test_a_connection_lost_during_analyze_inside_the_callers_transaction_is_thrown(): void
    {
        $connection = DB::connection();
        $killed     = false;
        $connection->beforeExecuting(function (string $query) use ($connection, &$killed) {
            if (!$killed && stripos($query, 'analyze') === 0 && $connection->transactionLevel() > 0) {
                $killed = true;
                $pid    = $connection->getPdo()->query('select pg_backend_pid()')->fetchColumn();
                $this->locker->statement('select pg_terminate_backend(?)', [$pid]);
                usleep(300000); // the backend exits before the ANALYZE is sent
            }
        });

        $levels = [];
        try {
            DB::transaction(function () use (&$levels) {
                DB::table('products')->insert(['title' => 'Before the rebuild', 'price' => 10]);
                Artisan::call('fuzzy-search:rebuild', ['model' => User::class]);
                $levels[] = DB::transactionLevel();
                DB::table('products')->insert(['title' => 'After the rebuild', 'price' => 10]);
            });
            $this->fail('DB::transaction() returned normally after its connection was lost');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('analyze', $e->getSql());
        }

        $this->assertTrue($killed, 'the ANALYZE ran inside the transaction');
        $this->assertSame([], $levels, 'the closure went on after the rebuild');
        $this->assertSame(0, DB::table('products')->whereIn('title', ['Before the rebuild', 'After the rebuild'])->count(), 'a statement autocommitted');
        $this->assertSame(0, DB::table('fuzzy_index_documents')->count());
        $this->assertSame([], $this->reported);
    }

    /**
     * On a deadlock or a serialization failure, Laravel's nested transaction() drops its level
     * without ROLLBACK TO SAVEPOINT (MySQL has rolled the whole transaction back by then), so on
     * PostgreSQL the caller's transaction stayed aborted: its next statement failed with 25P02. The
     * savepoint is rolled back on any failure. The deadlock is staged: the ANALYZE inside the caller's
     * transaction aborts it, as a deadlock victim's is, then fails with PostgreSQL's message.
     */
    public function test_a_deadlocked_analyze_inside_the_callers_transaction_leaves_it_usable(): void
    {
        $connection = DB::connection();
        $connection->beforeExecuting(function (string $query) use ($connection) {
            if (stripos($query, 'analyze') === 0 && $connection->transactionLevel() > 0) {
                try {
                    $connection->getPdo()->exec('select 1 / 0');
                } catch (\PDOException) {
                }

                throw new \PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected');
            }
        });

        DB::transaction(function () {
            $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => User::class]));
            DB::table('products')->insert(['title' => 'After the rebuild', 'price' => 10]);
        });

        $this->assertSame(1, DB::table('products')->where('title', 'After the rebuild')->count());
        $this->assertSame(User::count(), DB::table('fuzzy_index_documents')->count());
        $this->assertCount(1, $this->reported);
        $this->assertStringContainsString('deadlock detected', $this->reported[0]->getMessage());
    }
}
