<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * Fallback Algorithm Feature Tests
 * 
 * Tests for the fallback() method in SearchBuilder to ensure
 * fallback algorithms are properly configured and can be chained.
 */
class FallbackTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Basic Fallback Tests
    |--------------------------------------------------------------------------
    */

    public function test_fallback_method_is_chainable(): void
    {
        $results = User::search('john')
            ->using('fuzzy')
            ->fallback('levenshtein')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_multiple_fallbacks_can_be_chained(): void
    {
        $results = User::search('john')
            ->using('trigram')
            ->fallback('fuzzy')
            ->fallback('levenshtein')
            ->fallback('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Algorithm Transition Tests
    |--------------------------------------------------------------------------
    */

    public function test_fallback_to_like_for_simple_search(): void
    {
        $results = User::search('john')
            ->using('levenshtein')
            ->fallback('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
        $this->assertTrue($results->contains('name', 'John Doe'));
    }

    public function test_fallback_to_soundex_for_phonetic_matching(): void
    {
        $results = User::search('jon')  // Similar to John
            ->using('trigram')
            ->fallback('soundex')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Fallback with Other Options Tests
    |--------------------------------------------------------------------------
    */

    public function test_fallback_with_typo_tolerance(): void
    {
        $results = User::search('jhn')
            ->using('levenshtein')
            ->typoTolerance(2)
            ->fallback('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fallback_with_tokenization(): void
    {
        $results = User::search('john doe')
            ->using('fuzzy')
            ->tokenize()
            ->matchAll()
            ->fallback('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fallback_with_field_weighting(): void
    {
        $results = User::search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->using('trigram')
            ->fallback('fuzzy')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fallback_preserves_pagination(): void
    {
        $results = User::search('john')
            ->using('fuzzy')
            ->fallback('like')
            ->paginate(10);

        $this->assertInstanceOf(
            \Illuminate\Contracts\Pagination\LengthAwarePaginator::class,
            $results
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Query Builder Fallback Tests
    |--------------------------------------------------------------------------
    */

    public function test_fallback_works_with_query_builder(): void
    {
        $results = DB::table('users')
            ->whereFuzzy('name', 'john', 'levenshtein')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }
}
