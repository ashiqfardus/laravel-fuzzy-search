<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;

class RankThenLimitTest extends TestCase
{
    public function test_exact_match_appears_first_even_when_inserted_last(): void
    {
        $this->app['db']->table('users')->truncate();

        // 5 weak matches (all contain 'john' as substring — pass SQL filter)
        for ($i = 1; $i <= 5; $i++) {
            $this->app['db']->table('users')->insert([
                'name'       => "johnxxx{$i}",
                'email'      => "weak{$i}@test.com",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        // Exact match as row #6 — last inserted
        $this->app['db']->table('users')->insert([
            'name'       => 'john',
            'email'      => 'john@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['fuzzy-search.max_candidates' => 100]);

        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $results = $builder
            ->search('john')
            ->searchIn(['name'])
            ->withRelevance()
            ->orderBy('id', 'asc') // Force DB order = insertion order, bypass SQL score ordering
            ->take(3)
            ->get();

        // PHP rescore should rank 'john' #1 regardless of insertion order
        $this->assertEquals('john', $results->first()->name);
        $this->assertContains('john', $results->pluck('name')->toArray());
    }

    public function test_offset_is_applied_after_scoring(): void
    {
        $this->app['db']->table('users')->truncate();

        // Row 1: exact match
        $this->app['db']->table('users')->insert([
            'name' => 'john', 'email' => 'john@test.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Rows 2-5: weak matches
        for ($i = 2; $i <= 5; $i++) {
            $this->app['db']->table('users')->insert([
                'name' => "johnx{$i}", 'email' => "x{$i}@test.com",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        config(['fuzzy-search.max_candidates' => 100]);

        $builder = new SearchBuilder(
            $this->app['db']->table('users'),
            app(FuzzySearch::class)
        );

        $page2 = $builder
            ->search('john')
            ->searchIn(['name'])
            ->withRelevance()
            ->orderBy('id', 'asc')
            ->skip(1)   // Skip the top-scored result
            ->take(2)
            ->get();

        // 'john' (exact match) was scored #1 and skipped — should NOT appear on page 2
        $this->assertNotContains('john', $page2->pluck('name')->toArray());
        $this->assertCount(2, $page2);
    }
}
