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
     * paginate() must throw BadMethodCallException when extended syntax is active —
     * the LIKE driver path would silently discard all AST operators.
     * Use simplePaginate() or get() instead.
     */
    public function test_paginate_throws_for_extended_syntax(): void
    {
        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/extended/i');

        $builder
            ->search('alice')
            ->extended()
            ->searchIn(['name'])
            ->paginate();
    }

    /**
     * simplePaginate() must NOT throw for extended syntax — it delegates to get()
     * which correctly routes through the AST compiler.
     */
    public function test_simple_paginate_works_for_extended_syntax(): void
    {
        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $result = $builder
            ->search('alice')
            ->extended()
            ->searchIn(['name'])
            ->simplePaginate(10);

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\Paginator::class, $result);
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

    /**
     * min_search_length boundary: a single-character term must return empty
     * collection when the published default (2) is active.
     */
    public function test_min_search_length_returns_empty_for_short_term(): void
    {
        config(['fuzzy-search.min_search_length' => 2, 'fuzzy-search.allow_empty_search' => true]);

        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $results = $builder
            ->search('j') // length 1 < min 2
            ->searchIn(['name'])
            ->get();

        $this->assertCount(0, $results);
    }

    /**
     * min_search_length boundary: a term at or above the minimum executes normally.
     */
    public function test_min_search_length_executes_for_term_at_minimum(): void
    {
        config(['fuzzy-search.min_search_length' => 2]);

        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $results = $builder
            ->search('jo') // length 2 == min 2 — must execute
            ->searchIn(['name'])
            ->get();

        // 'jo' matches John, Johnny, Jon — expect at least one result
        $this->assertNotEmpty($results);
    }
}
