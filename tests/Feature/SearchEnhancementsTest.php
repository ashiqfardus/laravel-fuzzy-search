<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Illuminate\Support\Facades\DB;

/**
 * Search Enhancements Tests
 *
 * Tests for search enhancement features: suggest (autocomplete),
 * didYouMean (spell correction), boostRecent (recency boost),
 * and getAnalytics (search analytics).
 */
class SearchEnhancementsTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Suggest / Autocomplete Tests
    |--------------------------------------------------------------------------
    */

    public function test_suggest_returns_array(): void
    {
        $suggestions = User::search('jo')
            ->searchIn(['name', 'email'])
            ->suggest(5);

        $this->assertIsArray($suggestions);
    }

    public function test_suggest_returns_matching_suggestions(): void
    {
        $suggestions = User::search('joh')
            ->searchIn(['name'])
            ->suggest(5);

        // Should find suggestions starting with 'joh'
        foreach ($suggestions as $suggestion) {
            $this->assertStringStartsWith('joh', strtolower($suggestion));
        }
    }

    public function test_suggest_respects_limit(): void
    {
        $suggestions = User::search('j')
            ->searchIn(['name'])
            ->suggest(2);

        $this->assertLessThanOrEqual(2, count($suggestions));
    }

    public function test_suggest_returns_empty_for_short_term(): void
    {
        $suggestions = User::search('j')  // Only 1 character
            ->searchIn(['name'])
            ->suggest(5);

        // Should return empty for term < 2 chars
        $this->assertEmpty($suggestions);
    }

    public function test_suggest_is_case_insensitive_on_every_driver(): void
    {
        $suggestions = User::search('joh')->searchIn(['name'])->suggest(5);

        $this->assertNotEmpty($suggestions);
        $this->assertContains('John', $suggestions);
    }

    /*
    |--------------------------------------------------------------------------
    | Did You Mean Tests
    |--------------------------------------------------------------------------
    */

    public function test_did_you_mean_returns_array(): void
    {
        $alternatives = User::search('jonh')  // Typo
            ->searchIn(['name'])
            ->didYouMean(3);

        $this->assertIsArray($alternatives);
    }

    public function test_did_you_mean_returns_alternatives_with_distance(): void
    {
        // Seed term dictionary so didYouMean has something to find — posted under User, since
        // didYouMean() only offers the searched model's terms
        foreach (['john' => 50, 'jones' => 20, 'jane' => 30] as $term => $docCount) {
            $termId = $this->app['db']->table('fuzzy_index_terms')->insertGetId(['term' => $term, 'doc_count' => $docCount, 'term_length' => mb_strlen($term)]);
            $this->app['db']->table('fuzzy_index_postings')->insert(['term_id' => $termId, 'model_type' => User::class, 'model_id' => '1', 'frequency' => 1, 'column_name' => 'name']);
        }

        // 'jonh' has Levenshtein distance 1 from 'john'
        $alternatives = User::search('jonh')->searchIn(['name'])->didYouMean(3);

        $this->assertNotEmpty($alternatives);
        $first = $alternatives[0];
        $this->assertArrayHasKey('term', $first);
        $this->assertArrayHasKey('distance', $first);
        $this->assertArrayHasKey('confidence', $first);
        $this->assertGreaterThan(0, $first['distance']);
        $this->assertContains('john', array_column($alternatives, 'term'));
    }

    public function test_did_you_mean_respects_limit(): void
    {
        $alternatives = User::search('jonh')
            ->searchIn(['name'])
            ->didYouMean(2);

        $this->assertLessThanOrEqual(2, count($alternatives));
    }

    public function test_did_you_mean_returns_empty_for_short_term(): void
    {
        $alternatives = User::search('j')
            ->searchIn(['name'])
            ->didYouMean(3);

        $this->assertEmpty($alternatives);
    }

    /*
    |--------------------------------------------------------------------------
    | Boost Recent Tests
    |--------------------------------------------------------------------------
    */

    public function test_boost_recent_is_chainable(): void
    {
        $results = User::search('john')
            ->boostRecent(1.5, 'created_at', 30)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_boost_recent_with_default_params(): void
    {
        $results = User::search('john')
            ->boostRecent()
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_boost_recent_decays_linearly_across_the_window(): void
    {
        // README "Recency Boost": 1 + (multiplier - 1) x (1 - age_in_days / days) — the full
        // multiplier at age 0, none at the end of the window. It is not a flat multiplier for
        // everything inside the window.
        $builder = User::search('john')->boostRecent(1.5, 'created_at', 30);
        $boost   = new \ReflectionMethod($builder, 'calculateRecencyBoost');
        $boost->setAccessible(true);

        $aged = fn (int $days) => (object) ['created_at' => now()->subDays($days)->toDateTimeString()];

        $this->assertSame(1.5, round($boost->invoke($builder, $aged(0)), 4));
        $this->assertSame(1.25, round($boost->invoke($builder, $aged(15)), 4));
        $this->assertSame(1.0167, round($boost->invoke($builder, $aged(29)), 4));
        $this->assertSame(1.0, round($boost->invoke($builder, $aged(60)), 4));
    }

    public function test_boost_recent_affects_relevance_scores(): void
    {
        // Add a very recent user
        DB::table('users')->insert([
            'name' => 'John Recent',
            'email' => 'recent@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resultsWithBoost = User::search('john')
            ->boostRecent(2.0, 'created_at', 7)
            ->withRelevance()
            ->get();

        $this->assertGreaterThan(0, $resultsWithBoost->count());
        
        // The recent user should have a score
        $recentUser = $resultsWithBoost->firstWhere('name', 'John Recent');
        if ($recentUser) {
            $this->assertNotNull($recentUser->_score);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Analytics Tests
    |--------------------------------------------------------------------------
    */

    public function test_get_analytics_returns_array(): void
    {
        $analytics = User::search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->using('levenshtein')
            ->typoTolerance(2)
            ->getAnalytics();

        $this->assertIsArray($analytics);
    }

    public function test_get_analytics_contains_search_info(): void
    {
        $analytics = User::search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->using('levenshtein')
            ->typoTolerance(3)
            ->tokenize()
            ->matchAll()
            ->getAnalytics();

        $this->assertEquals('john', $analytics['search_term']);
        $this->assertEquals('levenshtein', $analytics['algorithm']);
        $this->assertEquals(3, $analytics['typo_tolerance']);
        $this->assertTrue($analytics['tokenized']);
        $this->assertEquals('all', $analytics['token_mode']);
        $this->assertContains('name', $analytics['columns_searched']);
    }

    public function test_get_analytics_reflects_configuration(): void
    {
        $analytics = User::search('test')
            ->ignoreStopWords()
            ->accentInsensitive()
            ->cache(60)
            ->boostRecent()
            ->getAnalytics();

        $this->assertTrue($analytics['stop_words_active']);
        $this->assertTrue($analytics['accent_insensitive']);
        $this->assertTrue($analytics['cached']);
        $this->assertTrue($analytics['recency_boost']);
    }

    /*
    |--------------------------------------------------------------------------
    | Search Combination Tests
    |--------------------------------------------------------------------------
    */

    public function test_all_new_features_work_together(): void
    {
        $results = User::search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->boostRecent(1.5)
            ->withRelevance()
            ->highlight('em')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_typo_tolerance_zero_disables_typo_matches_for_fuzzy_algorithm(): void
    {
        $strict = User::search('jonh')->using('fuzzy')->typoTolerance(0)->get();
        $loose  = User::search('jonh')->using('fuzzy')->typoTolerance(2)->get();

        $this->assertCount(0, $strict, 'typoTolerance(0) must not match "John" for "jonh"');
        $this->assertContains('John Doe', $loose->pluck('name')->all());
    }

    public function test_extended_syntax_supports_length_aware_paginate(): void
    {
        $page = User::search("'jo")->extended()->paginate(2, 'page', 1);

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $page);
        $this->assertGreaterThanOrEqual(3, $page->total()); // John Doe, Jon Snow, Johnny Bravo, Bob Johnson
        $this->assertCount(2, $page->items());
    }
}
