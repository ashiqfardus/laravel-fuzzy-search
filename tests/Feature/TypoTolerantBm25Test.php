<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class TypoTolerantBm25Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        User::create(['name' => 'jonh smith', 'email' => 'typo@example.com']);
        app(IndexManager::class)->indexBatch(User::all());
    }

    public function test_a_typo_finds_the_indexed_term_through_the_dictionary(): void
    {
        $names = User::search('jonh')->useInvertedIndex()->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertContains('jonh smith', $names);
    }

    public function test_typo_tolerance_zero_disables_expansion(): void
    {
        $names = User::search('jonh')->useInvertedIndex()->typoTolerance(0)->get()->pluck('name')->all();

        $this->assertSame(['jonh smith'], $names);
    }

    public function test_the_exact_term_ranks_above_its_expansions(): void
    {
        $ranked = User::search('john')->useInvertedIndex()->get();

        $this->assertSame('John Doe', $ranked->first()->name);
        $this->assertGreaterThan(
            $ranked->firstWhere('name', 'jonh smith')->_raw_score,
            $ranked->first()->_raw_score
        );
    }

    public function test_short_terms_are_not_expanded(): void
    {
        // 'jo' (2 chars) is below typo_tolerance.min_word_length (4): only exact matches.
        $this->assertCount(0, User::search('jo')->useInvertedIndex()->get());
    }

    public function test_count_and_paginate_totals_include_expansion_matches(): void
    {
        $builder = fn () => User::search('jonh')->useInvertedIndex();

        $expected = $builder()->get()->count();
        $this->assertGreaterThan(1, $expected);
        $this->assertSame($expected, $builder()->count());
        $this->assertSame($expected, $builder()->paginate(1)->total());
    }

    public function test_config_can_disable_typo_tolerance_globally(): void
    {
        config(['fuzzy-search.typo_tolerance.enabled' => false]);

        $this->assertSame(['jonh smith'], User::search('jonh')->useInvertedIndex()->get()->pluck('name')->all());
    }

    public function test_debug_info_reports_the_weighted_terms(): void
    {
        $builder = User::search('jonh')->useInvertedIndex();
        $builder->get();

        $terms = $builder->getDebugInfo()['index_terms'];
        $this->assertSame(1.0, $terms['jonh']);
        $this->assertSame(0.5, $terms['john']); // 1 - 2/4
    }
}
