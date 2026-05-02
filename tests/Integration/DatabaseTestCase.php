<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearchServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base for integration tests. Reads DB config from env vars set by CI.
 * Skips automatically when the target DB env vars are not set (safe for local dev).
 */
abstract class DatabaseTestCase extends BaseTestCase
{
    protected string $dbDriver = 'sqlite';

    protected function getPackageProviders($app): array
    {
        return [FuzzySearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        $driver = env('DB_TEST_DRIVER', 'sqlite');
        $this->dbDriver = $driver;

        parent::setUp();
        $this->setUpSchema();
        $this->seedFixtures();
    }

    protected function defineEnvironment($app): void
    {
        $driver = env('DB_TEST_DRIVER', 'sqlite');

        if ($driver === 'sqlite') {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ]);
        } else {
            $required = ['DB_TEST_HOST', 'DB_TEST_DATABASE', 'DB_TEST_USERNAME'];
            foreach ($required as $var) {
                if (empty(env($var))) {
                    $this->markTestSkipped(
                        "Integration test requires {$var} env var. " .
                        "Set DB_TEST_DRIVER + DB_TEST_* vars to run against a real database."
                    );
                }
            }

            $app['config']->set('database.default', 'integration');
            $app['config']->set('database.connections.integration', [
                'driver'   => $driver,
                'host'     => env('DB_TEST_HOST', '127.0.0.1'),
                'port'     => (int) env('DB_TEST_PORT', $driver === 'pgsql' ? 5432 : 3306),
                'database' => env('DB_TEST_DATABASE'),
                'username' => env('DB_TEST_USERNAME'),
                'password' => env('DB_TEST_PASSWORD', ''),
                'charset'  => 'utf8mb4',
                'prefix'   => '',
            ]);
        }

        $app['config']->set('fuzzy-search', array_merge(
            require __DIR__ . '/../../config/fuzzy-search.php',
            ['use_native_functions' => false, 'legacy_dispatch' => false]
        ));
    }

    protected function setUpSchema(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('integration_users');
        $this->app['db']->connection()->getSchemaBuilder()->create('integration_users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });
    }

    protected function seedFixtures(): void
    {
        $this->app['db']->table('integration_users')->insert([
            ['name' => 'John Doe',     'email' => 'john@test.com',   'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Jon Snow',     'email' => 'jon@test.com',    'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Johnny Bravo', 'email' => 'johnny@test.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Jane Smith',   'email' => 'jane@test.com',   'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Alice Brown',  'email' => 'alice@test.com',  'created_at' => now(), 'updated_at' => now()],
            ['name' => 'cafe',         'email' => 'cafe@test.com',   'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Steven Jobs',  'email' => 'steven@test.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('integration_users');
        parent::tearDown();
    }
}
