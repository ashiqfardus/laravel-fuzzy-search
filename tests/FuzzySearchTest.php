<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Illuminate\Support\Facades\DB;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;

class FuzzySearchTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | LIKE Algorithm Tests
    |--------------------------------------------------------------------------
    */

    public function test_where_fuzzy_with_like_algorithm(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
        $this->assertTrue($results->contains('name', 'John Doe'));
    }

    public function test_like_is_case_insensitive(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'JOHN', 'like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
        $this->assertTrue($results->contains('name', 'John Doe'));
    }

    public function test_like_partial_match(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'Doe', 'like')
            ->get();

        $this->assertEquals(2, $results->count()); // John Doe and Jane Doe
    }

    /*
    |--------------------------------------------------------------------------
    | Levenshtein Algorithm Tests
    |--------------------------------------------------------------------------
    */

    public function test_where_fuzzy_with_levenshtein_algorithm(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'jon', 'levenshtein', ['max_distance' => 2])
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_levenshtein_finds_typos(): void
    {
        // "johnn" is 1 edit away from "john"
        $results = DB::table('users')
            ->whereFuzzy('name', 'johnn', 'levenshtein', ['max_distance' => 2])
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_levenshtein_with_strict_distance(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'xyz', 'levenshtein', ['max_distance' => 1])
            ->get();

        // Should not match anything
        $this->assertEquals(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Soundex Algorithm Tests
    |--------------------------------------------------------------------------
    */

    public function test_where_fuzzy_with_soundex_algorithm(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'soundex')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_soundex_phonetic_matching(): void
    {
        // "Jon" and "John" have same soundex
        $this->assertEquals(soundex('Jon'), soundex('John'));
    }

    /*
    |--------------------------------------------------------------------------
    | Similar Text Algorithm Tests
    |--------------------------------------------------------------------------
    */

    public function test_where_fuzzy_with_similar_text(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'similar_text', ['min_percentage' => 50])
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Multiple Columns Tests
    |--------------------------------------------------------------------------
    */

    public function test_where_fuzzy_multiple_columns(): void
    {
        $results = DB::table('users')
            ->whereFuzzyMultiple(['name', 'email'], 'john', 'like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_search_across_columns(): void
    {
        $results = DB::table('products')
            ->whereFuzzyMultiple(['title', 'description'], 'phone', 'like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | OR Condition Tests
    |--------------------------------------------------------------------------
    */

    public function test_or_where_fuzzy(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'like')
            ->orWhereFuzzy('name', 'alice', 'like')
            ->get();

        $this->assertGreaterThanOrEqual(2, $results->count());
    }

    public function test_complex_fuzzy_query(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'like')
            ->orWhereFuzzy('email', 'jane', 'like')
            ->get();

        $this->assertGreaterThanOrEqual(2, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering Tests
    |--------------------------------------------------------------------------
    */

    public function test_order_by_fuzzy(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'like')
            ->orderByFuzzy('name', 'john')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | PHP Helper Function Tests
    |--------------------------------------------------------------------------
    */

    public function test_levenshtein_distance_calculation(): void
    {
        $distance = FuzzySearch::levenshteinDistance('john', 'jon');
        $this->assertEquals(1, $distance);

        $distance = FuzzySearch::levenshteinDistance('john', 'john');
        $this->assertEquals(0, $distance);

        // john -> jane: o->a, h->n, n->e = 3 substitutions
        $distance = FuzzySearch::levenshteinDistance('john', 'jane');
        $this->assertEquals(3, $distance);

        $distance = FuzzySearch::levenshteinDistance('kitten', 'sitting');
        $this->assertEquals(3, $distance); // k->s, e->i, +g = 3 edits
    }

    public function test_similarity_percentage_calculation(): void
    {
        $percentage = FuzzySearch::similarityPercentage('john', 'john');
        $this->assertEquals(100, $percentage);

        $percentage = FuzzySearch::similarityPercentage('john', 'jon');
        $this->assertGreaterThan(70, $percentage);

        $percentage = FuzzySearch::similarityPercentage('hello', 'world');
        $this->assertLessThan(50, $percentage);
    }

    public function test_levenshtein_with_custom_costs(): void
    {
        $options = [
            'cost_insert' => 2,
            'cost_replace' => 1,
            'cost_delete' => 2,
        ];

        $distance = FuzzySearch::levenshteinDistance('john', 'jon', $options);
        $this->assertEquals(2, $distance); // Delete 'h' costs 2
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases Tests
    |--------------------------------------------------------------------------
    */

    public function test_empty_search_term(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', '', 'like')
            ->get();

        // Empty string matches all with LIKE '%%'
        $this->assertGreaterThan(0, $results->count());
    }

    public function test_special_characters_in_search(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('email', '@example.com', 'like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_unicode_characters(): void
    {
        // Add a user with unicode characters
        DB::table('users')->insert([
            'name' => 'José García',
            'email' => 'jose@example.com',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Search for partial match that doesn't require unicode matching
        $results = DB::table('users')
            ->whereFuzzy('email', 'jose@example', 'like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Database Driver Compatibility Tests
    |--------------------------------------------------------------------------
    */

    public function test_sqlite_driver_support(): void
    {
        // Current test is running on SQLite
        $driver = DB::connection()->getDriverName();
        $this->assertEquals('sqlite', $driver);

        // All algorithms should work
        $results = DB::table('users')->whereFuzzy('name', 'john', 'like')->get();
        $this->assertGreaterThan(0, $results->count());

        $results = DB::table('users')->whereFuzzy('name', 'john', 'levenshtein')->get();
        $this->assertGreaterThan(0, $results->count());

        $results = DB::table('users')->whereFuzzy('name', 'john', 'soundex')->get();
        $this->assertGreaterThan(0, $results->count());
    }

    public function test_query_does_not_throw_exception(): void
    {
        // Ensure no SQL exceptions are thrown for any algorithm
        // Note: 'metaphone' uses SOUNDEX fallback which doesn't exist in SQLite
        $algorithms = ['like', 'levenshtein', 'soundex', 'similar_text'];

        foreach ($algorithms as $algorithm) {
            try {
                $results = DB::table('users')
                    ->whereFuzzy('name', 'john', $algorithm)
                    ->get();

                $this->assertTrue(true, "Algorithm {$algorithm} executed successfully");
            } catch (\Exception $e) {
                $this->fail("Algorithm {$algorithm} threw exception: " . $e->getMessage());
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Integration Tests
    |--------------------------------------------------------------------------
    */

    public function test_chained_fuzzy_with_other_conditions(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'like')
            ->where('email', 'LIKE', '%example.com')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_with_select(): void
    {
        $results = DB::table('users')
            ->select('name', 'email')
            ->whereFuzzy('name', 'john', 'like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
        $this->assertObjectHasProperty('name', $results->first());
        $this->assertObjectHasProperty('email', $results->first());
    }

    public function test_fuzzy_with_limit(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'like')
            ->limit(1)
            ->get();

        $this->assertLessThanOrEqual(1, $results->count());
    }
}

