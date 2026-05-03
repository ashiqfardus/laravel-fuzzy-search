<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;

class EmptySearchGuardTest extends TestCase
{
    /**
     * Regression test for P0-2: search('') with extended() must NOT throw
     * EmptySearchTermException because the extended query string provides the
     * real search term.
     */
    public function test_search_empty_with_extended_does_not_throw(): void
    {
        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        // Should not throw — extended() supplies the query, overriding the empty term.
        $results = $builder
            ->search('')
            ->extended('alice | bob')
            ->searchIn(['name'])
            ->get();

        $this->assertNotNull($results);
    }

    /**
     * Regression test for P0-2: search('') with no extended/searchBoolean and
     * allow_empty_search=false (default) MUST throw EmptySearchTermException.
     */
    public function test_search_empty_without_extended_throws(): void
    {
        config(['fuzzy-search.allow_empty_search' => false]);

        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $this->expectException(EmptySearchTermException::class);

        $builder
            ->search('')
            ->get();
    }
}
