<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class IndexPathHighlightingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(IndexManager::class)->indexBatch(User::all());
    }

    public function test_typo_expansions_are_highlighted(): void
    {
        $john = User::search('jonh')->useInvertedIndex()->highlight('em')->get()->firstWhere('name', 'John Doe');

        $this->assertSame('<em>John</em> Doe', $john->_highlighted['name']);
        $this->assertSame('name', $john->_matches[0]['column']);
    }

    public function test_prefix_expansions_are_highlighted(): void
    {
        $johnny = User::search('joh')->useInvertedIndex()->asYouType()->highlight('em')->get()->firstWhere('name', 'Johnny Bravo');

        $this->assertSame('<em>Johnny</em> Bravo', $johnny->_highlighted['name']);
    }

    public function test_every_query_term_is_highlighted_and_ranges_do_not_overlap(): void
    {
        $john = User::search('john doe')->useInvertedIndex()->typoTolerance(0)->highlight('em')->get()->firstWhere('name', 'John Doe');

        $this->assertSame('<em>John</em> <em>Doe</em>', $john->_highlighted['name']);
    }

    public function test_paginated_index_results_are_highlighted_too(): void
    {
        $page = User::search('jonh')->useInvertedIndex()->highlight('em')->paginate(10);

        $this->assertStringContainsString('<em>', collect($page->items())->firstWhere('name', 'John Doe')->_highlighted['name']);
    }

    public function test_like_path_highlighting_is_unchanged(): void
    {
        $john = User::search('john doe')->using('like')->highlight('em')->get()->firstWhere('name', 'John Doe');

        $this->assertSame('<em>John Doe</em>', $john->_highlighted['name']);
    }

    public function test_like_path_keeps_adjacent_matches_as_separate_tags(): void
    {
        User::create(['name' => 'banana', 'email' => 'banana@example.com']);

        $row = User::search('an')->using('like')->highlight('em')->get()->firstWhere('name', 'banana');

        $this->assertSame('b<em>an</em><em>an</em>a', $row->_highlighted['name']);
        $this->assertCount(2, $row->_matches[0]['indices']);
    }
}
