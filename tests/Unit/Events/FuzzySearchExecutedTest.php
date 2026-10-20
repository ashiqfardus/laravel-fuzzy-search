<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Events;

require_once __DIR__ . '/../../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\ListColumnsUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\ZeroConfigUser;
use Illuminate\Support\Facades\DB;
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

    /** ER-205 (a): candidateCount is the matches before the page/limit cut, not the items searched. */
    public function test_in_memory_candidate_count_is_the_matches_before_the_page_cut(): void
    {
        $items  = collect([['name' => 'John Doe'], ['name' => 'Johnny'], ['name' => 'Zed'], ['name' => 'Bob']]);
        $search = fn () => FuzzySearch::on($items)->search('john')->searchIn(['name']);

        $events = $this->capture(fn () => $search()->take(1)->get());
        $this->assertSame([2, 1], [$events[0]->candidateCount, $events[0]->resultCount]);

        $events = $this->capture(fn () => $search()->skip(1)->take(5)->get());
        $this->assertSame([2, 1], [$events[0]->candidateCount, $events[0]->resultCount]);

        $events = $this->capture(fn () => FuzzySearch::on($items)->search('qqqq')->searchIn(['name'])->get());
        $this->assertSame([0, 0], [$events[0]->candidateCount, $events[0]->resultCount]);
    }

    /**
     * ER-205 (b): under default_algorithm => 'like' every search that names no algorithm reports
     * `like` (README: the name as given), a zero-config model as a declared one and the query
     * builder; using('like') still reports `simple`.
     */
    public function test_default_algorithm_like_is_reported_as_like_on_zero_config_models_too(): void
    {
        config(['fuzzy-search.default_algorithm' => 'like']);

        $algorithms = fn (callable $search) => array_map(fn (FuzzySearchExecuted $e) => $e->algorithm, $this->capture($search));

        foreach ([ZeroConfigUser::class, ListColumnsUser::class] as $model) {
            $this->assertSame(['like'], $algorithms(fn () => $model::search('john')->get()), "{$model} get");
            $this->assertSame(['like'], $algorithms(fn () => $model::search('john')->first()), "{$model} first");
            $this->assertSame(['like'], $algorithms(fn () => $model::search('john')->paginate(2)), "{$model} paginate");
            $this->assertSame(['like'], $algorithms(fn () => $model::search('john')->simplePaginate(2)), "{$model} simplePaginate");
        }

        $this->assertSame(['like'], $algorithms(fn () => (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('john')->searchIn(['name'])->get()), 'query builder');
        $this->assertSame(['simple'], $algorithms(fn () => ZeroConfigUser::search('john')->using('like')->get()), "using('like')");
        $this->assertSame('like', ZeroConfigUser::search('john')->getDebugInfo()['algorithm']);
    }
}
