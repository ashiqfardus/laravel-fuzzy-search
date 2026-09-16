<?php
// tests/Unit/ConfigWiringTest.php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class ConfigWiringTest extends TestCase
{
    public function test_scoring_exact_match_value_drives_raw_scores(): void
    {
        config(['fuzzy-search.scoring' => ['exact_match' => 1000, 'prefix_match' => 80, 'contains' => 60, 'fuzzy_match' => 50]]);

        // Raw SearchBuilder (not User::search()) so only 'name' is searched — User's default
        // $searchable config also weights 'email', and searchIn() adds to that set rather than
        // replacing it (see tests/Unit/SearchBuilderTest.php dedup regression test), which would
        // add a second column's score on top of the exact-match value under test.
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $exact = $builder->search('John Doe')->searchIn(['name' => 1])->using('like')->get()->first();

        $this->assertSame('John Doe', $exact->name);
        $this->assertEquals(1000.0, (float) $exact->_raw_score);
    }

    public function test_scoring_values_appear_in_sql_relevance_ordering(): void
    {
        config(['fuzzy-search.scoring' => ['exact_match' => 123, 'prefix_match' => 45, 'contains' => 6, 'fuzzy_match' => 1]]);

        $bindings = User::search('john')->searchIn(['name' => 1])->getBindings();

        $this->assertContains(123, $bindings);
        $this->assertContains(45, $bindings); // prefix × prefixBoost(1.0), rounded to an int (PostgreSQL rejects a fractional bind here)
        $this->assertContains(6, $bindings);
    }

    public function test_highlighting_config_provides_default_tags_and_auto_enable(): void
    {
        config(['fuzzy-search.highlighting' => ['enabled' => true, 'tag_open' => '<b class="hit">', 'tag_close' => '</b>']]);

        $user = User::search('john')->using('like')->get()->first();

        $this->assertStringContainsString('<b class="hit">John</b>', $user->_highlighted['name']);
    }

    public function test_highlight_without_arguments_uses_config_tags(): void
    {
        config(['fuzzy-search.highlighting' => ['enabled' => false, 'tag_open' => '<u>', 'tag_close' => '</u>']]);

        $user = User::search('john')->using('like')->highlight()->get()->first();

        $this->assertStringContainsString('<u>John</u>', $user->_highlighted['name']);
    }

    public function test_performance_max_patterns_is_the_default_cap(): void
    {
        config(['fuzzy-search.performance.max_patterns' => 10]);

        // Raw SearchBuilder — see note in test_scoring_exact_match_value_drives_raw_scores();
        // User::search()'s auto-added 'email' column would double the WHERE/ORDER BY pattern
        // count this test caps.
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $bindings = $builder->search('johnathan')->searchIn(['name'])->using('fuzzy')->typoTolerance(2)->getBindings();
        $likeBindings = array_filter($bindings, fn ($b) => is_string($b) && str_contains($b, '%'));

        // relevance ORDER BY adds 2 LIKE bindings per column on top of the 10 capped WHERE patterns
        $this->assertLessThanOrEqual(12, count($likeBindings));
    }

    public function test_unicode_normalize_config_enables_normalization_by_default(): void
    {
        config(['fuzzy-search.unicode.normalize' => true]);
        $this->assertTrue(User::search('cafe')->getDebugInfo()['unicode_normalize']);

        config(['fuzzy-search.unicode.normalize' => false]);
        $this->assertFalse(User::search('cafe')->getDebugInfo()['unicode_normalize']);
    }

    /**
     * Pins the shipped config/fuzzy-search.php defaults independently of TestCase's mirror,
     * so a regression like shipping 'normalize' => true (the inert v2.0 value, now live) is
     * caught even though tests/TestCase.php hardcodes its own defaults.
     */
    public function test_published_config_declares_the_wired_defaults(): void
    {
        $config = require __DIR__ . '/../../config/fuzzy-search.php';

        $this->assertSame(false, $config['unicode']['normalize']);
        $this->assertSame(['exact_match' => 100, 'prefix_match' => 80, 'contains' => 60, 'fuzzy_match' => 50], $config['scoring']);
        $this->assertSame(['max_patterns' => 100], $config['performance']);
        $this->assertSame(false, $config['highlighting']['enabled']);
    }

    public function test_debounce_is_deprecated(): void
    {
        $deprecations = [];
        set_error_handler(function ($errno, $errstr) use (&$deprecations) {
            if ($errno === E_USER_DEPRECATED) { $deprecations[] = $errstr; return true; }
            return false;
        });

        try {
            User::search('john')->debounce(300);
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString('debounce()', $deprecations[0]);
    }
}
