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

    public function test_bm25_path_count_matches_paginate_total_and_get_count(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $make = fn () => User::search('john')->useInvertedIndex();

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }

    public function test_bm25_path_with_filter_count_matches_paginate_total_and_get_count(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $make = fn () => User::search('john')->useInvertedIndex()->filter('email', 'like', '%@example.com');

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }
}
