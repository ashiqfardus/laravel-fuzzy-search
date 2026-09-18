<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../SameNameModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Illuminate\Database\Eloquent\Model;

/**
 * Federated Search Tests
 * 
 * Tests for searching across multiple models simultaneously.
 */
class FederatedSearchTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Basic Federation Tests
    |--------------------------------------------------------------------------
    */

    public function test_federated_search_returns_collection(): void
    {
        $results = FederatedSearch::across([User::class, Product::class])
            ->search('john')
            ->searchIn(['name', 'title', 'email'])
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_federated_search_finds_users(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->limit(20)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_federated_search_finds_products(): void
    {
        $results = FederatedSearch::across([Product::class])
            ->search('phone')
            ->searchIn(['title', 'description'])
            ->using('like')
            ->limit(20)
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_federated_search_includes_model_type(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
        
        $first = $results->first();
        $this->assertTrue(
            isset($first->_model_type),
            '_model_type must be set on every FederatedSearch result'
        );
        $this->assertNotEmpty($first->_model_type);
    }

    /*
    |--------------------------------------------------------------------------
    | Fluent API Tests
    |--------------------------------------------------------------------------
    */

    public function test_using_method_is_chainable(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name'])
            ->using('like')
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_typo_tolerance_is_chainable(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('jonh')
            ->searchIn(['name'])
            ->typoTolerance(2)
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_limit_is_applied(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->limit(2)
            ->get();

        $this->assertLessThanOrEqual(2, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Grouped Results Tests
    |--------------------------------------------------------------------------
    */

    public function test_get_grouped_returns_collection(): void
    {
        $grouped = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->getGrouped();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $grouped);
    }

    public function test_get_counts_returns_array(): void
    {
        $counts = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->getCounts();

        $this->assertIsArray($counts);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Cases
    |--------------------------------------------------------------------------
    */

    public function test_handles_nonexistent_model_gracefully(): void
    {
        $results = FederatedSearch::across([User::class, 'NonExistentModel'])
            ->search('john')
            ->searchIn(['name'])
            ->using('like')
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_empty_search_throws_exception_by_default(): void
    {
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException::class);

        FederatedSearch::across([User::class])
            ->search('')
            ->searchIn(['name'])
            ->using('like')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Search In Columns Tests
    |--------------------------------------------------------------------------
    */

    public function test_search_in_weighted_columns(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->using('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Normalized Score Tests
    |--------------------------------------------------------------------------
    */

    public function test_federated_results_have_normalized_scores(): void
    {
        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name', 'email'])
            ->using('like')
            ->get();

        if ($results->isEmpty()) {
            $this->markTestSkipped('No federated results to test normalization on');
            return;
        }

        foreach ($results as $row) {
            $score = $row->_score ?? 0;
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | searchIn() Honoured On Searchable Models (B4)
    |--------------------------------------------------------------------------
    */

    public function test_search_in_columns_are_applied_to_searchable_models(): void
    {
        // Product uses the Searchable trait with columns title/description. Restrict the
        // federated search to "description" only: "smartphone" appears only in a description.
        $results = FederatedSearch::across([Product::class])
            ->search('smartphone')
            ->searchIn(['description' => 5])
            ->using('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());

        // Same term restricted to "title" must find nothing — proving searchIn() is honoured.
        $none = FederatedSearch::across([Product::class])
            ->search('smartphone')
            ->searchIn(['title' => 5])
            ->using('like')
            ->get();

        $this->assertCount(0, $none);
    }

    public function test_search_in_ignores_columns_a_model_does_not_have(): void
    {
        // users has no "title", products has no "name": no SQL error, both still searchable.
        $results = FederatedSearch::across([User::class, Product::class])
            ->search('john')
            ->searchIn(['name' => 10, 'title' => 10])
            ->using('like')
            ->get();

        $this->assertContains('User', $results->pluck('_model_type')->unique()->all());
    }

    public function test_non_searchable_model_with_no_matching_columns_is_skipped_not_sql_errored(): void
    {
        // PlainProduct is not Searchable/Fuzzy; its table (products) has no "name" column.
        // Requesting only "name" must skip PlainProduct entirely rather than run
        // whereFuzzyMultiple() against a column that does not exist (a real SQL error on
        // MySQL/PostgreSQL; SQLite silently treats an unresolved quoted identifier as a
        // string literal instead of raising an error, so this only fails pre-fix on
        // MySQL/PostgreSQL — verified manually against both during Task 8 review).
        $results = FederatedSearch::across([PlainProduct::class, User::class])
            ->search('john')
            ->searchIn(['name' => 10])
            ->using('like')
            ->get();

        $this->assertEquals(['User'], $results->pluck('_model_type')->unique()->all());
    }

    public function test_non_searchable_model_with_matching_column_is_still_searched(): void
    {
        // "description" exists on products: the non-Searchable path still works normally.
        $results = FederatedSearch::across([PlainProduct::class])
            ->search('smartphone')
            ->searchIn(['description' => 5])
            ->using('like')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Pagination, Per-Model Limits, Model Ordering
    |--------------------------------------------------------------------------
    */

    public function test_limit_per_model_caps_each_model_before_merging(): void
    {
        $results = FederatedSearch::across([User::class, Product::class])
            ->search('on')
            ->searchIn(['name', 'title'])
            ->using('like')
            ->limitPerModel(1)
            ->limit(10)
            ->get();

        $this->assertLessThanOrEqual(1, $results->where('_model_type', 'User')->count());
        $this->assertLessThanOrEqual(1, $results->where('_model_type', 'Product')->count());
    }

    public function test_paginate_returns_length_aware_paginator_with_real_total(): void
    {
        $federated = FederatedSearch::across([User::class, Product::class])
            ->search('on')
            ->searchIn(['name', 'title'])
            ->using('like');

        $all   = $federated->limit(100)->get();
        $page1 = $federated->paginate(2, 'page', 1);
        $page2 = $federated->paginate(2, 'page', 2);

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $page1);
        $this->assertSame($all->count(), $page1->total());
        $this->assertCount(2, $page1->items());
        $this->assertNotEquals(
            collect($page1->items())->map(fn ($r) => $r->_model_type . ':' . $r->getKey())->all(),
            collect($page2->items())->map(fn ($r) => $r->_model_type . ':' . $r->getKey())->all()
        );
    }

    public function test_simple_paginate_detects_next_page(): void
    {
        $page = FederatedSearch::across([User::class, Product::class])
            ->search('on')->searchIn(['name', 'title'])->using('like')
            ->simplePaginate(2, 'page', 1);

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\Paginator::class, $page);
        $this->assertTrue($page->hasMorePages());
        $this->assertCount(2, $page->items());
    }

    public function test_order_by_model_breaks_score_ties(): void
    {
        $productsFirst = FederatedSearch::across([User::class, Product::class])
            ->search('on')->searchIn(['name', 'title'])->using('like')
            ->withRelevance(false)
            ->orderByModel([Product::class, User::class])
            ->get();

        $this->assertSame('Product', $productsFirst->first()->_model_type);
    }

    /*
    |--------------------------------------------------------------------------
    | Reachable Totals, Stable Cross-Page Ordering, across() Order (Ruling P27)
    |--------------------------------------------------------------------------
    */

    public function test_limit_per_model_caps_the_reachable_total_for_paginate(): void
    {
        // Two User matches ("Jon Snow", "Bob Johnson") and one Product match ("iPhone 15
        // Pro"). limitPerModel(1) means only 1 row per model is ever reachable, so total()
        // must reflect 2 (not the uncapped 3), lastPage() must be 2, and page 3 must be empty.
        $federated = FederatedSearch::across([User::class, Product::class])
            ->search('on')
            ->searchIn(['name', 'title'])
            ->using('like')
            ->limitPerModel(1);

        $page1 = $federated->paginate(1, 'page', 1);

        $this->assertSame(2, $page1->total());
        $this->assertSame(2, $page1->lastPage());

        $page3 = $federated->paginate(1, 'page', 3);

        $this->assertCount(0, $page3->items());
    }

    public function test_get_counts_reports_matches_not_the_page(): void
    {
        // "Jon Snow" and "Bob Johnson" both match; limit(1) returns one row. getCounts() used
        // to group the returned page, so it reported 1 where 2 rows match.
        $counts = FederatedSearch::across([User::class, Product::class])
            ->search('on')
            ->searchIn(['name', 'title'])
            ->using('like')
            ->limit(1)
            ->getCounts();

        $this->assertSame(2, $counts['User']);
        $this->assertSame(1, $counts['Product']);
    }

    public function test_get_counts_sums_to_the_paginate_total(): void
    {
        $federated = FederatedSearch::across([User::class, Product::class])
            ->search('on')
            ->searchIn(['name', 'title'])
            ->using('like')
            ->limitPerModel(1);

        $this->assertSame([1, 1], array_values($federated->getCounts()));
        $this->assertSame(array_sum($federated->getCounts()), $federated->paginate(1, 'page', 1)->total());
    }

    public function test_max_candidates_caps_a_models_share_of_the_total(): void
    {
        // A model can never contribute more rows than max_candidates: the LIKE path ranks that
        // many candidates and slices the page out of them. total() must not promise more.
        config(['fuzzy-search.max_candidates' => 1]);

        $federated = FederatedSearch::across([User::class, Product::class])
            ->search('on')
            ->searchIn(['name', 'title'])
            ->using('like');

        $page = $federated->paginate(1, 'page', 1);

        $this->assertSame(2, $page->total(), 'User matches twice but only one row is reachable');
        $this->assertSame(['User' => 1, 'Product' => 1], $federated->getCounts());
        $this->assertCount(0, $federated->paginate(1, 'page', 3)->items());
    }

    public function test_models_sharing_a_class_basename_are_counted_separately(): void
    {
        // Two Searchable "User" models in different namespaces, both on the users table: the
        // per-model counts must be keyed by class, or one overwrites the other and total()
        // promises half the rows the search returns. getCounts() still reports basenames, so
        // there the two must be added together.
        $federated = fn () => FederatedSearch::across([
            \Ashiqfardus\LaravelFuzzySearch\Tests\SameNameA\User::class,
            \Ashiqfardus\LaravelFuzzySearch\Tests\SameNameB\User::class,
        ])->search('john')->searchIn(['name'])->using('like');

        $rows = $federated()->limit(100)->get()->count();

        $this->assertGreaterThan(0, $rows);
        $this->assertSame($rows, $federated()->paginate(2, 'page', 1)->total());
        $this->assertSame(['User' => $rows], $federated()->getCounts());
    }

    public function test_paginate_is_stable_and_gapless_across_pages(): void
    {
        // Walking every paginate() page must reproduce exactly the same order as a single
        // ->limit(100)->get() call: no row skipped, none duplicated, regardless of ties.
        $federated = FederatedSearch::across([User::class, Product::class])
            ->search('on')
            ->searchIn(['name', 'title'])
            ->using('like');

        $expected = $federated->limit(100)->get()
            ->map(fn ($r) => $r->_model_type . ':' . $r->getKey())
            ->all();

        $walked = [];
        $page = 1;
        $lastPage = 1;

        do {
            $paginator = $federated->paginate(2, 'page', $page);
            $lastPage = $paginator->lastPage();

            foreach ($paginator->items() as $item) {
                $walked[] = $item->_model_type . ':' . $item->getKey();
            }

            $page++;
        } while ($page <= $lastPage);

        $this->assertSame($expected, $walked);
    }

    public function test_get_defaults_tie_break_to_across_order_without_order_by_model(): void
    {
        // withRelevance(false) with no orderByModel(): the tie-break must default to the
        // across() order (User first, since User is listed first here) — not alphabetical
        // by _model_type ("Product" < "User"), which is what the pre-fix PHP_INT_MAX-for-both
        // fallback produced regardless of across() order. Deliberately listing User before
        // Product (the opposite of alphabetical) makes the two behaviours disagree.
        $first = FederatedSearch::across([User::class, Product::class])
            ->search('on')->searchIn(['name', 'title'])->using('like')
            ->withRelevance(false)
            ->get()
            ->first();

        $this->assertSame('User', $first->_model_type);
    }

    /*
    |--------------------------------------------------------------------------
    | searchIn() Keeps the Model's $searchable Extras (I3)
    |--------------------------------------------------------------------------
    */

    public function test_search_in_on_a_searchable_model_keeps_its_configured_synonyms(): void
    {
        // SynonymUserFixture configures 'jon' => ['john'] as a synonym. Restricting the
        // federated search to searchIn(['name' => 1]) takes the bare-SearchBuilder path in
        // queryFor(); before the fix that path dropped the model's synonyms entirely, so
        // searching 'jon' would never expand to 'john' and "John Doe" would be missing.
        $results = FederatedSearch::across([SynonymUserFixture::class])
            ->search('jon')
            ->searchIn(['name' => 1])
            ->using('like')
            ->get();

        $this->assertContains('John Doe', $results->pluck('name')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Relation Columns (not supported yet)
    |--------------------------------------------------------------------------
    */

    public function test_relation_columns_are_ignored_by_federated_search_without_error(): void
    {
        require_once __DIR__ . '/../RelationModels.php';

        $results = FederatedSearch::across([User::class])
            ->search('john')
            ->searchIn(['name' => 10, 'author.name' => 5])
            ->using('like')
            ->get();

        $this->assertTrue($results->contains('name', 'John Doe'));
    }
}

/**
 * Plain model over the "products" table with no Searchable/Fuzzy trait, used to exercise
 * FederatedSearch's non-Searchable fallback branch.
 */
class PlainProduct extends Model
{
    protected $table = 'products';
    protected $guarded = [];
}

/**
 * Named Searchable model over "users" configuring a synonym, used to prove
 * FederatedSearch::queryFor()'s searchIn()-narrowed path still applies the model's own
 * $searchable extras (stop words, synonyms, accent handling) instead of dropping them (I3).
 */
class SynonymUserFixture extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'  => ['name' => 1],
        'synonyms' => ['jon' => ['john']],
    ];
}
