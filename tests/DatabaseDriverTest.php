<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Illuminate\Support\Facades\DB;

/**
 * Database Driver Specific Tests
 *
 * These tests verify SQL generation is correct for each database driver.
 * Note: Actual execution requires the respective database to be available.
 */
class DatabaseDriverTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Query SQL Generation Tests
    |--------------------------------------------------------------------------
    | These tests verify that the correct SQL is generated for each driver
    |--------------------------------------------------------------------------
    */

    public function test_mysql_like_query_generation(): void
    {
        // Simulate MySQL driver behavior
        $query = DB::table('users')
            ->whereFuzzy('name', 'john', 'like');

        $sql = $query->toSql();

        // Should contain LIKE clause
        $this->assertStringContainsString('like', strtolower($sql));
    }

    public function test_levenshtein_pattern_generation(): void
    {
        $query = DB::table('users')
            ->whereFuzzy('name', 'john', 'levenshtein', ['max_distance' => 2]);

        $sql = $query->toSql();

        // Should generate LIKE patterns for fuzzy matching
        $this->assertStringContainsString('like', strtolower($sql));
    }

    public function test_soundex_fallback_for_sqlite(): void
    {
        // SQLite doesn't have native SOUNDEX, should fall back to LIKE patterns
        $query = DB::table('users')
            ->whereFuzzy('name', 'john', 'soundex');

        $sql = $query->toSql();

        // Should use LIKE patterns as fallback
        $this->assertStringContainsString('like', strtolower($sql));
    }

    public function test_multiple_columns_generates_or_conditions(): void
    {
        $query = DB::table('users')
            ->whereFuzzyMultiple(['name', 'email'], 'john', 'like');

        $sql = $query->toSql();

        // Should contain OR for multiple columns
        $this->assertStringContainsString('or', strtolower($sql));
    }

    public function test_order_by_fuzzy_generates_valid_sql(): void
    {
        $query = DB::table('users')
            ->orderByFuzzy('name', 'john');

        $sql = $query->toSql();

        // Should contain ORDER BY
        $this->assertStringContainsString('order by', strtolower($sql));
    }

    /*
    |--------------------------------------------------------------------------
    | Binding Tests
    |--------------------------------------------------------------------------
    */

    public function test_query_bindings_are_set(): void
    {
        $query = DB::table('users')
            ->whereFuzzy('name', 'john', 'like');

        $bindings = $query->getBindings();

        // Should have bindings for the search value
        $this->assertNotEmpty($bindings);
    }

    public function test_multiple_bindings_for_complex_query(): void
    {
        $query = DB::table('users')
            ->whereFuzzy('name', 'john', 'like')
            ->orWhereFuzzy('email', 'jane', 'like');

        $bindings = $query->getBindings();

        // Should have multiple bindings
        $this->assertGreaterThan(1, count($bindings));
    }

    /*
    |--------------------------------------------------------------------------
    | SQL Injection Prevention Tests
    |--------------------------------------------------------------------------
    */

    public function test_sql_injection_prevention(): void
    {
        $maliciousInput = "'; DROP TABLE users; --";

        // This should not throw an exception and should safely escape the input
        $results = DB::table('users')
            ->whereFuzzy('name', $maliciousInput, 'like')
            ->get();

        // Table should still exist
        $count = DB::table('users')->count();
        $this->assertGreaterThan(0, $count);
    }

    public function test_special_sql_characters_are_escaped(): void
    {
        $specialChars = "O'Brien";

        $results = DB::table('users')
            ->whereFuzzy('name', $specialChars, 'like')
            ->get();

        // Should execute without error
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }
}

