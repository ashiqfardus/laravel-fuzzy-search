<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class DriverAlgorithmTest extends DatabaseTestCase
{
    private FuzzySearch $fuzzySearch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuzzySearch = app(FuzzySearch::class);
    }

    public function test_simple_finds_exact_substring(): void
    {
        $results = $this->builder()->search('Jane')->searchIn(['name'])->using('simple')->get();
        $this->assertContains('Jane Smith', $results->pluck('name')->toArray());
    }

    public function test_fuzzy_tolerates_one_typo(): void
    {
        $results = $this->builder()->search('Jonh')->searchIn(['name'])->using('fuzzy')->get();
        $names = $results->pluck('name')->toArray();
        $matched = array_filter($names, fn($n) => str_starts_with($n, 'John') || str_starts_with($n, 'Jon'));
        $this->assertNotEmpty($matched, "Expected fuzzy match for 'Jonh' to find John/Jon variants");
    }

    public function test_levenshtein_finds_close_matches(): void
    {
        $results = $this->builder()->search('Jone')->searchIn(['name'])->using('levenshtein')->get();
        $names = $results->pluck('name')->toArray();
        $matched = array_filter($names, fn($n) => str_starts_with($n, 'Jon') || str_starts_with($n, 'John'));
        $this->assertNotEmpty($matched);
    }

    public function test_soundex_does_not_throw(): void
    {
        // Soundex behavior varies by DB engine — just assert no exception and returns a collection
        $results = $this->builder()->search('stephen')->searchIn(['name'])->using('soundex')->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_trigram_does_not_throw(): void
    {
        $results = $this->builder()->search('alice')->searchIn(['name'])->using('trigram')->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_similar_text_does_not_throw(): void
    {
        $results = $this->builder()->search('john')->searchIn(['name'])->using('similar_text')->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_accent_insensitive_on_term_matches_unaccented_row(): void
    {
        // 'café' term → stripped to 'cafe' → matches 'cafe' row
        $results = $this->builder()
            ->search('café')
            ->searchIn(['name'])
            ->using('simple')
            ->accentInsensitive()
            ->get();
        $this->assertContains('cafe', $results->pluck('name')->toArray());
    }

    public function test_exact_match_ranks_first(): void
    {
        $results = $this->builder()
            ->search('Jane')
            ->searchIn(['name'])
            ->withRelevance()
            ->get();
        $this->assertEquals('Jane Smith', $results->first()->name);
    }

    private function builder(): SearchBuilder
    {
        return new SearchBuilder(
            $this->app['db']->table('integration_users'),
            $this->fuzzySearch
        );
    }
}
