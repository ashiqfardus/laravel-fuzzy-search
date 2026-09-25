<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearchServiceProvider;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\ConfiguresDatabaseConnection;

abstract class TestCase extends BaseTestCase
{
    use ConfiguresDatabaseConnection;

    protected function getPackageProviders($app): array
    {
        return [
            FuzzySearchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // sqlite :memory: by default; DB_TEST_DRIVER=mysql|mariadb|pgsql|sqlsrv switches
        // the whole suite to a real server (see tests/Concerns/ConfiguresDatabaseConnection).
        $this->configureTestDatabaseConnection($app);

        // The suite runs on the shipped config/fuzzy-search.php, loaded whole. A hand-kept
        // copy drifted from it (default_algorithm, levenshtein.max_distance, indexing.async,
        // unicode.accent_insensitive) and hid bugs that show only at the shipped values.
        // A test that needs another value sets it locally, with a comment saying why.
        // Tests\Unit\ShippedConfigTest fails on any change here it does not list.
        $app['config']->set('fuzzy-search', require __DIR__ . '/../config/fuzzy-search.php');

        // indexing.async ships true and needs no override: on the sync connection the job a save
        // dispatches runs before save() returns. Testbench defaults to sync on Laravel 12 and 13;
        // this holds it on every version (Laravel 11+ itself defaults to the database queue).
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        // A crashed run on a real database can leave the index tables behind.
        $this->dropLeftoverFuzzyIndexTables();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function setUpDatabase(): void
    {
        // Idempotent on real databases where a previous run may have aborted mid-test.
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('products');
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('users');

        // Create test table
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });

        // Create products table for additional testing
        $this->app['db']->connection()->getSchemaBuilder()->create('products', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
        });

        // Seed users test data
        $this->app['db']->table('users')->insert([
            ['name' => 'John Doe', 'email' => 'john@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Jane Doe', 'email' => 'jane@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Jon Snow', 'email' => 'jon@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Johnny Bravo', 'email' => 'johnny@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Alice Smith', 'email' => 'alice@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bob Johnson', 'email' => 'bob@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Charlie Brown', 'email' => 'charlie@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed products test data
        $this->app['db']->table('products')->insert([
            ['title' => 'iPhone 15 Pro', 'description' => 'Latest Apple smartphone', 'price' => 999.99, 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'Samsung Galaxy S24', 'description' => 'Android flagship phone', 'price' => 899.99, 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'MacBook Pro', 'description' => 'Professional laptop', 'price' => 1999.99, 'created_at' => now(), 'updated_at' => now()],
            ['title' => 'iPad Air', 'description' => 'Tablet computer', 'price' => 599.99, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('products');
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists('users');

        // Reset the observer's schema column cache so static state does not
        // leak between test cases that use different in-memory databases.
        \Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver::resetColumnCache();
        \Ashiqfardus\LaravelFuzzySearch\FederatedSearch::resetColumnCache();
        \Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::resetPipelineCache();
        \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::reset();

        parent::tearDown();
    }
}
