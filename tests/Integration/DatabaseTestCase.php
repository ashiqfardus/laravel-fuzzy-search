<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearchServiceProvider;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\ConfiguresDatabaseConnection;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\ReportsSourceDeprecations;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base for driver-level integration tests that use the published config file verbatim
 * and a dedicated `integration_users` fixture table.
 *
 * Database selection is shared with the main TestCase via ConfiguresDatabaseConnection:
 * DB_TEST_DRIVER + DB_TEST_* env vars pick the server; missing vars skip the test.
 */
abstract class DatabaseTestCase extends BaseTestCase
{
    use ConfiguresDatabaseConnection, ReportsSourceDeprecations;

    protected function getPackageProviders($app): array
    {
        return [FuzzySearchServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchema();
        $this->seedFixtures();

        // Last, above Laravel's error handler: a src/ deprecation fails the run (failOnDeprecation).
        $this->reportSourceDeprecations();
    }

    protected function defineEnvironment($app): void
    {
        $this->configureTestDatabaseConnection($app);

        // The shipped config, loaded whole, as in TestCase. Tests\Integration\ShippedConfigTest
        // fails on any override here.
        $app['config']->set('fuzzy-search', require __DIR__ . '/../../config/fuzzy-search.php');
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
