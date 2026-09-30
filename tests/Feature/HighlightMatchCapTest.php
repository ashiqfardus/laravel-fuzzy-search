<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * Deep review RB-6. highlight() recorded every occurrence of the term in every value: its offsets
 * in _matches and a tagged copy in _highlighted. A long stored text that repeats the term (a
 * comment of "the the the …") cost megabytes a row: 15 rows of 60 KB took 57 MB and an 8 MB
 * response. A value is highlighted up to highlighting.max_matches occurrences.
 */
class HighlightMatchCapTest extends TestCase
{
    private function highlighted(string $title): object
    {
        return Product::search('zeta')->searchIn(['title', 'description'])->highlight('b')->get()->firstWhere('title', $title);
    }

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('products')->insert([
            ['title' => 'Long zeta', 'description' => rtrim(str_repeat('zeta ', 40))],
            ['title' => 'Short zeta', 'description' => 'zeta and Zeta'],
        ]);
    }

    public function test_a_value_is_highlighted_up_to_the_configured_number_of_matches(): void
    {
        config(['fuzzy-search.highlighting.max_matches' => 25]);

        $long    = $this->highlighted('Long zeta');
        $matches = collect($long->_matches)->firstWhere('column', 'description');

        $this->assertCount(25, $matches['indices']);
        $this->assertSame([0, 3], $matches['indices'][0]);
        $this->assertSame([120, 123], $matches['indices'][24], 'the first 25, in the value\'s order');
        $this->assertSame(25, substr_count($long->_highlighted['description'], '<b>zeta</b>'));
        $this->assertSame(rtrim(str_repeat('zeta ', 40)), strip_tags($long->_highlighted['description']), 'the rest of the value follows untagged');

        $short = $this->highlighted('Short zeta');
        $this->assertSame('<b>zeta</b> and <b>Zeta</b>', $short->_highlighted['description']);
    }

    public function test_the_shipped_limit_is_100_and_0_removes_it(): void
    {
        $this->assertSame(100, config('fuzzy-search.highlighting.max_matches'));
        $this->assertSame(40, substr_count($this->highlighted('Long zeta')->_highlighted['description'], '<b>'));

        DB::table('products')->insert(['title' => 'Huge zeta', 'description' => rtrim(str_repeat('zeta ', 51))]); // 254 characters

        config(['fuzzy-search.highlighting.max_matches' => 3]);
        $this->assertSame(3, substr_count($this->highlighted('Huge zeta')->_highlighted['description'], '<b>'));

        config(['fuzzy-search.highlighting.max_matches' => 0]);
        $this->assertSame(51, substr_count($this->highlighted('Huge zeta')->_highlighted['description'], '<b>'));
    }
}
