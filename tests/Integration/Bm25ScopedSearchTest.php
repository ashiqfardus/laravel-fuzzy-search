<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/**
 * The BM25 path took the top (limit + offset) × 2 ids from the whole index and only then
 * applied the query's constraints. A selective filter (published, in stock, a category)
 * left the page short or empty while matches existed further down the ranking, and
 * paginate() reported the unconstrained total. Filters added with filter() were ignored
 * on this path altogether.
 *
 * Fixture: 30 rows whose name is "widget" repeated k times (k = 1..30), so BM25 ranks
 * them strictly by k, highest first. Rows k = 1..5 — the five *lowest* ranked — carry
 * the @keep.com email that the constraints select.
 */
class Bm25ScopedSearchTest extends TestCase
{
    private const TOTAL = 30;
    private const KEEP  = [1, 2, 3, 4, 5];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->table('users')->delete();

        $rows = [];
        for ($k = 1; $k <= self::TOTAL; $k++) {
            $rows[] = [
                'name'       => trim(str_repeat('widget ', $k)),
                'email'      => "k{$k}@" . (in_array($k, self::KEEP, true) ? 'keep.com' : 'other.com'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->app['db']->table('users')->insert($rows);

        (new IndexManager(new WhitespaceTokenizer(), new NullStemmer()))
            ->indexBatch(ScopedBm25User::all());
    }

    /** @return int[] the k values of the results, in order */
    private function ks(iterable $results): array
    {
        $ks = [];
        foreach ($results as $row) {
            $ks[] = (int) substr($row->email, 1, strpos($row->email, '@') - 1);
        }
        return $ks;
    }

    private function keepFilter(): SearchBuilder
    {
        return ScopedBm25User::search('widget')->useInvertedIndex()->filter('email', 'like', '%@keep.com');
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function test_get_honours_filter_and_fills_the_page_from_lower_ranked_matches(): void
    {
        $results = $this->keepFilter()->limit(5)->get();

        $this->assertSame([5, 4, 3, 2, 1], $this->ks($results));
    }

    public function test_get_honours_eloquent_constraints_applied_before_the_search(): void
    {
        $builder = new SearchBuilder(ScopedBm25User::where('email', 'like', '%@keep.com'), app(FuzzySearch::class));

        $results = $builder->search('widget')->searchIn(['name'])->useInvertedIndex()->limit(5)->get();

        $this->assertSame([5, 4, 3, 2, 1], $this->ks($results));
    }

    public function test_get_walks_the_ranked_list_with_offset_and_limit_under_constraints(): void
    {
        $results = $this->keepFilter()->skip(2)->take(2)->get();

        $this->assertSame([3, 2], $this->ks($results));
    }

    public function test_get_returns_nothing_when_no_ranked_row_satisfies_the_constraints(): void
    {
        $results = ScopedBm25User::search('widget')->useInvertedIndex()->filter('email', 'nobody@nowhere.com')->get();

        $this->assertCount(0, $results);
    }

    public function test_get_without_constraints_keeps_rank_order_and_limit(): void
    {
        $results = ScopedBm25User::search('widget')->useInvertedIndex()->limit(4)->get();

        $this->assertSame([30, 29, 28, 27], $this->ks($results));
    }

    public function test_candidate_chunk_size_does_not_change_results(): void
    {
        config(['fuzzy-search.bm25.candidate_chunk' => 2]);

        $this->assertSame([5, 4, 3, 2, 1], $this->ks($this->keepFilter()->limit(5)->get()));
        $this->assertSame([30, 29, 28], $this->ks(ScopedBm25User::search('widget')->useInvertedIndex()->limit(3)->get()));
    }

    public function test_scores_are_normalised_against_the_best_row_the_query_can_see(): void
    {
        [$best, $next] = $this->keepFilter()->limit(2)->get()->all();
        $corpusBest    = ScopedBm25User::search('widget')->useInvertedIndex()->limit(1)->get()->first();

        // k = 5 is not the best match in the corpus (k = 30 is), but it is the best the filter
        // lets through: a row the query hides does not set the scale.
        $this->assertLessThan($corpusBest->_raw_score, $best->_raw_score);
        $this->assertSame(1.0, $best->_score);
        $this->assertLessThan(1.0, $next->_score);

        // The same scale on every page.
        $this->assertSame($next->_score, $this->keepFilter()->paginate(1, 'page', 2)->items()[0]->_score);
    }

    // -------------------------------------------------------------------------
    // paginate()
    // -------------------------------------------------------------------------

    public function test_paginate_total_and_items_reflect_the_constrained_query(): void
    {
        $page1 = $this->keepFilter()->paginate(3, 'page', 1);
        $page2 = $this->keepFilter()->paginate(3, 'page', 2);

        $this->assertSame(5, $page1->total());
        $this->assertSame([5, 4, 3], $this->ks($page1->items()));
        $this->assertSame([2, 1], $this->ks($page2->items()));
    }

    public function test_paginate_total_counts_eloquent_constraints_applied_before_the_search(): void
    {
        $builder = new SearchBuilder(ScopedBm25User::where('email', 'like', '%@keep.com'), app(FuzzySearch::class));

        $page = $builder->search('widget')->searchIn(['name'])->useInvertedIndex()->paginate(10);

        $this->assertSame(5, $page->total());
        $this->assertSame([5, 4, 3, 2, 1], $this->ks($page->items()));
    }

    public function test_unconstrained_paginate_keeps_the_full_total_and_rank_order(): void
    {
        $page2 = ScopedBm25User::search('widget')->useInvertedIndex()->paginate(5, 'page', 2);

        $this->assertSame(self::TOTAL, $page2->total());
        $this->assertSame([25, 24, 23, 22, 21], $this->ks($page2->items()));
        // Page-relative normalisation would make the first row of every page 1.0.
        $this->assertLessThan(1.0, $page2->items()[0]->_score);
    }

    public function test_paginate_beyond_the_last_constrained_page_is_empty_with_the_right_total(): void
    {
        $page = $this->keepFilter()->paginate(3, 'page', 3);

        $this->assertSame(5, $page->total());
        $this->assertCount(0, $page->items());
    }

    public function test_an_ungrouped_or_where_does_not_count_rows_outside_the_ranking(): void
    {
        // Satisfies the first arm of the caller's where, but does not match the search.
        $this->app['db']->table('users')->insert(['name' => 'gadget', 'email' => 'gadget@other.com', 'created_at' => now(), 'updated_at' => now()]);

        $builder = fn () => (new SearchBuilder(ScopedBm25User::where('email', 'gadget@other.com')->orWhere('email', 'like', '%@keep.com'), app(FuzzySearch::class)))
            ->search('widget')->searchIn(['name'])->useInvertedIndex();

        $this->assertSame([5, 4, 3, 2, 1], $this->ks($builder()->get()));
        $this->assertSame(5, $builder()->count(), 'the ranked-id whereIn bound tighter than the orWhere');
        $this->assertSame(5, $builder()->paginate(10)->total());
    }

    public function test_a_plain_query_builder_keeps_its_where_on_the_index_path(): void
    {
        $builder = fn () => (new SearchBuilder($this->app['db']->table('users')->where('email', 'like', '%@keep.com'), app(FuzzySearch::class)))
            ->search('widget')->searchIn(['name'])->useInvertedIndex(ScopedBm25User::class);

        $this->assertSame([5, 4, 3, 2, 1], $this->ks($builder()->limit(5)->get()));
        $this->assertSame(5, $builder()->paginate(10)->total());
    }
}

class ScopedBm25User extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns' => ['name' => 1],
    ];
}
