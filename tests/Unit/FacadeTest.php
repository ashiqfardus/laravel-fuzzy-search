<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Facades\FuzzySearch as FuzzySearchFacade;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\InMemorySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * L6 (round 10). The facade was the one shipped PHP file no test loaded: a parse error in it passed
 * every suite, while the README's In-Memory example (FuzzySearch::on()) fataled for every consumer.
 */
class FacadeTest extends TestCase
{
    public function test_the_facade_resolves_to_the_bound_fuzzy_search_and_proxies_it(): void
    {
        $this->assertSame(app(FuzzySearch::class), FuzzySearchFacade::getFacadeRoot());

        $search = FuzzySearchFacade::on([['name' => 'John Doe'], ['name' => 'Alice Smith']]);
        $this->assertInstanceOf(InMemorySearch::class, $search);
        $this->assertSame(['John Doe'], $search->search('john')->searchIn(['name'])->get()->pluck('name')->all());

        $this->assertSame(1, FuzzySearchFacade::levenshteinDistance('john', 'jon'));

        $filter = FuzzySearchFacade::tableSearch(['name']);
        $this->assertInstanceOf(\Closure::class, $filter);
        $this->assertSame(['Alice Smith'], $filter(User::query(), 'alice')->pluck('name')->all());
    }
}
