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

    public function test_non_matching_column_with_an_unclosed_angle_bracket_is_not_stripped(): void
    {
        // _highlighted[$column] holds the raw, un-escaped value for a searched column
        // that did not match. displayValueFor() must not strip_tags() it: PHP's
        // strip_tags() treats an unterminated "<" as an open tag and eats everything
        // after it, silently truncating content like "fits S<M<L".
        $this->app['db']->table('products')->insert([
            'title' => 'Special Shirt', 'description' => 'fits S<M<L', 'price' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $builder = new SearchBuilder($this->app['db']->table('products'), app(FuzzySearch::class));
        $results = $builder->search('Special')->searchIn(['title', 'description'])->highlight()->get();

        $row = $results->firstWhere('title', 'Special Shirt');
        $this->assertNotNull($row);

        $rendered = SearchBuilder::renderHighlighted($row, 'description');
        $this->assertSame('fits S&lt;M&lt;L', $rendered);
    }
}
