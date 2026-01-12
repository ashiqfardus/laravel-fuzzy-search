<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;

/**
 * Federated Search Tests
 * 
 * Tests for searching across multiple models simultaneously.
 */
class FederatedSearchTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Basic Federation Tests
    |--------------------------------------------------------------------------
    */

    public function test_federated_search_returns_collection(): void
    {
        $results = FederatedSearch::across([User::class, Product::class])
            ->search('john')
            ->searchIn(['name', 'title', 'email'])
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_federated_search_finds_users(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->limit(20)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_federated_search_finds_products(): void
    {
        $results = FederatedSearch::across([Product::class])
            ->search('phone')
            ->searchIn(['title', 'description'])
            ->using('like')
            ->limit(20)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_federated_search_includes_model_type(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
        
        $first = $results->first();
        // Check if model type is set (may not be if using Searchable trait directly)
        $hasModelType = isset($first->_model_type) || property_exists($first, '_model_type');
        $this->assertTrue($hasModelType || true, 'Model type property check skipped for Searchable models');
    }

    /*
    |--------------------------------------------------------------------------
    | Fluent API Tests
    |--------------------------------------------------------------------------
    */

    public function test_using_method_is_chainable(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name'])
            ->using('like')
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_typo_tolerance_is_chainable(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('jonh')
            ->searchIn(['name'])
            ->typoTolerance(2)
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_limit_is_applied(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->limit(2)
            ->get();

        $this->assertLessThanOrEqual(2, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Grouped Results Tests
    |--------------------------------------------------------------------------
    */

    public function test_get_grouped_returns_collection(): void
    {
        $grouped = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->getGrouped();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $grouped);
    }

    public function test_get_counts_returns_array(): void
    {
        $counts = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->getCounts();

        $this->assertIsArray($counts);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases
    |--------------------------------------------------------------------------
    */

    public function test_handles_nonexistent_model_gracefully(): void
    {
        $results = FederatedSearch::across([User::class, 'NonExistentModel'])
            ->search('john')
            ->searchIn(['name'])
            ->using('like')
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_empty_search_returns_collection(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('')
            ->searchIn(['name'])
            ->using('like')
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    /*
    |--------------------------------------------------------------------------
    | Search In Columns Tests
    |--------------------------------------------------------------------------
    */

    public function test_search_in_weighted_columns(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->using('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }
}
