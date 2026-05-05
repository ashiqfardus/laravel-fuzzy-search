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
}
