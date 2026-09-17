<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class ScoreNormalizationTest extends TestCase
{
    public function test_score_is_normalized_to_zero_one_range(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $results = $builder->search('john')->searchIn(['name'])->withRelevance()->get();

        $this->assertGreaterThan(0, $results->count());
        foreach ($results as $row) {
            $this->assertGreaterThanOrEqual(0.0, $row->_score);
            $this->assertLessThanOrEqual(1.0, $row->_score);
        }
    }

    public function test_top_result_has_normalized_score_of_one(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $results = $builder->search('john')->searchIn(['name'])->withRelevance()->get();
        $this->assertEquals(1.0, $results->first()->_score);
    }

    public function test_raw_score_preserved_as_underscore_raw_score(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $results = $builder->search('john')->searchIn(['name'])->withRelevance()->get();

        foreach ($results as $row) {
            $this->assertObjectHasProperty('_raw_score', $row);
            $this->assertGreaterThanOrEqual(0, $row->_raw_score);
        }
    }

    public function test_empty_results_no_division_by_zero(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $results = $builder->search('xqzxqzxqzxqz')->searchIn(['name'])->withRelevance()->get();
        $this->assertEquals(0, $results->count());
    }

    public function test_null_direct_column_still_contributes_an_empty_fuzzy_score(): void
    {
        // v2.0 fed '' into the fuzzy branch for a missing/NULL direct column, so with
        // typoTolerance(2) a 2-letter term scored (20 - 2*4) x weight = 12 against it.
        // columnValues() now returns [] for an empty value, which must not silently
        // drop that contribution for a direct (non-relation) column.
        $this->app['db']->table('products')->insert([
            'title' => 'ab', 'description' => null, 'price' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $builder = new SearchBuilder($this->app['db']->table('products'), app(FuzzySearch::class));
        $results = $builder->search('ab')->searchIn(['title', 'description'])
            ->using('like')->typoTolerance(2)->withRelevance()->get();

        $row = $results->firstWhere('title', 'ab');
        $this->assertNotNull($row);
        // 100 (exact match on title, weight 1) + 12 (empty description, fuzzy floor, weight 1)
        $this->assertEquals(112.0, (float) $row->_raw_score);
    }
}
