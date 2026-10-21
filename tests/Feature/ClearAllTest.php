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
 * the tables are busy, saying so on the console. MySQL truncates under LOCK TABLES … WRITE with a
 * one second wait, and deletes when the tables are busy. ER-228: MySQL and MariaDB try the truncate
 * 3 times, 2 s apart, and their DELETEs, and a flush's of one model, run in bounded transactions.
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

        $this->clearAll(false);

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
     * SQL Server behind the index write lock; MySQL as MariaDB, with a one second lock wait and no NOWAIT. flushAll() says whether it truncated.
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
                'truncate table fuzzy_index_terms',
                'delete from fuzzy_index_meta',
                'unlock tables',
            ],
            'mysql' => [
                'lock tables fuzzy_index_postings write, fuzzy_index_documents write, fuzzy_index_meta write, fuzzy_index_model_terms write, fuzzy_index_terms write',
                'truncate table fuzzy_index_postings',
                'truncate table fuzzy_index_documents',
                'truncate table fuzzy_index_model_terms',
                'truncate table fuzzy_index_terms',
                'delete from fuzzy_index_meta',
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
            default => array_map(fn (string $table) => "delete from {$table}", self::TABLES), // SQLite: DELETE without WHERE is its truncate
        };
        $this->assertSame($expected, $statements);
        $this->assertTrue($truncated);
        $this->assertSame(0, DB::table('fuzzy_index_postings')->count());
        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->assertSame(1, (int) DB::scalar('select @@session.foreign_key_checks'), 'foreign_key_checks is back on');
        }
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
     * truncates the referenced words, so only there it says it deleted.
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

            $this->clearAll($this->dbDriver === 'pgsql');

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
     * transaction, so it checks the permission first. MySQL takes LOCK TABLES as MariaDB does.
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
        $this->assertNotEmpty(preg_grep('/^delete from fuzzy_index_postings\b/', $statements), implode("\n", $statements));
        // MySQL: such a user cannot read performance_schema, so the tables count as in use (ER-228).
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
     * flush($model), which `rebuild --fresh` runs, truncates the big tables when its type is the only
     * one with documents, checked under the lock the truncation holds; SQLite deletes. The model
     * types stay, and the type's meta row goes as it always did.
     */
    public function test_flush_truncates_when_the_model_is_the_only_one_indexed(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $dictionaries = $this->dictionaries();

        $statements = $this->statements(fn () => app(IndexManager::class)->flush(User::class));

        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame($dictionaries, $this->dictionaries());
        $this->assertSame($this->dbDriver !== 'sqlite', (bool) preg_grep('/^truncate /', $statements), implode("\n", $statements));
        app(IndexManager::class)->indexBatch(User::all());
        $this->assertGreaterThan(0, User::search('john')->useInvertedIndex()->get()->count());
    }

    /** With a second type indexed, flush($model) deletes only its own rows. */
    public function test_flush_deletes_only_its_own_rows_when_another_model_is_indexed(): void
    {
        $this->indexBoth();
        $productType = DB::table('fuzzy_index_model_types')->where('model_type', Product::class)->value('id');
        $keep        = [
            'documents'   => DB::table('fuzzy_index_documents')->where('model_type', Product::class)->count(),
            'postings'    => DB::table('fuzzy_index_postings')->where('model_type_id', $productType)->count(),
            'model_terms' => DB::table('fuzzy_index_model_terms')->where('model_type_id', $productType)->count(),
            'meta'        => DB::table('fuzzy_index_meta')->where('model_type', Product::class)->count(),
        ];
        $this->assertGreaterThan(0, min($keep));

        $statements = $this->statements(fn () => app(IndexManager::class)->flush(User::class));

        $this->assertEmpty(preg_grep('/^truncate /', $statements), implode("\n", $statements));
        $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
        $this->assertSame(0, DB::table('fuzzy_index_meta')->where('model_type', User::class)->count());
        $this->assertSame($keep, [
            'documents'   => DB::table('fuzzy_index_documents')->where('model_type', Product::class)->count(),
            'postings'    => DB::table('fuzzy_index_postings')->where('model_type_id', $productType)->count(),
            'model_terms' => DB::table('fuzzy_index_model_terms')->where('model_type_id', $productType)->count(),
            'meta'        => DB::table('fuzzy_index_meta')->where('model_type', Product::class)->count(),
        ]);
        $this->assertSame(DB::table('fuzzy_index_postings')->count(), DB::table('fuzzy_index_postings')->where('model_type_id', $productType)->count());
    }

    /**
     * On MySQL a reader's open transaction holds a metadata lock on the postings, which LOCK TABLES
     * would wait for; MariaDB's NOWAIT is refused. ER-228: the truncate is tried 3 times, 2 s apart
     * (MySQL waits at most one second a try, and only once no other session holds a table), then
     * clear --all deletes in bounded batches, each its own transaction, and says to run it again
     * when the index is idle. The reader is not killed (it commits afterwards), and MySQL's session
     * lock_wait_timeout is back where it was, after this path and after the truncating one.
     */
    public function test_mysql_and_mariadb_give_up_the_truncate_after_three_tries_and_delete_in_batches(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL\'s and MariaDB\'s truncate tries and batched DELETEs.');
        }
        $this->indexBoth();
        $dictionaries = $this->dictionaries();
        $this->boundLockWaits(7);
        $this->flushRows(5);

        config(['database.connections.reader' => config('database.connections.integration')]);
        $reader = DB::connection('reader');
        $reader->beginTransaction();
        try {
            $reader->table('fuzzy_index_postings')->count();

            $started    = microtime(true);
            $statements = $this->statements(function () {
                $this->artisan('fuzzy-search:clear', ['--all' => true])
                    ->expectsOutputToContain(self::ROW_BY_ROW . ', not truncated, because the index tables were busy (or TRUNCATE is not allowed). On a large index, run it again when the index is idle, so it truncates, before rebuilding.')
                    ->assertExitCode(0)
                    ->run();
            });
            $elapsed = microtime(true) - $started;
            $this->assertGreaterThan(3.9, $elapsed, 'it tried 3 times, 2 s apart');
            $this->assertLessThan(10, $elapsed, 'it waited for the reader');
            if ($this->dbDriver === 'mysql') {
                $this->assertSame(7, (int) DB::scalar('select @@session.lock_wait_timeout'), 'after the fallback');
            }
            $this->assertEmpty(preg_grep('/^truncate /', $statements));
            $this->assertGreaterThan(1, count(preg_grep('/^delete from fuzzy_index_postings\b/', $statements)), implode("\n", $statements));
            $this->assertCleared($dictionaries);
            $reader->commit(); // not killed
        } finally {
            if ($reader->transactionLevel() > 0) {
                $reader->rollBack();
            }
            DB::purge('reader');
        }

        $this->indexBoth();
        $this->clearAll(false);
        if ($this->dbDriver === 'mysql') {
            $this->assertSame(7, (int) DB::scalar('select @@session.lock_wait_timeout'), 'after the truncate');
        }
    }

    public static function truncations(): array
    {
        return ['clear --all' => [true], 'flush of the only model indexed' => [false]];
    }

    /**
     * ER-228: a reader that holds the tables for 1.5 s does not send clear --all, or the flush of
     * the only model indexed, to the DELETEs: the truncate is tried again 2 s later and succeeds.
     */
    #[DataProvider('truncations')]
    public function test_mysql_and_mariadb_truncate_on_a_later_try_once_the_reader_has_finished(bool $all): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL\'s and MariaDB\'s truncate tries.');
        }
        $all ? $this->indexBoth() : app(IndexManager::class)->indexBatch(User::all());
        $dictionaries = $this->dictionaries();

        [$readerError, $clearError] = $this->alongside(
            function (\Closure $ready) {
                DB::transaction(function () use ($ready) {
                    DB::table('fuzzy_index_postings')->count();
                    $ready();
                    usleep(1_500_000);
                });
            },
            function () use ($all, &$statements, &$truncated) {
                $statements = $this->statements(function () use ($all, &$truncated) {
                    $all ? $truncated = app(IndexManager::class)->flushAll() : app(IndexManager::class)->flush(User::class);
                });
            }
        );

        $this->assertNull($readerError);
        $this->assertNull($clearError);
        if ($all) {
            $this->assertTrue($truncated);
        }
        $this->assertNotEmpty(preg_grep('/^truncate /', $statements), implode("\n", $statements));
        $this->assertEmpty(preg_grep('/^delete from fuzzy_index_postings\b/', $statements), implode("\n", $statements));
        $this->assertCleared($dictionaries);
    }

    /**
     * ER-228 (ruling b8): where MySQL cannot read performance_schema.metadata_locks (performance_schema
     * off, no privilege, MySQL before 8.0.16, any error), the index tables count as in use: no LOCK
     * TABLES, which could deadlock a reader's transaction, and no tries; the DELETEs run at once,
     * in batches, and the command says to run it again when the index is idle.
     */
    #[DataProvider('truncations')]
    public function test_mysql_deletes_in_batches_at_once_when_it_cannot_tell_whether_the_tables_are_in_use(bool $all): void
    {
        if ($this->dbDriver !== 'mysql') {
            $this->markTestSkipped('MySQL\'s metadata lock check.');
        }
        $all ? $this->indexBoth() : app(IndexManager::class)->indexBatch(User::all());
        $dictionaries = $this->dictionaries();
        $this->flushRows(5);
        DB::connection()->beforeExecuting(function (string $sql) {
            if (str_contains($sql, 'performance_schema')) {
                throw new \Illuminate\Database\QueryException(DB::getDefaultConnection(), $sql, [], new \PDOException("SQLSTATE[42000]: Syntax error or access violation: 1142 SELECT command denied to user 'app'@'%' for table 'metadata_locks'"));
            }
        });

        $started    = microtime(true);
        $statements = $this->statements(function () use ($all) {
            $all
                ? $this->artisan('fuzzy-search:clear', ['--all' => true])->expectsOutputToContain('run it again when the index is idle')->assertExitCode(0)->run()
                : app(IndexManager::class)->flush(User::class);
        });

        $this->assertLessThan(1.5, microtime(true) - $started, 'no tries');
        $this->assertEmpty(preg_grep('/^(lock tables|truncate )/', $statements), implode("\n", $statements));
        $this->assertGreaterThan(1, count(preg_grep('/^delete from fuzzy_index_postings\b/', $statements)), implode("\n", $statements));
        $this->assertCleared($dictionaries);
    }

    /**
     * ER-228: with another model indexed, flush($model) on MySQL/MariaDB deletes its postings in
     * batches by model_id, each its own transaction, then its words' counts, its documents and its
     * meta row; the other model's rows stay, and each shared word's doc_count ends as a fresh count
     * of the documents holding it. Every database ends exact; only MySQL/MariaDB batch.
     */
    public function test_a_flush_beside_another_model_deletes_its_rows_in_batches_and_keeps_the_shared_counts_exact(): void
    {
        $this->indexShared();
        $keep = $this->productRows();
        $this->flushRows(5);
        $commits = 0;
        DB::connection()->getEventDispatcher()->listen(\Illuminate\Database\Events\TransactionCommitted::class, function () use (&$commits) {
            $commits++;
        });

        $statements = $this->statements(fn () => app(IndexManager::class)->flush(User::class));

        $this->assertFlushedBesideProducts($keep);
        $this->assertEmpty(preg_grep('/^truncate /', $statements), implode("\n", $statements));
        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $batches = count(preg_grep('/^delete from fuzzy_index_postings\b/', $statements));
            $this->assertGreaterThan(1, $batches, implode("\n", $statements));
            $this->assertGreaterThanOrEqual($batches, $commits, 'a transaction per batch');
        }
    }

    /** @return array<string, array{string}> the table whose delete the flush is interrupted after */
    public static function interruptions(): array
    {
        return [
            'between postings batches'  => ['postings'],
            'in a chunk of words'       => ['model_terms'],
            'between documents batches' => ['documents'],
        ];
    }

    /**
     * ER-228: a batched flush interrupted part way (the process dies, a statement fails) has
     * committed the batches before; run again, it finishes, and the index ends as if it had run once.
     */
    #[DataProvider('interruptions')]
    public function test_a_batched_flush_interrupted_part_way_ends_exact_when_run_again(string $table): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB flush in batches; elsewhere the flush is one transaction.');
        }
        $this->indexShared();
        $keep = $this->productRows();
        $this->flushRows(5);
        $seen = 0;
        DB::listen(function ($query) use ($table, &$seen) {
            if (preg_match('/^\s*delete from [`"\[]?fuzzy_index_' . $table . '\b/i', $query->sql) && ++$seen === 2 - (int) ($table === 'model_terms')) {
                throw new \RuntimeException('interrupted');
            }
        });

        $error = null;
        try {
            app(IndexManager::class)->flush(User::class);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
        $this->assertSame('interrupted', $error);
        $this->assertSame(0, DB::transactionLevel());

        app(IndexManager::class)->flush(User::class);

        $this->assertFlushedBesideProducts($keep);
    }

    /** @return array<string, array{string}> the table a batch deleted from, after whose commit another session writes */
    public static function flushBatches(): array
    {
        return [
            'after a postings batch'  => ['postings'],
            'after a chunk of words'  => ['model_terms'],
            'after a documents batch' => ['documents'],
        ];
    }

    /**
     * N-B2: 2.0.1 flushed a model in one transaction; the batched flush of MySQL/MariaDB commits
     * each batch, so a write of the model between two of them (a queue worker re-indexing a saved
     * row, indexing a new one) kept postings and a document the later batches had passed, and raised
     * the shared words' counts and the model's own for good. The flush ends with one transaction
     * that deletes what is left of the type and counts its words again: the type ends empty, and
     * every count a fresh count, as 2.0.1's single transaction left them.
     */
    #[DataProvider('flushBatches')]
    public function test_a_write_between_the_batches_of_a_flush_leaves_the_type_empty_and_the_counts_exact(string $table): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB flush in batches; elsewhere the flush is one transaction.');
        }
        $this->indexShared();
        $keep = $this->productRows();
        $this->flushRows(5);
        $ran = $this->writeBetweenBatches($table);

        app(IndexManager::class)->flush(User::class);

        $this->assertTrue($ran(), 'the write ran between two batches');
        $this->assertFlushedBesideProducts($keep);
    }

    /**
     * The final transaction of a batched flush or clear, on a server set to READ COMMITTED: its
     * DELETE of the documents takes no gap lock there, so a write of the same type could claim a
     * new document while the transaction ran, and the postings and model terms it left were deleted
     * without their words being counted again. The transaction runs at REPEATABLE READ, whatever
     * the session was set to, and the session is put back.
     */
    public function test_a_write_during_the_final_transaction_of_a_flush_at_read_committed_leaves_the_counts_exact(): void
    {
        $keep = $this->readCommittedWriteDuringTheFinalTransaction('select distinct [`"]?term_id[`"]? from [`"]?fuzzy_index_postings[`"]? where [`"]?model_type_id[`"]? = \\?');

        app(IndexManager::class)->flush(User::class);

        $this->assertSame('READ-COMMITTED', $this->isolation(), 'the session setting is put back');
        $this->assertFlushedBesideProducts($keep);
    }

    /**
     * SQLite: a flush by type begins its transaction with reads and then writes, and a write by
     * another connection in between makes SQLite refuse the first DELETE ("database is locked").
     * The transaction retries it, as the index writes do, and the flush finishes.
     */
    public function test_a_flush_by_type_on_sqlite_retries_a_locked_database(): void
    {
        if ($this->dbDriver !== 'sqlite') {
            $this->markTestSkipped('Only SQLite refuses a write after a read this way.');
        }
        $this->indexBoth();
        $keep   = $this->productRows();
        $failed = 0;
        DB::listen(function ($query) use (&$failed) {
            if ($failed === 0 && preg_match('/^\s*delete from [`"\[]?fuzzy_index_/i', $query->sql) && ++$failed) {
                throw new \Illuminate\Database\QueryException(DB::getDefaultConnection(), $query->sql, [], new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
            }
        });

        app(IndexManager::class)->flush(User::class);

        $this->assertSame(1, $failed, 'the first DELETE was refused once');
        $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
        $this->assertSame($keep, $this->productRows());
    }

    private function isolation(): string
    {
        try {
            return (string) DB::scalar('select @@session.transaction_isolation');
        } catch (\Throwable) {
            return (string) DB::scalar('select @@session.tx_isolation');
        }
    }

    /**
     * Set the session to READ COMMITTED, and have another session add a user and index it right
     * after the statement matching $after, in the final transaction (after the flush has read the
     * words it will count again). Returns what the other model's rows were.
     */
    private function readCommittedWriteDuringTheFinalTransaction(string $after): array
    {
        $model = User::class;
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB flush and clear in batches, and set an isolation level.');
        }
        $this->indexShared();
        $keep = $this->productRows();
        $this->flushRows(5);
        config(['database.connections.app' => config('database.connections.integration')]);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $done = false;
        DB::listen(function ($query) use (&$done, $model, $after) {
            if ($done || $query->connectionName !== 'integration' || !preg_match('/^\s*' . $after . '\s*$/i', $query->sql)) {
                return;
            }
            $done = true;
            DB::setDefaultConnection('app');
            try {
                // At REPEATABLE READ the DELETE's gap locks hold this write off: it gives up at once.
                DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
                $text = $model === User::class ? 'name' : 'title';
                $row  = $model === User::class ? ['email' => 'quokka@example.com'] : [];
                $id   = DB::table((new $model())->getTable())->insertGetId([$text => 'Quokka Galaxy', 'created_at' => now(), 'updated_at' => now()] + $row);
                app(IndexManager::class)->indexBatch($model::query()->whereIn('id', [1, $id])->get());
            } catch (\Throwable) {
                // held off: the flush's transaction is what the test is about
            } finally {
                DB::setDefaultConnection('integration');
            }
        });

        return $keep;
    }

    /** @return array<string, array{string}> */
    public static function clearBatches(): array
    {
        return [
            'after a documents batch' => ['documents'],
            'after a batch of words'  => ['terms'],
        ];
    }

    /** N-B2, clear --all: a write between its batches leaves nothing behind either. */
    #[DataProvider('clearBatches')]
    public function test_a_write_between_the_batches_of_clear_all_leaves_the_index_empty(string $table): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB clear in batches; elsewhere the clear is one transaction.');
        }
        $this->indexBoth();
        $dictionaries = $this->dictionaries();
        $this->flushRows(5);
        // LOCK TABLES refused, and not for a lock wait: no more tries, the batches run.
        DB::connection()->beforeExecuting(function (string $sql) {
            if (preg_match('/^\s*lock tables/i', $sql)) {
                throw new \Illuminate\Database\QueryException(DB::getDefaultConnection(), $sql, [], new \PDOException('SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied'));
            }
        });
        $ran = $this->writeBetweenBatches($table, Product::class); // the first model type the clear's batches delete

        $this->assertFalse(app(IndexManager::class)->flushAll());

        $this->assertTrue($ran(), 'the write ran between two batches');
        $this->assertCleared($dictionaries);
    }

    /**
     * Once a batch that deleted from fuzzy_index_$table has committed, save row 1 of $model with
     * other words and add a row, and index both from another session, as a queue worker would
     * between two batches. Returns a closure that tells whether it ran.
     */
    private function writeBetweenBatches(string $table, string $model = User::class): \Closure
    {
        config(['database.connections.app' => config('database.connections.integration')]);
        $last = null;
        $done = false;
        DB::listen(function ($query) use (&$last) {
            if ($query->connectionName === 'integration' && preg_match('/^\s*delete from [`"\[]?fuzzy_index_(\w+)/i', $query->sql, $match)) {
                $last = $match[1];
            }
        });
        DB::connection()->getEventDispatcher()->listen(\Illuminate\Database\Events\TransactionCommitted::class, function ($event) use ($table, $model, &$last, &$done) {
            if ($done || $event->connectionName !== 'integration' || $last !== $table) {
                return;
            }
            $done = true;
            DB::setDefaultConnection('app');
            try {
                $text = $model === User::class ? 'name' : 'title';
                $row  = $model === User::class ? ['email' => 'quokka@example.com'] : [];
                DB::table((new $model())->getTable())->where('id', 1)->update([$text => 'Zebra Laptop']);
                $id = DB::table((new $model())->getTable())->insertGetId([$text => 'Quokka Galaxy', 'created_at' => now(), 'updated_at' => now()] + $row);
                app(IndexManager::class)->indexBatch($model::query()->whereIn('id', [1, $id])->get());
            } finally {
                DB::setDefaultConnection('integration');
            }
        });

        return function () use (&$done) {
            return $done;
        };
    }

    /** @return array<string, array{bool}> whether the replica stopped before the flushed model was indexed, or before the other */
    public static function replicas(): array
    {
        return [
            'the replica has not seen the other model'   => [false],
            'the replica has not seen the flushed model' => [true],
        ];
    }

    /**
     * N-B1: on a connection with a read/write split, without sticky (a queue worker's first query),
     * MySQL/MariaDB read whether another model had documents, and listed the rows of each batch,
     * outside a transaction: from the replica. One that had not seen the products yet let the flush
     * of the users truncate them; one that had not seen the users left their words behind, and
     * deleted their postings in one statement. Every read of the clear now goes to the write
     * connection.
     */
    #[DataProvider('replicas')]
    public function test_a_flush_on_a_read_write_split_reads_the_write_connection(bool $usersLast): void
    {
        $replica = $this->replicaOrSkip();
        try {
            DB::table('users')->insert([
                ['name' => 'Apple Laptop', 'email' => 'tablet@example.com', 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Galaxy Phone', 'email' => 'pro@example.com', 'created_at' => now(), 'updated_at' => now()],
            ]);
            app(IndexManager::class)->indexBatch($usersLast ? Product::all() : User::all());
            $this->freezeReplica($replica);
            app(IndexManager::class)->indexBatch($usersLast ? User::all() : Product::all());
            $keep = $this->productRows();
            $this->flushRows(5);

            $statements = $this->onReplicaSplit($replica, fn () => $this->statements(fn () => app(IndexManager::class)->flush(User::class)));

            $this->assertFlushedBesideProducts($keep);
            $this->assertEmpty(preg_grep('/^truncate /', $statements), implode("\n", $statements));
            $this->assertGreaterThan(2, count(preg_grep('/^delete from fuzzy_index_postings\b/', $statements)), 'batches of the rows the write connection holds');
        } finally {
            DB::unprepared("DROP DATABASE IF EXISTS `{$replica}`");
        }
    }

    /**
     * N-B1, clear --all: while another session's transaction holds a read of the index, MySQL looked
     * for its lock on the replica, found none and sent LOCK TABLES, which waited behind it; and the
     * batches listed the models from a replica that held none, so every document stayed.
     */
    public function test_clear_all_on_a_read_write_split_reads_the_write_connection(): void
    {
        $replica = $this->replicaOrSkip();
        try {
            $this->freezeReplica($replica);
            $this->indexBoth();
            $dictionaries = $this->dictionaries();
            $this->flushRows(5);
            $locks = [];

            DB::beginTransaction();
            try {
                DB::table('fuzzy_index_documents')->count(); // its metadata lock is held to its end
                $truncated = $this->onReplicaSplit($replica, function () use (&$locks) {
                    DB::connection()->beforeExecuting(function (string $sql) use (&$locks) {
                        if (preg_match('/^\s*lock tables/i', $sql)) {
                            $locks[] = $sql;
                        }
                    });

                    return app(IndexManager::class)->flushAll();
                });
            } finally {
                DB::rollBack();
            }

            $this->assertFalse($truncated);
            $this->assertCleared($dictionaries);
            if ($this->dbDriver === 'mysql') {
                $this->assertSame([], $locks, 'the lock check reads the write connection, which sees the reader');
            }
        } finally {
            DB::unprepared("DROP DATABASE IF EXISTS `{$replica}`");
        }
    }

    /**
     * N-B1: a truncate sets foreign_key_checks and lock_wait_timeout on its session and puts them
     * back. It read the values to put back on the read connection, another session, so the write
     * connection kept the read session's values for the rest of the process.
     */
    public function test_a_truncate_on_a_read_write_split_gives_the_write_session_its_settings_back(): void
    {
        $replica = $this->replicaOrSkip();
        try {
            app(IndexManager::class)->indexBatch(User::all());
            $this->freezeReplica($replica);

            [$statements, $settings] = $this->onReplicaSplit($replica, function () {
                DB::unprepared('SET SESSION foreign_key_checks = 0, lock_wait_timeout = 7');
                $statements = $this->statements(fn () => app(IndexManager::class)->flush(User::class));

                return [$statements, [(int) DB::scalar('select @@session.foreign_key_checks', [], false), (int) DB::scalar('select @@session.lock_wait_timeout', [], false)]];
            });

            $this->assertNotEmpty(preg_grep('/^truncate table fuzzy_index_terms\b/', $statements), implode("\n", $statements));
            $this->assertSame([0, 7], $settings);
        } finally {
            DB::unprepared("DROP DATABASE IF EXISTS `{$replica}`");
        }
    }

    /**
     * N-B1: where a table of the app's references the words, a truncate deletes them, so the
     * reference cascades. It looked for such a key on the read connection's database, the replica,
     * found none, and truncated the words, leaving the app's row pointing at nothing.
     */
    public function test_a_truncate_on_a_read_write_split_sees_the_apps_foreign_key_to_the_words(): void
    {
        $replica = $this->replicaOrSkip();
        Schema::dropIfExists('split_refs');
        try {
            app(IndexManager::class)->indexBatch(User::all());
            $this->freezeReplica($replica);
            Schema::create('split_refs', function ($table) {
                $table->id();
                $table->foreignId('term_id')->constrained('fuzzy_index_terms')->cascadeOnDelete();
            });
            DB::table('split_refs')->insert(['term_id' => DB::table('fuzzy_index_terms')->min('id')]);

            $this->onReplicaSplit($replica, fn () => app(IndexManager::class)->flush(User::class));

            $this->assertSame(0, DB::table('fuzzy_index_terms')->count());
            $this->assertSame(0, DB::table('split_refs')->count(), 'the words\' DELETE cascades: no row of the app\'s points at nothing');
        } finally {
            Schema::dropIfExists('split_refs');
            DB::unprepared("DROP DATABASE IF EXISTS `{$replica}`");
        }
    }

    /**
     * N-B1: with read and write on one server (a split whose read host is the primary, or a proxy),
     * the read connection is a second session: a read of the clear between LOCK TABLES and UNLOCK
     * TABLES waited behind the write session's own table locks, for lock_wait_timeout (a year by
     * default), so `clear <model>` hung. clear --all, clear <model> and rebuild --fresh, on both
     * the truncate and the batched path, send no statement through the read connection. Its
     * session gives up a lock wait after 5 s here, so a read there fails instead of hanging.
     */
    public function test_the_clears_and_rebuild_fresh_send_nothing_through_the_read_connection(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The counted read connection is a MySQL/MariaDB session; elsewhere every read of a clear runs in its transaction, on the write connection.');
        }
        config(['database.connections.split' => ['read' => [], 'write' => [], 'sticky' => false] + config('database.connections.integration')]);
        $config = config('database.connections.integration');
        $read   = new ReadCountingPdo(
            "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4",
            $config['username'],
            $config['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]
        );
        $read->exec('SET SESSION lock_wait_timeout = 5, innodb_lock_wait_timeout = 5');
        $run = function (string $command, array $arguments) use ($read): array {
            $read->reads = [];
            DB::setDefaultConnection('split');
            try {
                DB::connection()->setReadPdo($read);
                $this->artisan($command, $arguments)->assertExitCode(0)->run();
            } finally {
                DB::setDefaultConnection('integration');
            }

            return $read->reads;
        };

        try {
            $this->indexBoth();
            $this->flushRows(5);
            $this->assertSame([], $run('fuzzy-search:clear', ['model' => User::class]), 'clear <model> beside another model: batches');
            $this->assertSame([], $run('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true]), 'rebuild --fresh beside another model');
            $this->assertGreaterThan(0, DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
            $this->assertSame([], $run('fuzzy-search:clear', ['--all' => true]), 'clear --all: LOCK TABLES and TRUNCATE');
            $this->assertSame(0, DB::table('fuzzy_index_documents')->count());

            app(IndexManager::class)->indexBatch(User::all());
            $this->assertSame([], $run('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true]), 'rebuild --fresh of the only model: LOCK TABLES and TRUNCATE');
            $this->assertSame([], $run('fuzzy-search:clear', ['model' => User::class]), 'clear <model> of the only model: LOCK TABLES and TRUNCATE');
            $this->assertSame(0, DB::table('fuzzy_index_documents')->count());
        } finally {
            DB::purge('split');
        }
    }

    /** The name of a second database on the test server, for a replica (MySQL/MariaDB); skips elsewhere. */
    private function replicaOrSkip(): string
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MySQL and MariaDB clear outside a transaction; elsewhere every read of the clear is in its transaction, on the write connection.');
        }
        $replica = DB::connection()->getDatabaseName() . '_replica';
        try {
            DB::unprepared("CREATE DATABASE IF NOT EXISTS `{$replica}`");
        } catch (\Illuminate\Database\QueryException $e) {
            $this->markTestSkipped('The test connection cannot create a second database: ' . $e->getMessage());
        }

        return $replica;
    }

    /** Copy the index tables as they are now into $replica: a replica that applies no write after this. */
    private function freezeReplica(string $replica): void
    {
        $primary = DB::connection()->getDatabaseName();
        foreach ([...self::TABLES, 'fuzzy_index_model_types', 'fuzzy_index_columns'] as $table) {
            DB::unprepared("DROP TABLE IF EXISTS `{$replica}`.`{$table}`");
            DB::unprepared("CREATE TABLE `{$replica}`.`{$table}` LIKE `{$primary}`.`{$table}`");
            DB::unprepared("INSERT INTO `{$replica}`.`{$table}` SELECT * FROM `{$primary}`.`{$table}`");
        }
    }

    /** Run $run on a default connection that reads from $replica and writes to the test database, without sticky. */
    private function onReplicaSplit(string $replica, \Closure $run): mixed
    {
        config(['database.connections.split' => ['read' => ['database' => $replica], 'write' => [], 'sticky' => false] + config('database.connections.integration')]);
        DB::setDefaultConnection('split');
        try {
            return $run();
        } finally {
            DB::setDefaultConnection('integration');
            DB::purge('split');
        }
    }

    /** Index the users and the products, with words both hold (apple, laptop, galaxy …). */
    private function indexShared(): void
    {
        DB::table('users')->insert([
            ['name' => 'Apple Laptop', 'email' => 'tablet@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Galaxy Phone', 'email' => 'pro@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->indexBoth();
        $productType = DB::table('fuzzy_index_model_types')->where('model_type', Product::class)->value('id');
        $shared      = DB::table('fuzzy_index_model_terms')->where('model_type_id', $productType)->pluck('term_id')
            ->intersect(DB::table('fuzzy_index_model_terms')->where('model_type_id', '<>', $productType)->pluck('term_id'));
        $this->assertGreaterThan(2, $shared->count(), 'the models share words');
    }

    /** @return array<string, mixed> the product rows of each index table */
    private function productRows(): array
    {
        $productType = DB::table('fuzzy_index_model_types')->where('model_type', Product::class)->value('id');
        $rows        = function (string $table, string $column, $value, array $order) {
            $query = DB::table($table)->where($column, $value);
            foreach ($order as $key) {
                $query->orderBy($key);
            }

            return $query->get()->map(fn ($row) => array_map('strval', (array) $row))->all();
        };

        return [
            'documents'   => $rows('fuzzy_index_documents', 'model_type', Product::class, ['model_id']),
            'postings'    => $rows('fuzzy_index_postings', 'model_type_id', $productType, ['model_id', 'term_id', 'column_id']),
            'model_terms' => $rows('fuzzy_index_model_terms', 'model_type_id', $productType, ['term_id']),
            'meta'        => $rows('fuzzy_index_meta', 'model_type', Product::class, ['model_type']),
        ];
    }

    /**
     * After flush(User): no user row anywhere, every product row as it was, every word held by a
     * posting, and each word's doc_count, and each model's own count of it, the number of documents
     * holding it.
     */
    private function assertFlushedBesideProducts(array $keep): void
    {
        $userType = DB::table('fuzzy_index_model_types')->where('model_type', User::class)->value('id');
        $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
        $this->assertSame(0, DB::table('fuzzy_index_postings')->where('model_type_id', $userType)->count());
        $this->assertSame(0, DB::table('fuzzy_index_model_terms')->where('model_type_id', $userType)->count());
        $this->assertSame(0, DB::table('fuzzy_index_meta')->where('model_type', User::class)->count());
        $this->assertSame($keep, $this->productRows());

        $held = fn (array $columns) => DB::query()
            ->fromSub(DB::table('fuzzy_index_postings')->distinct()->select(['term_id', 'model_type_id', 'model_id']), 'held')
            ->select($columns)->selectRaw('count(*) as documents')->groupBy($columns)->get()
            ->mapWithKeys(fn ($row) => [implode(':', array_map(fn ($column) => $row->{$column}, $columns)) => (int) $row->documents])->sortKeys()->all();
        $counts = DB::table('fuzzy_index_terms')->pluck('doc_count', 'id')->mapWithKeys(fn ($count, $id) => [(string) $id => (int) $count])->sortKeys()->all();
        $own    = DB::table('fuzzy_index_model_terms')->get()
            ->mapWithKeys(fn ($row) => ["{$row->model_type_id}:{$row->term_id}" => (int) $row->doc_count])->sortKeys()->all();

        $this->assertSame($held(['term_id']), $counts, 'doc_count: a fresh count, and no word without a posting');
        $this->assertSame($held(['model_type_id', 'term_id']), $own, 'each model\'s own counts');
    }

    /** Make the flushes delete $rows rows a statement where they delete in batches (MySQL/MariaDB). */
    private function flushRows(int $rows): void
    {
        if (property_exists(IndexManager::class, 'flushRows')) {
            (new \ReflectionProperty(IndexManager::class, 'flushRows'))->setValue(app(IndexManager::class), $rows);
        }
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

/** A read connection that records each statement it prepares (every select Laravel sends). */
class ReadCountingPdo extends \PDO
{
    /** @var list<string> */
    public array $reads = [];

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->reads[] = $query;

        return parent::prepare($query, $options);
    }
}
