<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class MatchOffsetsTest extends TestCase
{
    public function test_matches_array_contains_indices_for_search_term(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $results = $builder->search('john')->searchIn(['name'])->highlight()->get();

        $first = $results->first();
        $this->assertObjectHasProperty('_matches', $first);
        $this->assertIsArray($first->_matches);
        $this->assertNotEmpty($first->_matches);

        $match = $first->_matches[0];
        $this->assertArrayHasKey('column', $match);
        $this->assertArrayHasKey('value', $match);
        $this->assertArrayHasKey('indices', $match);
        $this->assertIsArray($match['indices']);
    }

    public function test_indices_locate_search_term_in_value(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $results = $builder->search('Doe')->searchIn(['name'])->highlight()->get();

        $first = $results->first();
        foreach ($first->_matches as $match) {
            if ($match['column'] === 'name') {
                foreach ($match['indices'] as [$start, $end]) {
                    $this->assertEqualsIgnoringCase('Doe', substr($match['value'], $start, $end - $start + 1));
                }
            }
        }
    }

    public function test_highlighted_still_populated_for_backwards_compat(): void
    {
        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $results = $builder->search('john')->searchIn(['name'])->highlight()->get();

        $first = $results->first();
        $this->assertObjectHasProperty('_highlighted', $first);
    }

    public function test_render_highlighted_escapes_user_content(): void
    {
        $row = (object) [
            'name' => '<script>alert(1)</script> John',
            '_matches' => [
                ['column' => 'name', 'value' => '<script>alert(1)</script> John', 'indices' => [[26, 29]]],
            ],
        ];

        $rendered = \Ashiqfardus\LaravelFuzzySearch\SearchBuilder::renderHighlighted($row, 'name');

        $this->assertStringNotContainsString('<script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
        $this->assertStringContainsString('<mark>John</mark>', $rendered);
    }

    public function test_render_highlighted_returns_escaped_value_when_no_match(): void
    {
        $row = (object) [
            'name' => '<b>plain</b>',
            '_matches' => [],
        ];

        $rendered = \Ashiqfardus\LaravelFuzzySearch\SearchBuilder::renderHighlighted($row, 'name');
        $this->assertEquals(e('<b>plain</b>'), $rendered);
    }
}
