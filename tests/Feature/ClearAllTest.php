<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
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
 * column dictionaries, never commits a transaction of the caller's, and falls back to the DELETEs
 * where TRUNCATE is refused.
 */
class ClearAllTest extends TestCase
{
    private const TABLES = ['fuzzy_index_postings', 'fuzzy_index_documents', 'fuzzy_index_meta', 'fuzzy_index_terms'];

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

    /** The statements a clear --all sends, without quoting: the four tables', the locks and SQL Server's applock. */
    private function statements(\Closure $run): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $sql = strtolower(preg_replace('/\s+/', ' ', str_replace(['"', '`', '[', ']'], '', $query->sql)));
            if (str_contains($sql, 'sp_getapplock')) {
                $statements[] = 'sp_getapplock';
            } elseif (preg_match('/fuzzy_index_(postings|documents|meta|terms)|^(lock|unlock) tables?\b/', $sql)) {
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

        $this->artisan('fuzzy-search:clear', ['--all' => true])->assertExitCode(0);

        $this->assertCleared($dictionaries);
        // The dictionaries' ids still hold: the index fills again and searches.
        app(IndexManager::class)->indexBatch(User::all());
        $this->assertSame($dictionaries, $this->dictionaries());
        $this->assertGreaterThan(0, User::search('john')->useInvertedIndex()->get()->count());
    }

    /**
     * ER-187: the two big tables (postings, documents) are truncated, not deleted row by row.
     * PostgreSQL truncates all four in one statement, without CASCADE or RESTART IDENTITY; MySQL and
     * MariaDB under LOCK TABLES (their TRUNCATE commits); SQL Server behind the index write lock.
     */
    public function test_clear_all_truncates_the_big_tables(): void
    {
        $this->indexBoth();

        $statements = $this->statements(fn () => app(IndexManager::class)->flushAll());

        $expected = match ($this->dbDriver) {
            'pgsql' => [
                'lock table fuzzy_index_documents in share row exclusive mode',
                'truncate table fuzzy_index_postings, fuzzy_index_documents, fuzzy_index_meta, fuzzy_index_terms',
            ],
            'mysql', 'mariadb' => [
                'lock tables fuzzy_index_postings write, fuzzy_index_documents write, fuzzy_index_meta write, fuzzy_index_terms write',
                'truncate table fuzzy_index_postings',
                'truncate table fuzzy_index_documents',
                'delete from fuzzy_index_meta',
                'delete from fuzzy_index_terms',
                'unlock tables',
            ],
            'sqlsrv' => [
                'sp_getapplock',
                'truncate table fuzzy_index_postings',
                'truncate table fuzzy_index_documents',
                'delete from fuzzy_index_meta',
                'delete from fuzzy_index_terms',
            ],
            default => array_map(fn (string $table) => "delete from {$table}", self::TABLES), // SQLite: DELETE without WHERE is its truncate
        };
        $this->assertSame($expected, $statements);
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
            $statements = $this->statements(fn () => app(IndexManager::class)->flushAll());
            $this->assertSame(0, DB::table('fuzzy_index_postings')->count());
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, $this->counts());
        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->assertSame(array_map(fn (string $table) => "delete from {$table}", self::TABLES), $statements);
        }
    }

    /**
     * A table of the app's with a foreign key to the index makes TRUNCATE fail (PostgreSQL would
     * empty it with CASCADE); clear --all then deletes, and the app's rows stay.
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

            $this->artisan('fuzzy-search:clear', ['--all' => true])->assertExitCode(0);

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
     * transaction, so it checks the permission first. SQLite has no users.
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
                DB::unprepared("IF USER_ID('{$user}') IS NOT NULL DROP USER [{$user}]; IF SUSER_ID('{$user}') IS NOT NULL DROP LOGIN [{$user}]");
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
            $statements = $this->statements(fn () => $this->assertSame(0, Artisan::call('fuzzy-search:clear', ['--all' => true])));
        } finally {
            DB::setDefaultConnection('integration');
            DB::purge('rows_only');
            if ($mysql) {
                DB::unprepared("DROP USER IF EXISTS '{$user}'@'%'");
            } elseif ($this->dbDriver === 'sqlsrv') {
                DB::unprepared("DROP USER [{$user}]; DROP LOGIN [{$user}]");
            } else {
                DB::unprepared("DROP OWNED BY {$user}");
                DB::unprepared("DROP ROLE IF EXISTS {$user}");
            }
        }

        $this->assertCleared($dictionaries);
        // The refused statement is not logged (no QueryExecuted); the DELETEs that followed are.
        $this->assertContains('delete from fuzzy_index_postings', $statements);
        $this->assertSame($lockTables, in_array('unlock tables', $statements, true));
    }
}
