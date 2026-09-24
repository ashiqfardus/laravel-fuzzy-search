<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
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

        $names = $results->pluck('name')->map('strtolower')->toArray();
        $this->assertTrue(
            in_array('alice smith', $names) || in_array('bob johnson', $names),
            'extended() with OR should return at least Alice or Bob when search term is empty'
        );
    }

    /**
     * paginate() supports extended/boolean syntax — it ranks globally via paginateRanked(),
     * which routes extended queries through compileExtendedQuery() just like get() does.
     */
    public function test_paginate_works_for_extended_syntax(): void
    {
        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $page = $builder
            ->search('alice')
            ->extended()
            ->searchIn(['name'])
            ->paginate(10);

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $page);
        $this->assertContains(
            'alice smith',
            collect($page->items())->pluck('name')->map('strtolower')->all()
        );
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

    /**
     * empty('0') is true, so a search for "0" (a SKU, a house number) was treated as the empty
     * search: EmptySearchTermException from get()/first()/simplePaginate()/FederatedSearch, and
     * every row from paginate()/count(). It is a one-character term like any other.
     */
    public function test_a_search_for_0_is_a_real_search(): void
    {
        config(['fuzzy-search.min_search_length' => 1]);
        User::create(['name' => 'Room 0', 'email' => 'room@example.com']);
        $federated = fn () => FederatedSearch::across([User::class])->search('0')->searchIn(['name', 'email']);

        foreach (['like' => fn () => User::search('0'), 'tokenize' => fn () => User::search('0')->tokenize()] as $path => $make) {
            $this->assertSame(['Room 0'], $make()->get()->pluck('name')->all(), "{$path} get");
            $this->assertSame('Room 0', $make()->first()?->name, "{$path} first");
            $this->assertSame(['Room 0'], collect($make()->simplePaginate(5)->items())->pluck('name')->all(), "{$path} simplePaginate");
            $this->assertSame(1, $make()->paginate(5)->total(), "{$path} paginate");
            $this->assertSame(1, $make()->count(), "{$path} count");
        }

        $this->assertSame(['Room 0'], $federated()->get()->pluck('name')->all());
        $this->assertSame(1, $federated()->paginate(5)->total());
        $this->assertSame(['User' => 1], $federated()->getCounts());
    }
    /**
     * Ruling ER-46: every terminal applies get()'s empty-term guard. count() and paginate() used
     * to list every row while get() threw.
     *
     * @return array<string, \Closure(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder): mixed>
     */
    private function terminals(): array
    {
        return [
            'get'            => fn ($b) => $b->get()->count(),
            'first'          => fn ($b) => $b->first() === null ? 0 : 1,
            'paginate'       => fn ($b) => $b->paginate(50)->total(),
            'simplePaginate' => fn ($b) => count($b->simplePaginate(50)->items()),
            'count'          => fn ($b) => $b->count(),
            'getFacets'      => fn ($b) => array_sum($b->facet('name')->getFacets()['name']),
        ];
    }

    public function test_every_terminal_throws_for_an_empty_term(): void
    {
        config(['fuzzy-search.allow_empty_search' => false]);

        $leaks = [];

        foreach (['empty' => '', 'whitespace' => "  \t "] as $label => $term) {
            foreach (['like' => false, 'index' => true] as $path => $index) {
                foreach ($this->terminals() as $name => $run) {
                    $builder = User::search($term);
                    if ($index) {
                        $builder->useInvertedIndex();
                    }

                    try {
                        $leaks[] = "{$label} {$path} {$name} returned " . json_encode($run($builder));
                    } catch (EmptySearchTermException) {
                        $this->addToAssertionCount(1);
                    }
                }
            }
        }

        $this->assertSame([], $leaks);
    }

    public function test_every_terminal_lists_every_row_when_empty_search_is_allowed(): void
    {
        config(['fuzzy-search.allow_empty_search' => true]);
        $all = User::query()->count();

        foreach (['like' => false, 'index' => true] as $path => $index) {
            foreach ($this->terminals() as $name => $run) {
                $builder = User::search('');
                if ($index) {
                    $builder->useInvertedIndex();
                }

                $this->assertSame($name === 'first' ? 1 : $all, $run($builder), "{$name} on the {$path} path");
            }
        }
    }

    public function test_extended_keeps_its_exemption_on_every_terminal(): void
    {
        config(['fuzzy-search.allow_empty_search' => false]);

        foreach ($this->terminals() as $name => $run) {
            $this->assertGreaterThan(0, $run(User::search('')->extended('john')), $name);
        }
    }
}
