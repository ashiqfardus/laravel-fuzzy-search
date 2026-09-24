<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;

class InMemorySearchTest extends TestCase
{
    public function test_search_on_array_of_arrays(): void
    {
        $items = [
            ['name' => 'John Doe',     'email' => 'john@x.com'],
            ['name' => 'Jane Smith',   'email' => 'jane@x.com'],
            ['name' => 'Johnny Bravo', 'email' => 'johnny@x.com'],
        ];

        $results = FuzzySearch::on($items)->search('john')->searchIn(['name'])->get();

        $this->assertCount(2, $results);
        $names = array_column($results->all(), 'name');
        $this->assertContains('John Doe', $names);
        $this->assertContains('Johnny Bravo', $names);
    }

    public function test_max_items_ceiling_throws(): void
    {
        config(['fuzzy-search.in_memory.max_items' => 5]);
        $items = array_fill(0, 10, ['name' => 'x']);

        $this->expectException(\InvalidArgumentException::class);
        FuzzySearch::on($items);
    }

    public function test_returns_collection(): void
    {
        $results = FuzzySearch::on([['name' => 'John']])->search('john')->searchIn(['name'])->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_score_normalization_applied(): void
    {
        $items = [
            ['name' => 'John'],
            ['name' => 'John Doe'],
            ['name' => 'Johnny'],
        ];
        $results = FuzzySearch::on($items)->search('john')->searchIn(['name'])->get();
        $this->assertGreaterThan(0, $results->count());
        foreach ($results as $row) {
            $this->assertGreaterThanOrEqual(0.0, $row['_score']);
            $this->assertLessThanOrEqual(1.0, $row['_score']);
        }
    }

    public function test_take_and_skip(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['name' => 'john' . $i];
        }
        $page1 = FuzzySearch::on($items)->search('john')->searchIn(['name'])->take(3)->get();
        $this->assertCount(3, $page1);

        $page2 = FuzzySearch::on($items)->search('john')->searchIn(['name'])->skip(3)->take(3)->get();
        $this->assertCount(3, $page2);
    }

    // -------------------------------------------------------------------------
    // Case folding covers every script, as the SQL path's PHP scoring does
    // -------------------------------------------------------------------------

    /** The row whose $column is $value, from an in-memory search for $term. */
    private function rowFor(string $term, string $value, array $values): ?array
    {
        return FuzzySearch::on(array_map(fn ($v) => ['name' => $v], $values))
            ->search($term)->searchIn(['name'])->get()
            ->firstWhere('name', $value);
    }

    public function test_upper_case_accented_latin_matches_its_lower_case_form_exactly(): void
    {
        $row = $this->rowFor('ÉCOLE', 'école', ['école', 'lycée']);

        $this->assertNotNull($row);
        $this->assertSame(100, $row['_raw_score'], 'ÉCOLE must be an exact match for école, not a similar_text near-miss');

        $this->assertSame(100, $this->rowFor('école', 'ÉCOLE', ['ÉCOLE', 'LYCÉE'])['_raw_score'] ?? null);
    }

    public function test_cyrillic_and_greek_case_pairs_match(): void
    {
        foreach ([['МОСКВА', 'москва'], ['москва', 'МОСКВА'], ['ΑΘΗΝΑ', 'αθηνα'], ['αθηνα', 'ΑΘΗΝΑ']] as [$term, $value]) {
            $this->assertSame(100, $this->rowFor($term, $value, [$value, 'other'])['_raw_score'] ?? null, "{$term} vs {$value}: exact");
        }

        $prefix = $this->rowFor('МОСК', 'москва', ['москва']);
        $this->assertSame(60, $prefix['_raw_score'] ?? null, 'a Cyrillic prefix in the other case');

        $contains = $this->rowFor('ΘΗΝ', 'αθηνα', ['αθηνα']);
        $this->assertSame(30, $contains['_raw_score'] ?? null, 'a Greek substring in the other case');
    }
}
