<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Performance;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * Performance / Benchmark Tests
 * 
 * Tests for search performance, memory usage, and scalability.
 * These tests verify that the package performs within acceptable bounds.
 */
class PerformanceTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Response Time Tests
    |--------------------------------------------------------------------------
    */

    public function test_simple_search_completes_in_reasonable_time(): void
    {
        $start = microtime(true);
        
        User::search('john')->get();
        
        $duration = (microtime(true) - $start) * 1000; // ms
        
        // Should complete in under 500ms even with slow systems
        $this->assertLessThan(500, $duration, "Search took {$duration}ms");
    }

    public function test_levenshtein_search_completes_in_reasonable_time(): void
    {
        $start = microtime(true);
        
        User::search('john')
            ->using('levenshtein')
            ->typoTolerance(2)
            ->get();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertLessThan(500, $duration, "Levenshtein search took {$duration}ms");
    }

    public function test_soundex_search_completes_in_reasonable_time(): void
    {
        $start = microtime(true);
        
        User::search('john')
            ->using('soundex')
            ->get();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertLessThan(500, $duration, "Soundex search took {$duration}ms");
    }

    public function test_multi_column_search_completes_in_reasonable_time(): void
    {
        $start = microtime(true);
        
        User::search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->get();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertLessThan(500, $duration, "Multi-column search took {$duration}ms");
    }

    /*
    |--------------------------------------------------------------------------
    | Memory Usage Tests
    |--------------------------------------------------------------------------
    */

    public function test_search_memory_usage_is_reasonable(): void
    {
        $memoryBefore = memory_get_usage(true);
        
        User::search('john')->get();
        
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB
        
        // Should use less than 10MB for simple search
        $this->assertLessThan(10, $memoryUsed, "Search used {$memoryUsed}MB");
    }

    public function test_pagination_limits_memory_usage(): void
    {
        $memoryBefore = memory_get_usage(true);
        
        User::search('john')->paginate(10);
        
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = ($memoryAfter - $memoryBefore) / 1024 / 1024; // MB
        
        // Paginated search should use even less memory
        $this->assertLessThan(5, $memoryUsed, "Paginated search used {$memoryUsed}MB");
    }

    /*
    |--------------------------------------------------------------------------
    | Concurrent Query Tests
    |--------------------------------------------------------------------------
    */

    public function test_multiple_searches_complete_successfully(): void
    {
        $results = [];
        
        // Run multiple searches
        for ($i = 0; $i < 5; $i++) {
            $results[] = User::search('john')->get();
        }
        
        // All should return results
        foreach ($results as $result) {
            $this->assertGreaterThan(0, $result->count());
        }
    }

    public function test_different_algorithms_can_run_sequentially(): void
    {
        $algorithms = ['like', 'levenshtein', 'soundex', 'similar_text'];
        $results = [];
        
        foreach ($algorithms as $algorithm) {
            $results[$algorithm] = User::search('john')
                ->using($algorithm)
                ->get();
        }
        
        // All algorithms should return results
        foreach ($results as $algorithm => $result) {
            $this->assertGreaterThan(0, $result->count(), 
                "Algorithm {$algorithm} returned no results");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Pattern Generation Tests
    |--------------------------------------------------------------------------
    */

    public function test_max_patterns_limit_prevents_query_explosion(): void
    {
        $start = microtime(true);
        
        // Search with a long term that could generate many patterns
        User::search('abcdefghijklmnop')
            ->maxPatterns(20)
            ->using('levenshtein')
            ->get();
        
        $duration = (microtime(true) - $start) * 1000;
        
        // Should still complete quickly due to pattern limit
        $this->assertLessThan(1000, $duration, 
            "Pattern-limited search took {$duration}ms");
    }

    /*
    |--------------------------------------------------------------------------
    | Large Dataset Simulation Tests
    |--------------------------------------------------------------------------
    */

    public function test_search_with_more_records(): void
    {
        // Add more test records
        $records = [];
        for ($i = 0; $i < 100; $i++) {
            $records[] = [
                'name' => "User {$i} Test",
                'email' => "user{$i}@example.com",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('users')->insert($records);

        $start = microtime(true);
        
        $results = User::search('user')
            ->limit(20)
            ->get();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertLessThan(500, $duration, 
            "Search with 100+ records took {$duration}ms");
        $this->assertLessThanOrEqual(20, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Tokenization Performance Tests
    |--------------------------------------------------------------------------
    */

    public function test_tokenized_search_performance(): void
    {
        $start = microtime(true);
        
        User::search('john doe smith')
            ->tokenize()
            ->matchAny()
            ->get();
        
        $duration = (microtime(true) - $start) * 1000;
        
        $this->assertLessThan(500, $duration, 
            "Tokenized search took {$duration}ms");
    }

    /*
    |--------------------------------------------------------------------------
    | Benchmark Comparison Tests
    |--------------------------------------------------------------------------
    */

    public function test_like_is_fastest_algorithm(): void
    {
        $searchTerm = 'john';
        $iterations = 10;
        
        $times = [];
        
        foreach (['like', 'levenshtein', 'soundex'] as $algorithm) {
            $start = microtime(true);
            
            for ($i = 0; $i < $iterations; $i++) {
                User::search($searchTerm)->using($algorithm)->get();
            }
            
            $times[$algorithm] = (microtime(true) - $start) * 1000 / $iterations;
        }
        
        // LIKE should generally be faster than other algorithms
        $this->assertLessThanOrEqual(
            $times['levenshtein'] * 2, 
            $times['like'],
            "LIKE was unexpectedly slow compared to Levenshtein"
        );
    }
}
