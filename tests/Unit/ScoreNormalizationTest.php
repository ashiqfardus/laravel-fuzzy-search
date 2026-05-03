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
}
