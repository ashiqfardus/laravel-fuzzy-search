<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;

/**
 * Review finding I1: count() unconditionally ran buildQuery() (the LIKE path), so it
 * disagreed with paginate()->total() whenever the builder used extended() or
 * useInvertedIndex(). Each test builds three separate, identically configured builders (a
 * SearchBuilder is single-use — see RankThenLimitTest) and checks count(), paginate()->total()
 * and get()->count() all agree.
 */
class CountMatchesPaginateTest extends TestCase
{
    public function test_like_path_count_matches_paginate_total_and_get_count(): void
    {
        $make = fn () => User::search('john')->using('like');

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }

    public function test_extended_path_count_matches_paginate_total_and_get_count(): void
    {
        $make = fn () => User::search("'john")->extended();

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }

    /**
     * query.max_term_length used to be applied inside executeSearch() only, so count(),
     * paginate() and getBindings() bound the untruncated 400-character term.
     */
    public function test_max_term_length_caps_the_term_outside_get_too(): void
    {
        $make = fn () => User::search(str_repeat('a', 400))->using('like');

        $maxLength = (int) config('fuzzy-search.query.max_term_length', 128);
        $lengths   = array_map('strlen', array_filter($make()->getBindings(), 'is_string'));

        $this->assertLessThanOrEqual($maxLength + 2, max($lengths), 'term plus the two LIKE wildcards');
        $this->assertSame($make()->get()->count(), $make()->count());
    }

    public function test_bm25_path_count_matches_paginate_total_and_get_count(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $make = fn () => User::search('john')->useInvertedIndex();

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }

    /**
     * M8: paginateIndexed() clamped perPage to a hard-coded 100 while the LIKE path did not, so
     * the same paginate(200) call returned a 200-row page on LIKE and a 100-row page with
     * useInvertedIndex(). Both paths now clamp at max_candidates and nowhere else.
     */
    public function test_paginate_per_page_is_the_same_on_the_like_and_bm25_paths(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $this->assertSame(200, User::search('john')->paginate(200)->perPage());
        $this->assertSame(200, User::search('john')->useInvertedIndex()->paginate(200)->perPage());
    }

    public function test_paginate_per_page_is_capped_at_max_candidates_on_both_paths(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        config(['fuzzy-search.max_candidates' => 50]);

        $this->assertSame(50, User::search('john')->paginate(200)->perPage());
        $this->assertSame(50, User::search('john')->useInvertedIndex()->paginate(200)->perPage());
    }

    public function test_a_per_page_below_one_is_a_one_row_page(): void
    {
        // The clamp's lower bound: paginate(0) used to reach LengthAwarePaginator as 0 and
        // throw DivisionByZeroError when it computed lastPage().
        $this->assertSame([1, 1], [
            User::search('john')->paginate(0)->perPage(),
            User::search('john')->paginate(-5)->perPage(),
        ]);
    }

    /**
     * N6: simplePaginate($request->per_page) hydrated up to perPage + 1 models on the index path
     * — bounded only by max_postings_per_term. It clamps like paginate() now.
     */
    public function test_simple_paginate_per_page_is_capped_at_max_candidates_on_both_paths(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        config(['fuzzy-search.max_candidates' => 2]);

        $like = User::search('john')->simplePaginate(5000);
        $bm25 = User::search('john')->useInvertedIndex()->simplePaginate(5000);

        $this->assertCount(2, $bm25->items(), 'the index path matches more than two users');
        $this->assertLessThanOrEqual(2, count($like->items()));
        $this->assertSame([2, 2], [$like->perPage(), $bm25->perPage()]);
    }

    public function test_simple_paginate_per_page_below_one_is_a_one_row_page(): void
    {
        $page = User::search('john')->simplePaginate(0);

        $this->assertSame(1, $page->perPage());
        $this->assertCount(1, $page->items());
    }

    public function test_bm25_path_with_filter_count_matches_paginate_total_and_get_count(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $make = fn () => User::search('john')->useInvertedIndex()->filter('email', 'like', '%@example.com');

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }
}
