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

        // Fuzzy search config — mirrors config/fuzzy-search.php defaults so tests exercise
        // the published values rather than silently falling back to config() null returns.
        $app['config']->set('fuzzy-search', [
            'default_algorithm'  => 'levenshtein',
            'allow_empty_search' => false,
            'min_search_length'  => 2, // matches config/fuzzy-search.php default
            'max_candidates'     => 1000,
            'levenshtein' => [
                'max_distance' => 3,
                'cost_insert'  => 1,
                'cost_replace' => 1,
                'cost_delete'  => 1,
            ],
            'similar_text' => [
                'min_percentage' => 70,
            ],
            'like' => [
                'case_insensitive' => true,
            ],
            'use_native_functions' => false,
            'indexing' => [
                'enabled'            => false,
                'async'              => false,
                'queue'              => 'default',
                'chunk_size'         => 500,
                'tokenizer'          => \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer::class,
                'stemmer'            => \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer::class,
                'max_tokens_per_doc' => 5000,
                'job'                => ['tries' => 3, 'backoff' => [10, 60, 300], 'timeout' => 120],
            ],
            'bm25' => [
                'k1'                    => 1.5,
                'b'                     => 0.75,
                'max_postings_per_term' => 50000,
                'candidate_chunk'       => 200,
                'fuzzy'                 => ['candidate_pool' => 500, 'max_expansions' => 5, 'damping' => true],
                'prefix'                => ['max_expansions' => 10],
            ],
            'query' => [
                'max_depth'  => 16,
                'max_tokens' => 32,
            ],
            'in_memory' => [
                'max_items'      => 10000,
                'min_similarity' => 60,
            ],
            'scoring' => ['exact_match' => 100, 'prefix_match' => 80, 'contains' => 60, 'fuzzy_match' => 50],
            'performance' => ['max_patterns' => 100],
            'highlighting' => ['enabled' => false, 'tag_open' => '<em>', 'tag_close' => '</em>'],
            'unicode' => ['normalize' => false, 'accent_insensitive' => false],
            'typo_tolerance' => ['enabled' => true, 'max_distance' => 2, 'min_word_length' => 4],
            'locale' => 'en',
            'stop_words' => [
                'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'it'],
                'de' => ['der', 'die', 'das', 'und', 'oder', 'aber', 'in', 'auf', 'an', 'zu', 'für', 'von'],
                'fr' => ['le', 'la', 'les', 'un', 'une', 'des', 'et', 'ou', 'mais', 'dans', 'sur', 'à'],
                'es' => ['el', 'la', 'los', 'las', 'un', 'una', 'y', 'o', 'pero', 'en', 'sobre', 'a'],
            ],
            'analytics' => [
                'enabled'        => false,
                'queue'          => null,
                'sample_rate'    => 1.0,
                'retention_days' => 30,
                'hash_terms'     => false,
                'table'          => 'fuzzy_search_logs',
            ],
        ]);
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

        parent::tearDown();
    }
}
