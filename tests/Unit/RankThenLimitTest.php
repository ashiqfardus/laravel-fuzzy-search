<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

// Load shared models (needed for the Eloquent count()/get() parity test below).
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
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
            // Force DB order = insertion order on the underlying query (it leads the ORDER BY, ahead of
            // the SQL score ordering). Not SearchBuilder::orderBy(): an explicit order replaces the
            // relevance order, so PHP rescoring would no longer re-rank.
            ->query(fn ($q) => $q->orderBy('id', 'asc'))
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
            ->query(fn ($q) => $q->orderBy('id', 'asc'))
            ->skip(1)   // Skip the top-scored result
            ->take(2)
            ->get();

        // 'john' (exact match) was scored #1 and skipped — should NOT appear on page 2
        $this->assertNotContains('john', $page2->pluck('name')->toArray());
        $this->assertCount(2, $page2);
    }

    public function test_paginate_ranks_globally_before_slicing(): void
    {
        $this->app['db']->table('users')->truncate();
        for ($i = 1; $i <= 5; $i++) {
            $this->app['db']->table('users')->insert([
                'name' => "johnxxx{$i}", 'email' => "weak{$i}@test.com",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->app['db']->table('users')->insert([
            'name' => 'john', 'email' => 'john@test.com', 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['fuzzy-search.max_candidates' => 100]);

        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $page1 = $builder->search('john')->searchIn(['name'])->withRelevance()->query(fn ($q) => $q->orderBy('id', 'asc'))->paginate(2, 'page', 1);

        $this->assertSame('john', $page1->items()[0]->name, 'exact match must lead page 1 even though it was inserted last');
        $this->assertSame(6, $page1->total());
        $this->assertSame(3, $page1->lastPage());
    }

    public function test_paginate_page_two_is_the_next_ranked_slice(): void
    {
        $this->app['db']->table('users')->truncate();
        foreach (['john', 'johnny', 'johnathan', 'johnxxxxx'] as $i => $name) {
            $this->app['db']->table('users')->insert([
                'name' => $name, 'email' => "p{$i}@test.com", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        config(['fuzzy-search.max_candidates' => 100]);

        $make = fn () => (new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class)))
            ->search('john')->searchIn(['name'])->withRelevance();

        $ranked = $make()->take(4)->get()->pluck('name')->all();
        $page2  = $make()->paginate(2, 'page', 2);

        $this->assertSame(array_slice($ranked, 2, 2), collect($page2->items())->pluck('name')->all());
    }

    public function test_paginate_beyond_candidate_ceiling_falls_back_to_db_pagination(): void
    {
        $this->app['db']->table('users')->truncate();
        for ($i = 1; $i <= 6; $i++) {
            $this->app['db']->table('users')->insert([
                'name' => "john{$i}", 'email' => "c{$i}@test.com", 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        config(['fuzzy-search.max_candidates' => 3]);

        $builder = new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class));
        $page3 = $builder->search('john')->searchIn(['name'])->paginate(2, 'page', 3); // offset 4 >= ceiling 3

        $this->assertCount(2, $page3->items());
        $this->assertSame(6, $page3->total());
    }

    public function test_count_matches_get_count_for_query_builder_with_relevance(): void
    {
        $this->app['db']->table('users')->truncate();
        for ($i = 1; $i <= 5; $i++) {
            $this->app['db']->table('users')->insert([
                'name' => "john{$i}", 'email' => "qb{$i}@test.com",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        config(['fuzzy-search.max_candidates' => 100]);

        $make = fn () => (new SearchBuilder($this->app['db']->table('users'), app(FuzzySearch::class)))
            ->search('john')->searchIn(['name'])->withRelevance();

        // Two separate builders — a SearchBuilder is single-use.
        $this->assertSame($make()->get()->count(), $make()->count());
    }

    public function test_count_matches_get_count_for_eloquent_with_relevance(): void
    {
        $this->app['db']->table('users')->truncate();
        for ($i = 1; $i <= 5; $i++) {
            $this->app['db']->table('users')->insert([
                'name' => "john{$i}", 'email' => "el{$i}@test.com",
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        config(['fuzzy-search.max_candidates' => 100]);

        $make = fn () => User::search('john')->searchIn(['name'])->withRelevance();

        // Two separate builders — a SearchBuilder is single-use.
        $this->assertSame($make()->get()->count(), $make()->count());
    }
}
