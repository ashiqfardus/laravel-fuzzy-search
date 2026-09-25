<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../SameNameModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\LikeUser;
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

        // "john" is in John Doe's and Johnny Bravo's name and email, and in Bob Johnson's name.
        $this->assertEqualsCanonicalizing(['Bob Johnson', 'John Doe', 'Johnny Bravo'], $results->pluck('name')->all());

        $scores = $results->pluck('_score')->all();
        $this->assertSame(1.0, max($scores)); // the best row sets the scale
        foreach ($scores as $score) {
            $this->assertGreaterThan(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
        $this->assertLessThan(1.0, min($scores)); // Bob Johnson matches on his name only
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
    | Each Model Keeps Its Own Algorithm and Typo Tolerance
    |--------------------------------------------------------------------------
    */

    /** String bindings of the queries $call runs against $table. */
    private function stringBindingsOn(string $table, \Closure $call): array
    {
        $bindings = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use ($table, &$bindings) {
            if (preg_match('/\b' . $table . '\b/', $query->sql)) {
                array_push($bindings, ...array_filter($query->bindings, 'is_string'));
            }
        });

        $call();

        return array_values(array_unique($bindings));
    }

    public function test_each_model_is_searched_with_its_own_configured_algorithm(): void
    {
        // LikeUser: $searchable algorithm 'like'. Product: 'fuzzy'. The typo "jonh" is on purpose:
        // LIKE's only patterns are the term itself, fuzzy adds typo variants.
        foreach ([[], ['name' => 10, 'title' => 10]] as $searchIn) {
            $federated = FederatedSearch::across([LikeUser::class, Product::class])->search('jonh');
            if ($searchIn !== []) {
                $federated->searchIn($searchIn);
            }

            $users    = $this->stringBindingsOn('users', fn () => $federated->get());
            $products = $this->stringBindingsOn('products', fn () => $federated->get());

            $label = $searchIn === [] ? 'model columns' : 'searchIn()';
            $this->assertNotEmpty($users, $label);
            $this->assertSame([], array_diff($users, ['jonh', 'jonh%', '%jonh%']), "{$label}: LikeUser was not searched with LIKE");
            $this->assertNotEmpty(array_diff($products, ['jonh', 'jonh%', '%jonh%']), "{$label}: Product was not searched with fuzzy");

            $this->assertSame([], $federated->get()->where('_model_type', 'LikeUser')->all(), "{$label}: LIKE matched a typo");
        }
    }

    public function test_each_model_keeps_its_own_typo_tolerance(): void
    {
        // TypoZeroUser: fuzzy with typo_tolerance 0, so "jonh" matches nothing. User: fuzzy with the
        // default tolerance, so it finds John Doe.
        foreach ([[], ['name' => 10]] as $searchIn) {
            $federated = FederatedSearch::across([TypoZeroUser::class, User::class])->search('jonh');
            if ($searchIn !== []) {
                $federated->searchIn($searchIn);
            }

            $types = $federated->limit(50)->get()->pluck('_model_type')->unique()->values()->all();
            $this->assertSame(['User'], $types, $searchIn === [] ? 'model columns' : 'searchIn()');
            $this->assertSame(0, $federated->getCounts()['TypoZeroUser']);
        }
    }

    public function test_an_explicit_algorithm_or_typo_tolerance_still_overrides_every_model(): void
    {
        $withAlgorithm = FederatedSearch::across([LikeUser::class])->search('jonh')->using('fuzzy')->limit(50)->get();
        $this->assertContains('John Doe', $withAlgorithm->pluck('name')->all());

        $withTolerance = FederatedSearch::across([TypoZeroUser::class])->search('jonh')->typoTolerance(2)->limit(50)->get();
        $this->assertContains('John Doe', $withTolerance->pluck('name')->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Models Without the Searchable Trait Get the Same Limits
    |--------------------------------------------------------------------------
    */

    public function test_a_model_without_the_trait_is_capped_at_max_candidates(): void
    {
        // "jo" is in John Doe, Jon Snow, Johnny Bravo and Bob Johnson: four matches, two reachable.
        config(['fuzzy-search.max_candidates' => 2]);

        $federated = fn () => FederatedSearch::across([PlainUser::class])->search('jo')->searchIn(['name'])->using('like');

        $this->assertCount(2, $federated()->limit(10)->get());
        $this->assertSame(['PlainUser' => 2], $federated()->getCounts());

        $page = $federated()->paginate(1, 'page', 1);
        $this->assertSame(2, $page->total());
        $this->assertCount(0, $federated()->paginate(1, 'page', 3)->items());
        $this->assertCount(0, $federated()->simplePaginate(1, 'page', 3)->items());
        $this->assertFalse($federated()->simplePaginate(1, 'page', 2)->hasMorePages());
    }

    public function test_a_model_without_the_trait_and_without_a_searchable_column_matches_nothing(): void
    {
        // PlainTag declares no columns and its table has neither of the guessed "name"/"title".
        $schema = $this->app['db']->connection()->getSchemaBuilder();
        $schema->dropIfExists('federated_tags');
        $schema->create('federated_tags', function ($table) {
            $table->id();
            $table->string('label');
        });
        $this->app['db']->table('federated_tags')->insert([['label' => 'name tag'], ['label' => 'title tag']]);

        try {
            // The term is the guessed column's own name: were it written into the SQL, SQLite would
            // read the unknown quoted identifier as a string and match every row, the others throw.
            foreach (['name', 'title'] as $term) {
                $federated = fn () => FederatedSearch::across([PlainTag::class, User::class])->search($term);

                $this->assertSame([], $federated()->get()->where('_model_type', 'PlainTag')->all());
                $this->assertArrayNotHasKey('PlainTag', $federated()->getCounts());
                $this->assertSame($federated()->get()->count(), $federated()->paginate(15, 'page', 1)->total());
            }
        } finally {
            $schema->dropIfExists('federated_tags');
        }
    }

    public function test_a_model_without_the_trait_honours_min_search_length(): void
    {
        $federated = fn () => FederatedSearch::across([PlainUser::class])->search('j')->searchIn(['name'])->using('like');

        $this->assertCount(0, $federated()->get());
        $this->assertSame(['PlainUser' => 0], $federated()->getCounts());
        $this->assertSame(0, $federated()->paginate(15, 'page', 1)->total());
    }

    /*
    |--------------------------------------------------------------------------
    | perPage and ?page Are Clamped
    |--------------------------------------------------------------------------
    */

    public function test_paginate_clamps_per_page_to_at_least_one_and_at_most_max_candidates(): void
    {
        $federated = fn () => FederatedSearch::across([User::class, Product::class])
            ->search('on')->searchIn(['name', 'title'])->using('like');

        foreach ([0, -5] as $perPage) {
            $page = $federated()->paginate($perPage, 'page', 1);
            $this->assertSame(1, $page->perPage(), "paginate({$perPage})");
            $this->assertCount(1, $page->items(), "paginate({$perPage})");
            $this->assertSame($page->total(), $page->lastPage(), "paginate({$perPage})");

            $simple = $federated()->simplePaginate($perPage, 'page', 1);
            $this->assertSame(1, $simple->perPage(), "simplePaginate({$perPage})");
            $this->assertCount(1, $simple->items(), "simplePaginate({$perPage})");
        }

        config(['fuzzy-search.max_candidates' => 2]);
        $this->assertSame(2, $federated()->paginate(5000, 'page', 1)->perPage());
        $this->assertSame(2, $federated()->simplePaginate(5000, 'page', 1)->perPage());
    }

    public function test_paginate_reads_a_non_numeric_or_non_positive_page_as_page_one(): void
    {
        $federated = fn () => FederatedSearch::across([User::class, Product::class])
            ->search('on')->searchIn(['name', 'title'])->using('like');

        $first = collect($federated()->paginate(1, 'page', 1)->items())->map(fn ($r) => $r->_model_type . ':' . $r->getKey())->all();

        foreach (['abc', '0', '-3', ['1'], '1abc'] as $value) {
            $this->app['request']->query->set('page', $value);

            $page = $federated()->paginate(1);
            $this->assertSame(1, $page->currentPage(), 'paginate() ?page=' . json_encode($value));
            $this->assertSame($first, collect($page->items())->map(fn ($r) => $r->_model_type . ':' . $r->getKey())->all());

            $this->assertSame(1, $federated()->simplePaginate(1)->currentPage(), 'simplePaginate() ?page=' . json_encode($value));
        }
    }

    public function test_a_page_too_large_for_an_offset_is_capped_instead_of_overflowing(): void
    {
        // ($page - 1) * $perPage overflowed into a float for a page near PHP_INT_MAX, and
        // fetchRanked(int) threw a TypeError: a 500 for ?page=9223372036854775807.
        $federated = fn () => FederatedSearch::across([User::class, Product::class])
            ->search('on')->searchIn(['name', 'title'])->using('like');

        foreach ([(string) PHP_INT_MAX, '99999999999999999999', (string) intdiv(PHP_INT_MAX, 15)] as $value) {
            $this->app['request']->query->set('page', $value);

            $page = $federated()->paginate(15);
            $this->assertSame([], $page->items(), "paginate() ?page={$value}");
            $this->assertGreaterThan(1, $page->currentPage());
            $this->assertGreaterThan(0, $page->total());

            $this->assertSame([], $federated()->simplePaginate(15)->items(), "simplePaginate() ?page={$value}");
        }

        $this->assertSame([], $federated()->paginate(15, 'page', PHP_INT_MAX)->items());
        $this->assertSame([], $federated()->simplePaginate(1, 'page', PHP_INT_MAX)->items());
    }

    /*
    |--------------------------------------------------------------------------
    | Declared Columns of Models Without the Searchable Trait
    |--------------------------------------------------------------------------
    */

    public function test_a_scout_only_model_is_searched_without_running_scouts_searchable(): void
    {
        // $model->searchable, read from outside the model, reached Eloquent's __isset(), which took
        // Scout's searchable() method for a relation: it ran it on a blank model (an index write),
        // then threw LogicException.
        ScoutOnlyUser::$searchableCalls = 0;

        $results = FederatedSearch::across([ScoutOnlyUser::class])->search('john')->using('like')->get();

        $this->assertSame(0, ScoutOnlyUser::$searchableCalls, "Scout's searchable() ran");
        $this->assertEqualsCanonicalizing(['John Doe', 'Johnny Bravo', 'Bob Johnson'], $results->pluck('name')->all());
        $this->assertSame(['ScoutOnlyUser' => 3], FederatedSearch::across([ScoutOnlyUser::class])->search('john')->using('like')->getCounts());
        $this->assertSame(0, ScoutOnlyUser::$searchableCalls, "Scout's searchable() ran");
    }

    public function test_a_fuzzy_trait_model_is_searched_on_its_declared_columns(): void
    {
        // "smartphone" is only in a description. FuzzyProduct declares protected $fuzzySearchable =
        // ['description'], which isset() from outside the model could not see: the search ran on
        // the guessed "title" and found nothing.
        $results = FederatedSearch::across([FuzzyProduct::class])->search('smartphone')->using('like')->get();

        $this->assertSame(['iPhone 15 Pro'], $results->pluck('title')->all());
        $this->assertSame(['FuzzyProduct' => 1], FederatedSearch::across([FuzzyProduct::class])->search('smartphone')->using('like')->getCounts());
    }

    public function test_a_plain_model_is_searched_on_its_protected_searchable_columns(): void
    {
        $results = FederatedSearch::across([DeclaredPlainProduct::class])->search('smartphone')->using('like')->get();

        $this->assertSame(['iPhone 15 Pro'], $results->pluck('title')->all());
    }

    public function test_a_fuzzy_trait_model_is_searched_with_its_own_algorithm_unless_using_overrides_it(): void
    {
        // FuzzySoundexUser declares $fuzzyAlgorithm = 'soundex'; the fallback searched it with LIKE.
        // Soundex is native SOUNDEX() on MySQL and MariaDB, and phonetic LIKE patterns elsewhere,
        // among them the consonant skeleton "%jnh%" of "jonh", which no other driver generates.
        $soundex = function (\Closure $call): bool {
            $seen = false;
            \Illuminate\Support\Facades\DB::listen(function ($query) use (&$seen) {
                if (preg_match('/\busers\b/', $query->sql)
                    && (stripos($query->sql, 'soundex(') !== false || in_array('%jnh%', $query->bindings, true))) {
                    $seen = true;
                }
            });
            $call();

            return $seen;
        };

        $own = fn () => FederatedSearch::across([FuzzySoundexUser::class])->search('jonh');

        $this->assertTrue($soundex(fn () => $own()->get()), 'get() did not search with the model\'s soundex');
        $this->assertTrue($soundex(fn () => $own()->getCounts()), 'getCounts() did not search with the model\'s soundex');
        $this->assertContains('Jon Snow', $own()->limit(50)->get()->pluck('name')->all()); // "Jon" sounds like "jonh" everywhere

        $this->assertFalse($soundex(fn () => $own()->using('fuzzy')->get()), 'an explicit using() must override the model\'s algorithm');
        $this->assertFalse($soundex(fn () => $own()->using('fuzzy')->getCounts()));
    }

    public function test_a_fuzzy_trait_model_is_searched_with_its_own_options(): void
    {
        // FuzzyExactUser: fuzzy with $fuzzyOptions max_distance 0, so the typo "jonh" matches nothing,
        // as its own fuzzy() scope finds nothing. typoTolerance() and options() still override it.
        $federated = fn () => FederatedSearch::across([FuzzyExactUser::class])->search('jonh');

        $this->assertCount(0, FuzzyExactUser::query()->fuzzy('jonh')->get(), 'baseline: the model\'s own scope');
        $this->assertCount(0, $federated()->limit(50)->get());
        $this->assertSame(['FuzzyExactUser' => 0], $federated()->getCounts());

        $this->assertNotEmpty($federated()->typoTolerance(2)->limit(50)->get());
        $this->assertNotEmpty($federated()->options(['max_distance' => 2])->limit(50)->get());
    }

    /*
    |--------------------------------------------------------------------------
    | options() Reaches Every Model
    |--------------------------------------------------------------------------
    */

    public function test_options_apply_to_every_model(): void
    {
        // max_distance 0 leaves the fuzzy driver only the typed term, so the typo "jonh" matches
        // nothing; options() used to be stored and never applied.
        foreach ([User::class, PlainUser::class] as $class) {
            $federated = fn () => FederatedSearch::across([$class])->search('jonh')->searchIn(['name'])->using('fuzzy');

            $this->assertNotEmpty($federated()->limit(50)->get(), "{$class}: baseline, the typo matches");
            $this->assertCount(0, $federated()->options(['max_distance' => 0])->limit(50)->get(), "{$class}: options() ignored");
            $this->assertSame(0, array_sum($federated()->options(['max_distance' => 0])->getCounts()), "{$class}: getCounts() ignored options()");

            // typoTolerance() sets the same option, and wins over it.
            $this->assertNotEmpty($federated()->options(['max_distance' => 0])->typoTolerance(2)->limit(50)->get(), "{$class}: typoTolerance() lost to options()");
        }
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

/** Fuzzy with no typo tolerance: a typo never matches on its own configuration. */
class TypoZeroUser extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'        => ['name' => 10],
        'algorithm'      => 'fuzzy',
        'typo_tolerance' => 0,
    ];
}

/** Plain model over "users" with no Searchable/Fuzzy trait (FederatedSearch's fallback branch). */
class PlainUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
}

/** Plain model whose table has neither of the fallback's guessed columns, "name" and "title". */
class PlainTag extends Model
{
    protected $table = 'federated_tags';
    protected $guarded = [];
    public $timestamps = false;
}

/**
 * Only Scout's Searchable, and no $searchable property: `$model->searchable` from outside the
 * model resolves Scout's searchable() method. It is wrapped to count the calls.
 */
class ScoutOnlyUser extends Model
{
    use \Laravel\Scout\Searchable {
        searchable as scoutSearchable;
    }

    public static int $searchableCalls = 0;

    protected $table = 'users';
    protected $guarded = [];

    public function searchable(): void
    {
        static::$searchableCalls++;
        $this->scoutSearchable();
    }
}

/** Only the (deprecated) Fuzzy trait, declaring its columns the way that trait documents. */
class FuzzyProduct extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Fuzzy;

    protected $table = 'products';
    protected $guarded = [];

    protected array $fuzzySearchable = ['description'];
}

/** No trait at all, but a protected $searchable declaration. */
class DeclaredPlainProduct extends Model
{
    protected $table = 'products';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['description' => 5]];
}

/** Only the Fuzzy trait, with its own algorithm. */
class FuzzySoundexUser extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Fuzzy;

    protected $table = 'users';
    protected $guarded = [];

    protected array $fuzzySearchable = ['name'];
    protected string $fuzzyAlgorithm = 'soundex';
}

/** Only the Fuzzy trait: fuzzy, and no typo distance in its own options. */
class FuzzyExactUser extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Fuzzy;

    protected $table = 'users';
    protected $guarded = [];

    protected array $fuzzySearchable = ['name'];
    protected string $fuzzyAlgorithm = 'fuzzy';
    protected array $fuzzyOptions = ['max_distance' => 0];
}

