<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Concerns;

use Illuminate\Foundation\Application;

/**
 * Shared database wiring for every test base class.
 *
 * The driver is selected with the DB_TEST_DRIVER env var (default: sqlite in-memory).
 * For any other driver the DB_TEST_HOST / DB_TEST_DATABASE / DB_TEST_USERNAME vars are
 * required; when they are missing the test is skipped instead of failing, so a
 * developer without that database installed can still run the suite.
 *
 * Supported values: sqlite, mysql, mariadb, pgsql, sqlsrv.
 *
 *   DB_TEST_DRIVER=mysql DB_TEST_HOST=127.0.0.1 DB_TEST_DATABASE=fuzzy_test \
 *   DB_TEST_USERNAME=root DB_TEST_PASSWORD=secret vendor/bin/phpunit
 */
trait ConfiguresDatabaseConnection
{
    /** Resolved driver name for the current run (sqlite, mysql, mariadb, pgsql, sqlsrv). */
    protected string $dbDriver = 'sqlite';

    /** Package index tables in FK-safe drop order (children first). */
    protected const FUZZY_INDEX_TABLES = [
        'fuzzy_index_postings',
        'fuzzy_index_documents',
        'fuzzy_index_meta',
        'fuzzy_index_terms',
    ];

    protected function resolveTestDbDriver(): string
    {
        $driver = strtolower((string) env('DB_TEST_DRIVER', 'sqlite'));

        return $driver === '' ? 'sqlite' : $driver;
    }

    protected function usingRealDatabase(): bool
    {
        return $this->dbDriver !== 'sqlite';
    }

    /**
     * Configure database.default for the selected driver.
     */
    protected function configureTestDatabaseConnection(Application $app): void
    {
        $driver = $this->resolveTestDbDriver();
        $this->dbDriver = $driver;

        if ($driver === 'sqlite') {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => true,
            ]);

            return;
        }

        foreach (['DB_TEST_HOST', 'DB_TEST_DATABASE', 'DB_TEST_USERNAME'] as $var) {
            if (empty(env($var))) {
                $this->markTestSkipped(
                    "Test requires {$var} env var when DB_TEST_DRIVER={$driver}. " .
                    'Set DB_TEST_DRIVER + DB_TEST_* vars to run against a real database.'
                );
            }
        }

        $defaultPort = match ($driver) {
            'pgsql'  => 5432,
            'sqlsrv' => 1433,
            default  => 3306, // mysql, mariadb
        };

        // Laravel 10 has no dedicated "mariadb" driver; it talks to MariaDB through the
        // mysql driver. Keep $this->dbDriver = 'mariadb' so tests can still branch on it.
        $connectionDriver = $driver;
        if ($driver === 'mariadb' && !class_exists(\Illuminate\Database\MariaDbConnection::class)) {
            $connectionDriver = 'mysql';
        }

        $connection = [
            'driver'   => $connectionDriver,
            'host'     => env('DB_TEST_HOST', '127.0.0.1'),
            'port'     => (int) env('DB_TEST_PORT', $defaultPort),
            'database' => env('DB_TEST_DATABASE'),
            'username' => env('DB_TEST_USERNAME'),
            'password' => env('DB_TEST_PASSWORD', ''),
            'prefix'   => '',
        ];

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connection += [
                'charset'   => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict'    => true,
                'engine'    => null,
            ];
        } elseif ($driver === 'pgsql') {
            $connection += [
                'charset'  => 'utf8',
                'schema'   => 'public',
                'sslmode'  => 'prefer',
            ];
        } elseif ($driver === 'sqlsrv') {
            $connection += [
                'charset'                => 'utf8',
                'trust_server_certificate' => true,
                'encrypt'                => env('DB_TEST_ENCRYPT', 'no'),
            ];
        }

        $app['config']->set('database.default', 'integration');
        $app['config']->set('database.connections.integration', $connection);
    }

    /**
     * On a real database a previous crashed run can leave the package tables behind,
     * which makes `migrate` fail with "table already exists". Drop them (and their
     * migration rows) before the migrator runs. No-op on sqlite :memory:.
     */
    protected function dropLeftoverFuzzyIndexTables(): void
    {
        if (!$this->usingRealDatabase()) {
            return;
        }

        $schema = $this->app['db']->connection()->getSchemaBuilder();

        // FUZZY_INDEX_TABLES lists children before parents, so plain drops are FK-safe on
        // every driver. (disable/enableForeignKeyConstraints is deliberately not used: on
        // PostgreSQL it emits "SET CONSTRAINTS can only be used in transaction blocks".)
        foreach (self::FUZZY_INDEX_TABLES as $table) {
            $schema->dropIfExists($table);
        }

        if ($schema->hasTable('migrations')) {
            $this->app['db']->table('migrations')
                ->where('migration', 'like', '%fuzzy_index%')
                ->delete();
        }
    }
}
