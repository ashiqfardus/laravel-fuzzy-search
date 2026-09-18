<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Events;

require_once __DIR__ . '/../../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Event;

class FuzzySearchExecutedTest extends TestCase
{
    /** @return FuzzySearchExecuted[] */
    private function capture(callable $search): array
    {
        $events = [];
        Event::listen(FuzzySearchExecuted::class, function (FuzzySearchExecuted $e) use (&$events) {
            $events[] = $e;
        });
        $search();

        return $events;
    }

    public function test_five_argument_construction_still_works(): void
    {
        $e = new FuzzySearchExecuted('john', ['name'], 'fuzzy', 3, 1.5);

        $this->assertSame(-1, $e->resultCount);
        $this->assertSame('like', $e->path);
        $this->assertNull($e->modelClass);
    }

    public function test_like_path_reports_results_path_and_model(): void
    {
        $events = $this->capture(fn () => User::search('john')->get());

        $this->assertCount(1, $events);
        $this->assertSame('like', $events[0]->path);
        $this->assertSame(User::class, $events[0]->modelClass);
        $this->assertSame(User::search('john')->get()->count(), $events[0]->resultCount);
        $this->assertGreaterThanOrEqual($events[0]->resultCount, $events[0]->candidateCount);
    }

    public function test_bm25_and_extended_paths_are_named(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $bm25 = $this->capture(fn () => User::search('john')->useInvertedIndex()->get());
        $this->assertSame('bm25', $bm25[0]->path);
        $this->assertSame('bm25', $bm25[0]->algorithm);
        $this->assertSame(User::class, $bm25[0]->modelClass);

        $ext = $this->capture(fn () => User::search('x')->extended('name:john')->get());
        $this->assertSame('extended', $ext[0]->path);
        $this->assertSame('name:john', $ext[0]->searchTerm);
        $this->assertSame(User::search('x')->extended('name:john')->get()->count(), $ext[0]->resultCount);
    }

    public function test_a_bm25_search_that_matches_nothing_still_fires_the_event(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $events = $this->capture(fn () => User::search('zzzz')->useInvertedIndex()->get());

        $this->assertCount(1, $events);
        $this->assertSame('bm25', $events[0]->path);
        $this->assertSame('bm25', $events[0]->algorithm);
        $this->assertSame(0, $events[0]->resultCount);
        $this->assertSame(0, $events[0]->candidateCount);
    }

    public function test_paginate_reports_the_page_size_as_results_and_the_total_as_candidates(): void
    {
        $events = $this->capture(fn () => User::search('jo')->paginate(2));

        $this->assertSame('like', $events[0]->path);
        $this->assertSame(2, $events[0]->resultCount);
        $this->assertSame(User::search('jo')->count(), $events[0]->candidateCount);
    }

    public function test_simple_paginate_reports_the_page_size_not_the_look_ahead_row(): void
    {
        $paginator = null;
        $events = $this->capture(function () use (&$paginator) {
            $paginator = User::search('jo')->simplePaginate(2);
        });

        $this->assertCount(1, $events);
        $this->assertSame(2, $events[0]->resultCount);   // the perPage + 1 probe row is not a result
        $this->assertCount(2, $paginator->items());
        $this->assertTrue($paginator->hasMorePages());
    }

    public function test_in_memory_search_dispatches_with_its_own_path(): void
    {
        $events = $this->capture(fn () => FuzzySearch::on(collect([['name' => 'John Doe'], ['name' => 'Jane']]))->search('john')->searchIn(['name'])->get());

        $this->assertCount(1, $events);
        $this->assertSame('in_memory', $events[0]->path);
        $this->assertSame('in_memory', $events[0]->algorithm);
        $this->assertNull($events[0]->modelClass);
        $this->assertSame(1, $events[0]->resultCount);
    }
}
