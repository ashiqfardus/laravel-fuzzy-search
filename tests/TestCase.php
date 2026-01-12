<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearchServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            FuzzySearchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Fuzzy search config
        $app['config']->set('fuzzy-search', [
            'default_algorithm' => 'levenshtein',
            'levenshtein' => [
                'max_distance' => 3,
                'cost_insert' => 1,
                'cost_replace' => 1,
                'cost_delete' => 1,
            ],
            'similar_text' => [
                'min_percentage' => 70,
            ],
            'like' => [
                'case_insensitive' => true,
            ],
            'use_native_functions' => false,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function setUpDatabase(): void
    {
        // Create test table
        $this->app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
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

        parent::tearDown();
    }
}

