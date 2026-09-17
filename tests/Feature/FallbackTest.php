<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

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
    | Fallback actually runs (it used to be stored and never read)
    |--------------------------------------------------------------------------
    */

    public function test_fallback_algorithm_runs_when_the_primary_returns_nothing(): void
    {
        // Plain LIKE cannot match the transposed 'jonh'; the fuzzy driver's transposition pattern can.
        $this->assertCount(0, User::search('jonh')->using('simple')->get());

        $results = User::search('jonh')->using('simple')->fallback('fuzzy')->get();

        $this->assertTrue($results->contains('name', 'John Doe'));
    }

    public function test_fallbacks_are_tried_in_order_until_one_matches(): void
    {
        Event::fake([FuzzySearchExecuted::class]);

        $results = User::search('jonh')
            ->using('simple')
            ->fallback('like')   // still no match
            ->fallback('fuzzy')  // matches
            ->get();

        $this->assertTrue($results->contains('name', 'John Doe'));

        Event::assertDispatchedTimes(FuzzySearchExecuted::class, 3);
        $algorithms = [];
        Event::assertDispatched(FuzzySearchExecuted::class, function (FuzzySearchExecuted $e) use (&$algorithms) {
            $algorithms[] = $e->algorithm;
            return true;
        });
        $this->assertSame(['simple', 'like', 'fuzzy'], $algorithms);
    }

    public function test_fallback_is_not_used_when_the_primary_has_results(): void
    {
        Event::fake([FuzzySearchExecuted::class]);

        $results = User::search('john')->using('simple')->fallback('fuzzy')->get();

        $this->assertTrue($results->contains('name', 'John Doe'));
        Event::assertDispatchedTimes(FuzzySearchExecuted::class, 1);
        Event::assertDispatched(FuzzySearchExecuted::class, fn (FuzzySearchExecuted $e) => $e->algorithm === 'simple');
    }

    public function test_fallback_keeps_constraints_applied_before_the_search(): void
    {
        $builder = new SearchBuilder(User::where('email', 'john@example.com'), app(FuzzySearch::class));

        $results = $builder->search('jonh')->searchIn(['name'])->using('simple')->fallback('fuzzy')->get();

        // '%john%' also matches Johnny Bravo, but the where() must survive the retry.
        $this->assertCount(1, $results);
        $this->assertSame('John Doe', $results->first()->name);
    }

    public function test_fallback_keeps_filters(): void
    {
        $results = User::search('jonh')
            ->using('simple')
            ->fallback('fuzzy')
            ->filter('email', 'johnny@example.com')
            ->get();

        $this->assertCount(1, $results);
        $this->assertSame('Johnny Bravo', $results->first()->name);
    }

    public function test_bm25_path_falls_back_to_a_like_algorithm_when_the_index_has_no_match(): void
    {
        $manager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
        $manager->indexModel(User::where('name', 'John Doe')->first());

        // Since Phase 3 the index is typo-tolerant by default and would find John itself;
        // this test pins the fallback mechanics, so expansion is off.
        $this->assertCount(0, User::search('jonh')->useInvertedIndex()->typoTolerance(0)->get());

        $results = User::search('jonh')->useInvertedIndex()->fallback('fuzzy')->get();

        $this->assertTrue($results->contains('name', 'John Doe'));
    }

    public function test_fallback_applies_to_paginate(): void
    {
        $page = User::search('jonh')->using('simple')->fallback('fuzzy')->paginate(10);

        $this->assertGreaterThan(0, $page->total());
        $this->assertTrue(collect($page->items())->contains('name', 'John Doe'));
    }

    public function test_fallback_applies_to_count(): void
    {
        $this->assertSame(0, User::search('jonh')->using('simple')->count());
        $this->assertGreaterThan(0, User::search('jonh')->using('simple')->fallback('fuzzy')->count());
    }

    public function test_fallback_algorithms_are_part_of_the_cache_key(): void
    {
        $builder = User::search('jonh')->using('simple')->fallback('fuzzy')->cache(5);
        $plain   = User::search('jonh')->using('simple')->cache(5);

        $this->assertNotSame(
            (new \ReflectionMethod($plain, 'generateCacheKey'))->invoke($plain),
            (new \ReflectionMethod($builder, 'generateCacheKey'))->invoke($builder)
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
