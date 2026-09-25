<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** first() was declared ?Model, so on a query-builder source, whose rows are stdClass, it threw a TypeError. */
class QueryBuilderFirstTest extends TestCase
{
    private function search(string $term): SearchBuilder
    {
        return (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search($term)->searchIn(['name'])->using('like');
    }

    public function test_first_returns_the_row_of_a_query_builder_search(): void
    {
        $row = $this->search('jane')->first();

        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame('Jane Doe', $row->name);
        $this->assertNull($this->search('nobody')->first());
    }

    public function test_first_returns_the_row_on_a_cache_hit(): void
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $this->search('jane')->cache(60)->first();
        $row = $this->search('jane')->cache(60)->first();

        $this->assertInstanceOf(\stdClass::class, $row);
        $this->assertSame('Jane Doe', $row->name);
    }
}
