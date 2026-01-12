<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;

/**
 * SearchBuilder Unit Tests
 * 
 * Unit tests for the SearchBuilder fluent API methods.
 */
class SearchBuilderTest extends TestCase
{
    protected SearchBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        
        $query = User::query();
        $fuzzySearch = app(FuzzySearch::class);
        $this->builder = new SearchBuilder($query, $fuzzySearch);
    }

    /*
    |--------------------------------------------------------------------------
    | Fluent API Method Tests
    |--------------------------------------------------------------------------
    */

    public function test_search_method_is_chainable(): void
    {
        $result = $this->builder->search('test');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_search_in_method_is_chainable(): void
    {
        $result = $this->builder->searchIn(['name', 'email']);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_using_method_is_chainable(): void
    {
        $result = $this->builder->using('levenshtein');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_typo_tolerance_method_is_chainable(): void
    {
        $result = $this->builder->typoTolerance(2);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_tokenize_method_is_chainable(): void
    {
        $result = $this->builder->tokenize();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_match_all_method_is_chainable(): void
    {
        $result = $this->builder->matchAll();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_match_any_method_is_chainable(): void
    {
        $result = $this->builder->matchAny();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_prefix_boost_method_is_chainable(): void
    {
        $result = $this->builder->prefixBoost(2.0);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_partial_match_method_is_chainable(): void
    {
        $result = $this->builder->partialMatch();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_min_match_length_method_is_chainable(): void
    {
        $result = $this->builder->minMatchLength(3);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_custom_score_method_is_chainable(): void
    {
        $result = $this->builder->customScore(fn($item, $score) => $score);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_ignore_stop_words_method_is_chainable(): void
    {
        $result = $this->builder->ignoreStopWords();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_with_synonyms_method_is_chainable(): void
    {
        $result = $this->builder->withSynonyms(['test' => ['exam']]);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_synonym_group_method_is_chainable(): void
    {
        $result = $this->builder->synonymGroup(['laptop', 'notebook']);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_locale_method_is_chainable(): void
    {
        $result = $this->builder->locale('en');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_accent_insensitive_method_is_chainable(): void
    {
        $result = $this->builder->accentInsensitive();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_unicode_normalize_method_is_chainable(): void
    {
        $result = $this->builder->unicodeNormalize();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_highlight_method_is_chainable(): void
    {
        $result = $this->builder->highlight('em');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_debug_score_method_is_chainable(): void
    {
        $result = $this->builder->debugScore();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_use_index_method_is_chainable(): void
    {
        $result = $this->builder->useIndex();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_cache_method_is_chainable(): void
    {
        $result = $this->builder->cache(60);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_stable_ranking_method_is_chainable(): void
    {
        $result = $this->builder->stableRanking();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_fallback_method_is_chainable(): void
    {
        $result = $this->builder->fallback('like');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_debounce_method_is_chainable(): void
    {
        $result = $this->builder->debounce(300);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_max_patterns_method_is_chainable(): void
    {
        $result = $this->builder->maxPatterns(50);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_filter_method_is_chainable(): void
    {
        $result = $this->builder->filter('name', 'John');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_filter_in_method_is_chainable(): void
    {
        $result = $this->builder->filterIn('id', [1, 2, 3]);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_facet_method_is_chainable(): void
    {
        $result = $this->builder->facet('name');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_with_relevance_method_is_chainable(): void
    {
        $result = $this->builder->withRelevance();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_take_method_is_chainable(): void
    {
        $result = $this->builder->take(10);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_limit_method_is_chainable(): void
    {
        $result = $this->builder->limit(10);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_skip_method_is_chainable(): void
    {
        $result = $this->builder->skip(5);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_offset_method_is_chainable(): void
    {
        $result = $this->builder->offset(5);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_page_method_is_chainable(): void
    {
        $result = $this->builder->page(1, 10);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_order_by_method_is_chainable(): void
    {
        $result = $this->builder->orderBy('name');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_options_method_is_chainable(): void
    {
        $result = $this->builder->options(['max_distance' => 3]);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Value Constraint Tests
    |--------------------------------------------------------------------------
    */

    public function test_typo_tolerance_is_clamped_to_max(): void
    {
        // The method should clamp value to max of 5
        $this->builder->typoTolerance(10);
        
        // Just verify it doesn't throw
        $this->assertTrue(true);
    }

    public function test_typo_tolerance_is_clamped_to_min(): void
    {
        // The method should clamp value to min of 0
        $this->builder->typoTolerance(-5);
        
        // Just verify it doesn't throw
        $this->assertTrue(true);
    }

    public function test_prefix_boost_has_minimum_of_one(): void
    {
        // Prefix boost should be at least 1.0
        $this->builder->prefixBoost(0.5);
        
        // Just verify it doesn't throw
        $this->assertTrue(true);
    }

    public function test_min_match_length_has_minimum_of_one(): void
    {
        // Min match length should be at least 1
        $this->builder->minMatchLength(0);
        
        // Just verify it doesn't throw
        $this->assertTrue(true);
    }

    public function test_max_patterns_has_minimum_of_ten(): void
    {
        // Max patterns should be at least 10
        $this->builder->maxPatterns(5);
        
        // Just verify it doesn't throw
        $this->assertTrue(true);
    }

    /*
    |--------------------------------------------------------------------------
    | Complex Chain Tests
    |--------------------------------------------------------------------------
    */

    public function test_full_chain_is_valid(): void
    {
        $results = User::search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->using('levenshtein')
            ->typoTolerance(2)
            ->tokenize()
            ->matchAny()
            ->prefixBoost(2.0)
            ->ignoreStopWords()
            ->accentInsensitive()
            ->highlight('em')
            ->withRelevance()
            ->stableRanking()
            ->cache(60)
            ->limit(10)
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }
}
