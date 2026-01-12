<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Illuminate\Support\Facades\DB;

// Import shared models
require_once __DIR__ . '/TestModels.php';

/**
 * Eloquent Model Tests with Fuzzy Trait
 */
class EloquentTraitTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Basic Trait Tests
    |--------------------------------------------------------------------------
    */

    public function test_fuzzy_scope_on_model(): void
    {
        $results = User::fuzzy('john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_scope_with_custom_columns(): void
    {
        $results = User::fuzzy('john', ['name'])->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_with_algorithm(): void
    {
        $results = User::fuzzyWith('like', 'john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_levenshtein_scope(): void
    {
        $results = User::fuzzyLevenshtein('jon', null, 2)->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_soundex_scope(): void
    {
        $results = User::fuzzySoundex('john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_similar_scope(): void
    {
        $results = User::fuzzySimilar('john', null, 50)->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Model Configuration Tests
    |--------------------------------------------------------------------------
    */

    public function test_get_fuzzy_searchable_columns(): void
    {
        $user = new User();
        $columns = $user->getFuzzySearchableColumns();

        $this->assertEquals(['name', 'email'], $columns);
    }

    public function test_get_fuzzy_algorithm(): void
    {
        $user = new User();
        $algorithm = $user->getFuzzyAlgorithm();

        $this->assertEquals('levenshtein', $algorithm);
    }

    public function test_get_fuzzy_options(): void
    {
        $user = new User();
        $options = $user->getFuzzyOptions();

        $this->assertEquals(['max_distance' => 3], $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Eloquent Builder Macro Tests
    |--------------------------------------------------------------------------
    */

    public function test_where_fuzzy_on_eloquent(): void
    {
        $results = User::whereFuzzy('name', 'john', 'like')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_or_where_fuzzy_on_eloquent(): void
    {
        $results = User::whereFuzzy('name', 'john', 'like')
            ->orWhereFuzzy('name', 'alice', 'like')
            ->get();

        $this->assertGreaterThanOrEqual(2, $results->count());
    }

    public function test_where_fuzzy_multiple_on_eloquent(): void
    {
        $results = User::whereFuzzyMultiple(['name', 'email'], 'john', 'like')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_search_on_eloquent(): void
    {
        $results = User::fuzzySearch('name', 'john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_search_multiple_columns_on_eloquent(): void
    {
        $results = User::fuzzySearch(['name', 'email'], 'john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Collection Filtering Tests
    |--------------------------------------------------------------------------
    */

    public function test_filter_fuzzy_on_collection(): void
    {
        $users = User::all();
        // "John Doe" vs "John" = 4 distance (space + D + o + e)
        // Use a higher max distance or search for "John Doe"
        $filtered = User::filterFuzzy($users, 'name', 'John Doe', 3);

        $this->assertGreaterThan(0, $filtered->count());
    }

    public function test_sort_by_fuzzy_on_collection(): void
    {
        $users = User::all();
        $sorted = User::sortByFuzzy($users, 'name', 'John');

        $this->assertGreaterThan(0, $sorted->count());

        // First result should be closest match
        $firstName = $sorted->first()->name;
        $this->assertStringContainsString('John', $firstName);
    }

    /*
    |--------------------------------------------------------------------------
    | Chaining Tests
    |--------------------------------------------------------------------------
    */

    public function test_fuzzy_with_other_eloquent_methods(): void
    {
        $results = User::fuzzy('john')
            ->select('name', 'email')
            ->orderBy('name')
            ->limit(5)
            ->get();

        $this->assertLessThanOrEqual(5, $results->count());
    }

    public function test_fuzzy_with_where_clause(): void
    {
        $results = User::fuzzy('john')
            ->where('email', 'like', '%example.com')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Product Model Tests
    |--------------------------------------------------------------------------
    */

    public function test_product_fuzzy_search(): void
    {
        $results = Product::fuzzy('phone')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_product_fuzzy_in_description(): void
    {
        $results = Product::fuzzy('laptop')->get();

        $this->assertGreaterThan(0, $results->count());
    }
}

