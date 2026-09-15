<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Drivers\FuzzyDriver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class FuzzyDriverPatternsTest extends TestCase
{
    private function bindingsFor(array $fuzzyConfig, string $term = 'john', array $topLevel = []): array
    {
        $config = array_merge(config('fuzzy-search'), $topLevel, ['fuzzy' => $fuzzyConfig]);
        $query  = (new FuzzyDriver($config, 'sqlite'))->apply($this->app['db']->table('users'), 'name', $term);
        return $query->getBindings();
    }

    public function test_distance_zero_emits_only_contains_and_prefix_patterns(): void
    {
        $bindings = $this->bindingsFor(['max_distance' => 0]);

        $this->assertSame(['%john%', 'john%'], $bindings);
    }

    public function test_distance_one_adds_single_edit_patterns_but_no_boundary_patterns(): void
    {
        $bindings = $this->bindingsFor(['max_distance' => 1]);

        $this->assertContains('%jo%n%', $bindings);         // omission
        $this->assertContains('%jo_n%', $bindings);         // substitution
        $this->assertContains('%jhon%', $bindings);         // transposition
        $this->assertNotContains('j%hn', $bindings);        // boundary pattern is distance-2 territory
    }

    public function test_distance_two_adds_boundary_patterns(): void
    {
        $bindings = $this->bindingsFor(['max_distance' => 2]);

        $this->assertContains('j%hn', $bindings);
        $this->assertContains('jo%n', $bindings);
    }

    public function test_short_terms_get_no_typo_patterns_below_min_word_length(): void
    {
        $bindings = $this->bindingsFor(['max_distance' => 2], 'jon', ['typo_tolerance' => ['max_distance' => 2, 'min_word_length' => 4]]);

        $this->assertSame(['%jon%', 'jon%'], $bindings);
    }

    public function test_max_patterns_caps_the_pattern_list(): void
    {
        $bindings = $this->bindingsFor(['max_distance' => 2], 'johnathan', ['max_patterns' => 10]);

        $this->assertCount(10, $bindings);
        $this->assertSame('%johnathan%', $bindings[0]); // highest-signal patterns are kept first
    }
}
