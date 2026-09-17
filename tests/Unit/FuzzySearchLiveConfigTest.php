<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class FuzzySearchLiveConfigTest extends TestCase
{
    public function test_the_container_instance_sees_runtime_config_changes(): void
    {
        $fuzzy = app(FuzzySearch::class);
        $query = $this->app['db']->table('users');

        config(['fuzzy-search.default_algorithm' => 'simple']);
        $fuzzy->applyFuzzyWhere($query, 'name', 'john');

        // The simple driver emits one plain LIKE; the levenshtein/fuzzy defaults emit pattern lists.
        $this->assertSame(1, substr_count(strtolower($query->toSql()), 'like'));
    }

    public function test_an_explicit_config_array_is_still_a_frozen_snapshot(): void
    {
        $fuzzy = new FuzzySearch(['default_algorithm' => 'simple']);
        $query = $this->app['db']->table('users');

        config(['fuzzy-search.default_algorithm' => 'levenshtein']);
        $fuzzy->applyFuzzyWhere($query, 'name', 'john');

        $this->assertSame(1, substr_count(strtolower($query->toSql()), 'like'));
    }
}
