<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Drivers\TrigramDriver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * The trigram LIKE fallback pads the term ('  xyzq ') and trims each gram, so the edge grams
 * were one and two characters long: '%x%' and '%xy%' matched almost every row, and 'xyzq'
 * returned every user (each email holds the x of "example"). A gram shorter than three
 * characters is now dropped, unless the term itself is shorter.
 */
class TrigramShortGramTest extends TestCase
{
    public function test_a_term_with_no_near_match_finds_nothing(): void
    {
        $this->assertSame([], User::search('xyzq')->using('trigram')->get()->pluck('name')->all());
        $this->assertSame(0, User::search('xyzq')->using('trigram')->count());
        $this->assertSame(0, User::search('xyzq')->using('trigram')->paginate(10)->total());
    }

    public function test_the_trigram_patterns_are_the_term_and_its_three_character_grams(): void
    {
        $driver = new TrigramDriver(config('fuzzy-search'), 'sqlite');
        $bindings = fn (string $term) => $driver->apply($this->app['db']->table('users'), 'name', $term)->getBindings();

        $this->assertSame(['%xyzq%', '%xyz%', '%yzq%'], $bindings('xyzq'));
        $this->assertSame(['%bo%'], $bindings('bo'));
        $this->assertSame(['%w%'], $bindings('w'));
    }

    public function test_a_near_match_is_still_found(): void
    {
        $this->assertSame(['Alice Smith'], User::search('smiht')->using('trigram')->searchIn(['name'])->get()->pluck('name')->all());
        $this->assertSame(1, User::search('smiht')->using('trigram')->searchIn(['name'])->count());
    }

    public function test_a_one_or_two_letter_term_still_matches(): void
    {
        $names = fn (string $term) => User::search($term)->using('trigram')->searchIn(['name'])->get()->pluck('name')->sort()->values()->all();

        $this->assertSame(['Bob Johnson'], $names('bo'));

        config(['fuzzy-search.min_search_length' => 1]); // ships at 2, which would return nothing for 'w'
        $this->assertSame(['Charlie Brown', 'Jon Snow'], $names('w'));
    }
}
