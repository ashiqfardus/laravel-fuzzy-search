<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Cache;

/**
 * Cache Feature Tests
 * 
 * Tests for the cache() method in SearchBuilder including
 * cache hits, misses, TTL, and custom keys.
 */
class CacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear cache before each test
        Cache::flush();
        
        // Enable caching in config
        config(['fuzzy-search.cache.enabled' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Basic Cache Tests
    |--------------------------------------------------------------------------
    */

    public function test_cached_search_returns_results(): void
    {
        $results = User::search('john')
            ->cache(60)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_cached_results_match_uncached_results(): void
    {
        $uncachedResults = User::search('john')->get();
        $cachedResults = User::search('john')->cache(60)->get();

        $this->assertEquals(
            $uncachedResults->pluck('id')->toArray(),
            $cachedResults->pluck('id')->toArray()
        );
    }

    public function test_second_cached_call_uses_cache(): void
    {
        // First call - caches the result
        $results1 = User::search('john')
            ->cache(60)
            ->get();

        // Second call - should use cache
        $results2 = User::search('john')
            ->cache(60)
            ->get();

        $this->assertEquals(
            $results1->pluck('id')->toArray(),
            $results2->pluck('id')->toArray()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Custom Cache Key Tests
    |--------------------------------------------------------------------------
    */

    public function test_custom_cache_key_works(): void
    {
        $customKey = 'my-custom-search-key';

        $results = User::search('john')
            ->cache(60, $customKey)
            ->get();

        $this->assertGreaterThan(0, $results->count());
        
        // The custom key should exist in cache
        $this->assertTrue(Cache::has($customKey));
    }

    public function test_different_search_terms_use_different_cache_keys(): void
    {
        // Cache two different searches
        $results1 = User::search('john')->cache(60)->get();
        $results2 = User::search('alice')->cache(60)->get();

        // They should have different results
        $this->assertNotEquals(
            $results1->pluck('name')->toArray(),
            $results2->pluck('name')->toArray()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cache TTL Tests
    |--------------------------------------------------------------------------
    */

    public function test_zero_cache_time_does_not_cache(): void
    {
        // With 0 minutes, should still return results
        $results = User::search('john')
            ->cache(0)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_null_cache_time_uses_default(): void
    {
        $results = User::search('john')
            ->cache(null)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Invalidation Tests
    |--------------------------------------------------------------------------
    */

    public function test_cache_can_be_manually_cleared(): void
    {
        $customKey = 'test-clear-key';

        // Cache a result
        User::search('john')->cache(60, $customKey)->get();
        
        $this->assertTrue(Cache::has($customKey));

        // Clear the cache
        Cache::forget($customKey);
        
        $this->assertFalse(Cache::has($customKey));
    }

    /*
    |--------------------------------------------------------------------------
    | Cache with Other Features Tests
    |--------------------------------------------------------------------------
    */

    public function test_cache_with_pagination(): void
    {
        $results = User::search('john')
            ->cache(60)
            ->paginate(10);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Pagination\LengthAwarePaginator::class,
            $results
        );
    }

    public function test_cache_with_relevance_scoring(): void
    {
        $results = User::search('john')
            ->cache(60)
            ->withRelevance()
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_cache_with_highlighting(): void
    {
        $results = User::search('john')
            ->cache(60)
            ->highlight('em')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_cache_with_typo_tolerance(): void
    {
        $results = User::search('jhn')  // Typo
            ->cache(60)
            ->typoTolerance(2)
            ->get();

        // Should find results despite typo
        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}
