<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `fuzzy-search:clear --all` (IndexManager::flushAll()) truncates the big tables where it can
 * (ruling ER-187). One DELETE per table in one transaction logged every row: on SQL Server the log
 * grew from 1 GB to 5.4 GB in two minutes at 1M rows, MySQL kept the undo, PostgreSQL the WAL.
 * It stays atomic against index writes (TB-1: IndexModelJobOverlapTest), keeps the model type and
 * column dictionaries, never commits a transaction of the caller's, never waits for an exclusive
 * table lock (R13-M3, ruling ER-194), and falls back to the DELETEs where TRUNCATE is refused or
 * the tables are busy, saying so on the console. MySQL always deletes.
 */
class ClearAllTest extends TestCase
{
    private const TABLES = ['fuzzy_index_postings', 'fuzzy_index_documents', 'fuzzy_index_meta', 'fuzzy_index_model_terms', 'fuzzy_index_terms'];

    private function indexBoth(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        app(IndexManager::class)->indexBatch(Product::all());

        foreach (self::TABLES as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), $table);
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ([...self::TABLES, 'fuzzy_index_model_types', 'fuzzy_index_columns'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /** @return array<string, array<int, string>> */
    private function dictionaries(): array
    {
        return [
            'model_types' => DB::table('fuzzy_index_model_types')->orderBy('id')->pluck('model_type', 'id')->all(),
            'columns'     => DB::table('fuzzy_index_columns')->orderBy('id')->pluck('name', 'id')->all(),
        ];
    }

    private function assertCleared(array $dictionaries, string $message = ''): void
    {
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), trim("{$message} {$table}"));
        }
        $this->assertSame($dictionaries, $this->dictionaries(), trim("{$message} the dictionaries stay"));
    }

    /** The line clear --all prints when it deleted row by row. */
    private const ROW_BY_ROW = 'deleted row by row';

    /**
     * Run `fuzzy-search:clear --all`, expecting it to say it deleted row by row, or not. Through the
     * pending command: under Laravel 10's test kernel a package command's output never reaches
     * Artisan::output().
     */
    private function clearAll(bool $rowByRow): void
    {
        $command = $this->artisan('fuzzy-search:clear', ['--all' => true]);
        $rowByRow ? $command->expectsOutputToContain(self::ROW_BY_ROW) : $command->doesntExpectOutputToContain(self::ROW_BY_ROW);
        $command->assertExitCode(0)->run();
    }

    /** The statements a clear --all sends, without quoting: the five tables', the locks and SQL Server's applock (no read). */
    private function statements(\Closure $run): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $sql = strtolower(preg_replace('/\s+/', ' ', str_replace(['"', '`', '[', ']'], '', $query->sql)));
            if (str_contains($sql, 'sp_getapplock')) {
                $statements[] = 'sp_getapplock';
            } elseif (!str_starts_with($sql, 'select') && preg_match('/fuzzy_index_(postings|documents|meta|model_terms|terms)\b|^(lock|unlock) tables?\b/', $sql)) {
                $statements[] = trim($sql);
            }
        });
        $run();

        return $statements;
    }

    public function test_clear_all_empties_the_index_and_keeps_the_dictionaries(): void
    {
        $this->indexBoth();
        $dictionaries = $this->dictionaries();

        $this->clearAll($this->dbDriver === 'mysql');

        $this->assertCleared($dictionaries);
        // The dictionaries' ids still hold: the index fills again and searches.
        app(IndexManager::class)->indexBatch(User::all());
        $this->assertSame($dictionaries, $this->dictionaries());
        $this->assertGreaterThan(0, User::search('john')->useInvertedIndex()->get()->count());
    }

    /**
     * ER-187: the big tables (postings, documents, and the model terms, ER-197) are truncated, not
     * deleted row by row, and the model terms are emptied before the terms on every path (a DELETE
     * of the terms cascades into them row by row). PostgreSQL truncates all five in one statement, without CASCADE or RESTART IDENTITY, once it
     * holds them (LOCK TABLE … NOWAIT); MariaDB under LOCK TABLES … NOWAIT (its TRUNCATE commits);
     * SQL Server behind the index write lock. MySQL deletes (R13-M3: its LOCK TABLES cannot be
     * told not to wait). flushAll() says whether it truncated.
     */
    public function test_clear_all_truncates_the_big_tables(): void
    {
        $this->indexBoth();

        $statements = $this->statements(function () use (&$truncated) {
            $truncated = app(IndexManager::class)->flushAll();
        });

        $tables   = implode(', ', self::TABLES);
        $expected = match ($this->dbDriver) {
            'pgsql' => [
                'lock table fuzzy_index_documents in share row exclusive mode',
                "lock table {$tables} in access exclusive mode nowait",
                "truncate table {$tables}",
            ],
            'mariadb' => [
                'lock tables fuzzy_index_postings write, fuzzy_index_documents write, fuzzy_index_meta write, fuzzy_index_model_terms write, fuzzy_index_terms write nowait',
                'truncate table fuzzy_index_postings',
                'truncate table fuzzy_index_documents',
                'truncate table fuzzy_index_model_terms',
                'delete from fuzzy_index_meta',
                'delete from fuzzy_index_terms',
                'unlock tables',
            ],
            'sqlsrv' => [
                'sp_getapplock',
                'truncate table fuzzy_index_postings',
                'truncate table fuzzy_index_documents',
                'truncate table fuzzy_index_model_terms',
                'delete from fuzzy_index_meta',
                'delete from fuzzy_index_terms',
            ],
            default => array_map(fn (string $table) => "delete from {$table}", self::TABLES), // MySQL; SQLite: DELETE without WHERE is its truncate
        };
        $this->assertSame($expected, $statements);
        $this->assertSame($this->dbDriver !== 'mysql', $truncated);
        $this->assertSame(0, DB::table('fuzzy_index_postings')->count());
    }

    /**
     * Inside a transaction of the caller's, clear --all commits nothing: on MySQL/MariaDB TRUNCATE
     * and LOCK TABLES would commit it, so the DELETEs run there; PostgreSQL's and SQL Server's
     * TRUNCATE roll back with it.
     */
    public function test_clear_all_in_a_transaction_that_rolls_back_leaves_the_index_as_it_was(): void
    {
        $this->indexBoth();
        $before = $this->counts();

        DB::beginTransaction();
        try {
            $statements = $this->statements(function () use (&$truncated) {
                $truncated = app(IndexManager::class)->flushAll();
            });
            $this->assertSame(0, DB::table('fuzzy_index_postings')->count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, $this->counts());
        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->assertSame(array_map(fn (string $table) => "delete from {$table}", self::TABLES), $statements);
            $this->assertFalse($truncated);
        }
    }

    /**
     * A table of the app's with a foreign key to the index makes TRUNCATE fail (PostgreSQL would
     * empty it with CASCADE); clear --all then deletes, and the app's rows stay. Only PostgreSQL
     * truncates the referenced words, so only there (and on MySQL, always) it says it deleted.
     */
    public function test_a_table_of_the_apps_referencing_the_index_keeps_its_rows(): void
    {
        Schema::dropIfExists('clear_all_refs');
        Schema::create('clear_all_refs', function ($table) {
            $table->id();
            $table->foreignId('term_id')->nullable()->constrained('fuzzy_index_terms')->cascadeOnDelete();
        });

        try {
            DB::table('clear_all_refs')->insert([['term_id' => null], ['term_id' => null]]);
            $this->indexBoth();
            $dictionaries = $this->dictionaries();

            $this->clearAll(in_array($this->dbDriver, ['mysql', 'pgsql'], true));

            $this->assertCleared($dictionaries);
            $this->assertSame(2, DB::table('clear_all_refs')->count());
        } finally {
            Schema::dropIfExists('clear_all_refs');
        }
    }

    public static function grants(): array
    {
        return ['rows only' => [false], 'rows and LOCK TABLES' => [true]];
    }

    /**
     * A database user that may only read and write rows (no DROP on MySQL/MariaDB, no TRUNCATE on
     * PostgreSQL, no ALTER on SQL Server) cleared the index with the DELETEs before ER-187, and
     * still does: a refused LOCK TABLES or TRUNCATE falls back to them. On PostgreSQL the refusal
     * rolls back to a savepoint, so the transaction goes on; SQL Server would roll back the whole
     * transaction, so it checks the permission first. MySQL takes no LOCK TABLES at all (R13-M3).
     * The command says it deleted row by row. SQLite has no users.
     */
    #[DataProvider('grants')]
    public function test_a_user_who_may_only_read_and_write_rows_still_clears_all(bool $lockTables): void
    {
        $mysql = in_array($this->dbDriver, ['mysql', 'mariadb'], true);
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite has no database users.');
        }
        if ($lockTables && !$mysql) {
            $this->markTestSkipped('LOCK TABLES is MySQL\'s and MariaDB\'s.');
        }

        $this->indexBoth();
        $dictionaries = $this->dictionaries();
        $user         = 'fz_clear_' . getmypid();
        $password     = 'Fz_Clear_2026_' . bin2hex(random_bytes(4));
        $database     = DB::connection()->getDatabaseName();

        try {
            if ($mysql) {
                DB::unprepared("DROP USER IF EXISTS '{$user}'@'%'");
                DB::unprepared("CREATE USER '{$user}'@'%' IDENTIFIED BY '{$password}'");
                DB::unprepared("GRANT SELECT, INSERT, UPDATE, DELETE" . ($lockTables ? ', LOCK TABLES' : '') . " ON `{$database}`.* TO '{$user}'@'%'");
            } elseif ($this->dbDriver === 'sqlsrv') {
                $this->dropSqlsrvLogin($user);
                DB::unprepared("CREATE LOGIN [{$user}] WITH PASSWORD = '{$password}', CHECK_POLICY = OFF; CREATE USER [{$user}] FOR LOGIN [{$user}]; GRANT SELECT, INSERT, UPDATE, DELETE TO [{$user}]");
            } else {
                DB::unprepared("DROP ROLE IF EXISTS {$user}");
                DB::unprepared("CREATE ROLE {$user} LOGIN PASSWORD '{$password}'");
                DB::unprepared('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA ' . DB::scalar('select current_schema()') . " TO {$user}");
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $this->markTestSkipped('The test connection cannot create a database user: ' . $e->getMessage());
        }

        config(['database.connections.rows_only' => ['username' => $user, 'password' => $password] + config('database.connections.integration')]);
        DB::setDefaultConnection('rows_only');
        try {
            $statements = $this->statements(fn () => $this->clearAll(true));
        } finally {
            DB::setDefaultConnection('integration');
            DB::purge('rows_only');
            if ($mysql) {
                DB::unprepared("DROP USER IF EXISTS '{$user}'@'%'");
            } elseif ($this->dbDriver === 'sqlsrv') {
                $this->dropSqlsrvLogin($user);
            } else {
                DB::unprepared("DROP OWNED BY {$user}");
                DB::unprepared("DROP ROLE IF EXISTS {$user}");
            }
        }

        $this->assertCleared($dictionaries);
        // The refused statement is not logged (no QueryExecuted); the DELETEs that followed are.
        $this->assertContains('delete from fuzzy_index_postings', $statements);
        $this->assertSame($lockTables && $this->dbDriver === 'mariadb', in_array('unlock tables', $statements, true));
    }

    /** @return array<string, array{string, string, float}> what the app's transaction does first, then $delay seconds into the clear */
    public static function appTransactions(): array
    {
        return [
            // MySQL and MariaDB killed this one (1213): LOCK TABLES held the documents, waiting on the postings
            'reads the postings, then the documents' => ['postings', 'documents', 1.5],
            // PostgreSQL killed this one (40P01): TRUNCATE held the postings, waiting on the documents
            'reads the documents, then the postings' => ['documents', 'postings', 1.5],
            'reads the meta, then searches'          => ['meta', 'search', 1.5],
            // an index write inside the app's transaction (a save's runs after the commit): the sync
            // queue's IndexModelJob. PostgreSQL killed the app here, or 0.3 s in, the clear.
            'searches, then indexes a row 1.5 s into the clear' => ['search', 'job', 1.5],
            'searches, then indexes a row 0.3 s into the clear' => ['search', 'job', 0.3],
        ];
    }

    /**
     * R13-M3: an app transaction on its own connection (a forked process) reads the index, and while
     * it is open clear --all runs here; then the app reads or writes the index again. clear --all
     * waited for the app's lock on one table while holding others the app's next statement needed:
     * MySQL and MariaDB killed the app's transaction (1213) and its own row with it, PostgreSQL the
     * app's or the clear's (40P01). Both now commit, and the index ends consistent: empty, or holding
     * the app's write in full (MariaDB truncates once the app has committed, if that is within its
     * tries). SQLite has one writer: there a transaction that reads and then writes fails against
     * any concurrent writer (SQLITE_BUSY), whatever the package does.
     */
    #[DataProvider('appTransactions')]
    public function test_clear_all_and_an_open_app_transaction_both_commit(string $first, string $then, float $delay): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite has one writer: a transaction that reads, then writes, gets SQLITE_BUSY against any other writer.');
        }
        $this->indexBoth();

        $step = function (string $step, int|string $id) {
            match ($step) {
                'search' => User::search('john')->useInvertedIndex()->get(),
                'job'    => IndexModelJob::dispatchSync(User::class, $id),
                default  => DB::table("fuzzy_index_{$step}")->count(),
            };
        };
        $email = 'clear-all-app@example.test';
        [$appError, $clearError] = $this->alongside(
            function (\Closure $ready, \Closure $cleared) use ($step, $first, $then, $delay, $email) {
                DB::transaction(function () use ($step, $first, $then, $delay, $email, $ready, $cleared) {
                    $id = DB::table('users')->insertGetId(['name' => 'Quokka Wombat', 'email' => $email, 'created_at' => now(), 'updated_at' => now()]); // the app's own row
                    $step($first, $id);
                    $ready();
                    $cleared();
                    usleep((int) ($delay * 1_000_000));
                    $step($then, $id);
                });
            },
            fn () => $this->assertSame(0, Artisan::call('fuzzy-search:clear', ['--all' => true]))
        );

        $this->assertNull($appError, 'the app transaction');
        $this->assertNull($clearError, 'clear --all');
        $id = DB::table('users')->where('email', $email)->value('id');
        $this->assertNotNull($id, 'the app transaction kept its row');

        $documents = DB::table('fuzzy_index_documents')->pluck('model_id')->map(fn ($key) => (string) $key)->all();
        if ($documents === []) {
            foreach (self::TABLES as $table) {
                $this->assertSame(0, DB::table($table)->count(), $table);
            }
        } else {
            $this->assertSame('job', $then, 'the clear left rows');
            $this->assertSame([(string) $id], $documents);
            $meta = DB::table('fuzzy_index_meta')->get(['model_type', 'total_docs', 'total_tokens'])->map(fn ($row) => [$row->model_type, (int) $row->total_docs, (int) $row->total_tokens])->all();
            $this->assertSame([[User::class, 1, (int) DB::table('fuzzy_index_documents')->sum('doc_length')]], $meta);
            $this->assertSame([(int) $id], User::search('quokka')->useInvertedIndex()->get()->pluck('id')->map(fn ($key) => (int) $key)->all());
        }
    }

    /**
     * ER-194: while another transaction holds the index tables (here a reader that never ends during
     * the clear), PostgreSQL's and MariaDB's TRUNCATE is refused at once, tried again, and given up
     * for the DELETEs, which a reader does not block; MySQL always deletes. The command says so.
     * PostgreSQL's refusals roll back to their savepoint, so the transaction goes on, a caller's too.
     * SQL Server's readers hold no lock past their statement, so it truncates.
     */
    public function test_clear_all_deletes_row_by_row_while_another_transaction_holds_the_tables(): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite has no table locks: it always deletes.');
        }
        $this->indexBoth();
        $dictionaries = $this->dictionaries();
        $this->boundLockWaits(2); // a clear that waited for the reader fails the test, not hangs it

        config(['database.connections.reader' => config('database.connections.integration')]);
        $reader = DB::connection('reader');
        $reader->beginTransaction();
        try {
            $reader->table('fuzzy_index_postings')->count();
            $reader->table('fuzzy_index_documents')->count();

            $sqlsrv     = $this->dbDriver === 'sqlsrv';
            $statements = $this->statements(fn () => $this->clearAll(!$sqlsrv));
            $this->assertCleared($dictionaries);

            // Inside a transaction of the caller's: the DELETEs follow the refusals in it, and it goes on.
            $this->indexBoth();
            DB::transaction(function () use (&$truncated) {
                $truncated = app(IndexManager::class)->flushAll();
                DB::table('users')->count();
            });
            $this->assertCleared($dictionaries, 'in a transaction');
        } finally {
            $reader->rollBack();
            DB::purge('reader');
        }

        $this->assertSame($sqlsrv, (bool) preg_grep('/^truncate /', $statements), implode("\n", $statements));
        $this->assertSame($sqlsrv, $truncated);
    }

    /**
     * ER-194: a search holds its shared lock for a moment, so one refusal is no reason to delete row
     * by row: PostgreSQL and MariaDB try again, 200 ms apart, and truncate once the reader is gone.
     */
    public function test_clear_all_truncates_once_a_short_reader_has_finished(): void
    {
        if (!in_array($this->dbDriver, ['pgsql', 'mariadb'], true)) {
            $this->markTestSkipped('Only PostgreSQL and MariaDB refuse at once and try again.');
        }
        $this->indexBoth();
        $dictionaries = $this->dictionaries();

        [$readerError, $clearError] = $this->alongside(
            function (\Closure $ready) {
                DB::transaction(function () use ($ready) {
                    DB::table('fuzzy_index_postings')->count();
                    $ready();
                    usleep(300_000);
                });
            },
            function () use (&$statements, &$truncated) {
                $statements = $this->statements(function () use (&$truncated) {
                    $truncated = app(IndexManager::class)->flushAll();
                });
            }
        );

        $this->assertNull($readerError);
        $this->assertNull($clearError);
        $this->assertTrue($truncated);
        $this->assertNotEmpty(preg_grep('/^truncate /', $statements), implode("\n", $statements));
        $this->assertCleared($dictionaries);
    }

    /**
     * A deadlock inside PostgreSQL's savepoint (none can happen there now: NOWAIT never waits, and
     * TRUNCATE then holds what it needs) is not a refusal to fall back from: Laravel turns it into
     * a DeadlockException without rolling back to the savepoint, so the transaction is aborted.
     * clear --all throws it, rolled back whole, and the index is as it was.
     */
    public function test_a_deadlock_inside_the_savepoint_throws_and_leaves_the_index(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('Only PostgreSQL truncates in a savepoint.');
        }
        $this->indexBoth();
        $before = $this->counts();

        DB::listen(function ($query) {
            if (preg_match('/^\s*truncate table\b/i', $query->sql)) {
                throw new \Illuminate\Database\QueryException(DB::getDefaultConnection(), $query->sql, [], new \PDOException('SQLSTATE[40P01]: Deadlock detected: 7 ERROR:  deadlock detected'));
            }
        });

        try {
            app(IndexManager::class)->flushAll();
            $this->fail('clear --all went on after a deadlock in its savepoint');
        } catch (\Illuminate\Database\DeadlockException) {
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->counts());
    }

    /**
     * Run $app in a forked process, on its own connection to the test database, and $clear here
     * once $app has called its first argument. $app's second argument waits until $clear has
     * started. Both connections give up a lock wait after 10 s, so a deadlock the database missed
     * fails the test instead of hanging it. Returns [$app's error, $clear's error].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function alongside(\Closure $app, \Closure $clear): array
    {
        // function_exists(), not extension_loaded(): disable_functions can remove them too.
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
            $this->markTestSkipped('The app transaction runs in a second process: pcntl_fork(), pcntl_waitpid() and posix_kill().');
        }
        config(['database.connections.app' => config('database.connections.integration')]);

        $base = sys_get_temp_dir() . '/fuzzy-clear-all-' . getmypid() . '-' . uniqid();
        [$ready, $clearing, $failed] = ["{$base}.ready", "{$base}.clearing", "{$base}.error"];

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork() failed.');
        }
        if ($pid === 0) {
            try {
                DB::setDefaultConnection('app'); // its own connection: the parent's socket is not shared
                $this->boundLockWaits(10);
                $app(fn () => touch($ready), fn () => self::waitFor($clearing, 30));
            } catch (\Throwable $e) {
                file_put_contents($failed, (string) $e);
            } finally {
                posix_kill(getmypid(), SIGKILL); // no destructors, no PHPUnit shutdown: they belong to the parent
            }
        }

        $started    = self::waitFor($ready, 30);
        $clearError = null;
        try {
            $this->boundLockWaits(10);
            touch($clearing);
            $clear();
        } catch (\Throwable $e) {
            $clearError = (string) $e;
        }
        pcntl_waitpid($pid, $status);

        $appError = is_file($failed) ? file_get_contents($failed) : null;
        foreach ([$ready, $clearing, $failed] as $file) {
            @unlink($file);
        }
        $this->assertTrue($started || $appError !== null, 'the app transaction never got under way');

        return [$appError, $clearError];
    }

    /** Make the default connection give up a lock wait after $seconds. */
    private function boundLockWaits(int $seconds): void
    {
        match ($this->dbDriver) {
            'mysql', 'mariadb' => DB::unprepared("SET SESSION lock_wait_timeout = {$seconds}, innodb_lock_wait_timeout = {$seconds}"),
            'pgsql'            => DB::unprepared("SET lock_timeout = '{$seconds}s'"),
            'sqlsrv'           => DB::unprepared('SET LOCK_TIMEOUT ' . ($seconds * 1000)),
            default            => null,
        };
    }

    /** Wait until $file exists, at most $seconds; true if it appeared. */
    private static function waitFor(string $file, float $seconds): bool
    {
        $until = microtime(true) + $seconds;
        while (!file_exists($file)) {
            if (microtime(true) >= $until) {
                return false;
            }
            usleep(5_000);
        }

        return true;
    }

    /**
     * Drop SQL Server login $user and its database user. Microsoft's ODBC driver on Linux keeps a
     * purged connection pooled, so the login still has a session ("Could not drop login … as the
     * user is currently logged in"): end its sessions first.
     */
    private function dropSqlsrvLogin(string $user): void
    {
        DB::unprepared("DECLARE @kill nvarchar(max) = N''; SELECT @kill += N'KILL ' + CAST(session_id AS nvarchar(10)) + N';' FROM sys.dm_exec_sessions WHERE login_name = N'{$user}'; EXEC (@kill);"
            . " IF USER_ID('{$user}') IS NOT NULL DROP USER [{$user}]; IF SUSER_ID('{$user}') IS NOT NULL DROP LOGIN [{$user}]");
    }
}
