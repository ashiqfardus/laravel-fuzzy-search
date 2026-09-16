<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;

/**
 * Review finding C1 / I4: no model in the suite carried an Eloquent global scope
 * (SoftDeletes, a tenant scope, ...), so nothing caught paginate()'s total() computing
 * $this->query->getQuery()->getCountForPagination() in paginateRanked() — getQuery() skips
 * global scopes while the page items (get()) and count() (toBase()) both apply them. "Johnny
 * Bravo" is soft-deleted here (still present in the "users" table row, just scoped out) so
 * every path below must agree: get()->count() === count() === paginate()->total().
 *
 * Reviewer's own scratch repro: LIKE get()=2, count()=2, paginate()->total()=3 (item count
 * right, total wrong); same 3-vs-2 split on the extended path.
 */
class GlobalScopeTotalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // "Johnny Bravo" matches 'john' (LIKE %john%) same as "John Doe" and "Bob Johnson";
        // soft-deleting it is what a getQuery()-based total misses.
        $this->app['db']->table('users')->where('name', 'Johnny Bravo')->update(['deleted_at' => now()]);
    }

    public function test_like_path_totals_agree_under_a_global_scope(): void
    {
        // Separate builders — a SearchBuilder is single-use (see RankThenLimitTest).
        $make = fn () => SoftDeletedUser::search('john')->using('like');

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }

    public function test_extended_path_totals_agree_under_a_global_scope(): void
    {
        $make = fn () => SoftDeletedUser::search("'john")->extended();

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
    }

    public function test_bm25_path_totals_agree_under_a_global_scope_and_excludes_trashed(): void
    {
        // Index the trashed row too. BM25 matches whole tokens ("bravo" only appears in
        // "Johnny Bravo"), so unlike the LIKE/extended cases above, this is a real check that
        // the constrained BM25 query (which applies the SoftDeletes global scope) excludes the
        // trashed row entirely — not a parity check that would hold either way.
        app(IndexManager::class)->indexBatch(SoftDeletedUser::withTrashed()->get());

        $make = fn () => SoftDeletedUser::search('bravo')->useInvertedIndex();

        $this->assertSame($make()->get()->count(), $make()->count());
        $this->assertSame($make()->count(), $make()->paginate(2)->total());
        $this->assertSame(0, $make()->count(), 'the only BM25 match for "bravo" is soft-deleted');
    }

    public function test_federated_paginate_total_agrees_with_get_count_under_a_global_scope(): void
    {
        $federated = FederatedSearch::across([SoftDeletedUser::class])->search('john')->using('like');

        $this->assertSame($federated->get()->count(), $federated->paginate(2)->total());
    }
}
