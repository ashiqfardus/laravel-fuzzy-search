<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Illuminate\Support\Facades\DB;

// Import shared models
require_once __DIR__ . '/TestModels.php';

/**
 * Eloquent Model Tests with Fuzzy Trait
 */
class EloquentTraitTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Basic Trait Tests
    |--------------------------------------------------------------------------
    */

    public function test_fuzzy_scope_on_model(): void
    {
        $results = User::fuzzy('john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_scope_with_custom_columns(): void
    {
        $results = User::fuzzy('john', ['name'])->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_with_algorithm(): void
    {
        $results = User::fuzzyWith('like', 'john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_levenshtein_scope_accepts_a_max_distance_of_zero(): void
    {
        // 0 is exact containment, not "use the configured distance" (2 as shipped).
        $names = User::fuzzyLevenshtein('jon', ['name'], 0)->pluck('name')->all();

        $this->assertSame(['Jon Snow'], $names);
    }

    public function test_fuzzy_levenshtein_scope(): void
    {
        $results = User::fuzzyLevenshtein('jon', null, 2)->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_soundex_scope(): void
    {
        $results = User::fuzzySoundex('john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_similar_scope(): void
    {
        $results = User::fuzzySimilar('john', null, 50)->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Model Configuration Tests
    |--------------------------------------------------------------------------
    */

    public function test_get_fuzzy_searchable_columns(): void
    {
        $user = new User();
        $columns = $user->getFuzzySearchableColumns();

        $this->assertEquals(['name', 'email'], $columns);
    }

    public function test_get_fuzzy_algorithm(): void
    {
        $user = new User();
        $algorithm = $user->getFuzzyAlgorithm();

        $this->assertEquals('levenshtein', $algorithm);
    }

    public function test_get_fuzzy_options(): void
    {
        $user = new User();
        $options = $user->getFuzzyOptions();

        $this->assertEquals(['max_distance' => 3], $options);
    }

    /*
    |--------------------------------------------------------------------------
    | Eloquent Builder Macro Tests
    |--------------------------------------------------------------------------
    */

    public function test_where_fuzzy_on_eloquent(): void
    {
        $results = User::whereFuzzy('name', 'john', 'like')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_or_where_fuzzy_on_eloquent(): void
    {
        $results = User::whereFuzzy('name', 'john', 'like')
            ->orWhereFuzzy('name', 'alice', 'like')
            ->get();

        $this->assertGreaterThanOrEqual(2, $results->count());
    }

    public function test_where_fuzzy_multiple_on_eloquent(): void
    {
        $results = User::whereFuzzyMultiple(['name', 'email'], 'john', 'like')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_search_on_eloquent(): void
    {
        $results = User::fuzzySearch('name', 'john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_fuzzy_search_multiple_columns_on_eloquent(): void
    {
        $results = User::fuzzySearch(['name', 'email'], 'john')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | searchFuzzy() scope (Searchable)
    |--------------------------------------------------------------------------
    */

    public function test_search_fuzzy_scope_uses_the_configured_column_weights(): void
    {
        $builder = User::searchFuzzy('john');

        $this->assertSame(['name' => 10, 'email' => 5], $builder->getDebugInfo()['column_weights']);
        $this->assertSame('John Doe', $builder->get()->first()->name);
    }

    public function test_search_fuzzy_scope_weighs_caller_columns_equally(): void
    {
        $this->assertSame(['name' => 1], User::searchFuzzy('john', ['name'])->getDebugInfo()['column_weights']);
    }

    public function test_search_fuzzy_scope_reads_list_form_columns(): void
    {
        $builder = ListColumnsUser::searchFuzzy('john');

        $this->assertSame(['name' => 1, 'email' => 1], $builder->getDebugInfo()['column_weights']);
        $this->assertGreaterThan(0, $builder->get()->count());
    }

    public function test_search_fuzzy_scope_matches_search_for_a_model_with_no_text_columns(): void
    {
        // Auto-detection finds nothing here (the only non-key column is cast to array), so
        // search()/searchOn() search no column and match nothing; searchFuzzy() must not
        // invent a 'name' one, and matches nothing too.
        \Illuminate\Support\Facades\Schema::create('payload_rows', function ($table) {
            $table->id();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
        DB::table('payload_rows')->insert(['payload' => '{"name":"john"}']);

        try {
            $model = new class extends \Illuminate\Database\Eloquent\Model {
                use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
                protected $table = 'payload_rows';
                protected $casts = ['payload' => 'array'];
            };

            $expected = $model::search('x')->getDebugInfo();
            $actual   = $model::searchFuzzy('x')->getDebugInfo();

            $this->assertSame([], $expected['searchable_columns']);
            $this->assertSame($expected['searchable_columns'], $actual['searchable_columns']);
            $this->assertSame($expected['column_weights'], $actual['column_weights']);
            $this->assertSame(0, $model::search('john')->count());
            $this->assertSame(0, $model::searchFuzzy('john')->count());
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('payload_rows');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Collection Filtering Tests
    |--------------------------------------------------------------------------
    */

    public function test_filter_fuzzy_on_collection(): void
    {
        $users = User::all();
        // "John Doe" vs "John" = 4 distance (space + D + o + e)
        // Use a higher max distance or search for "John Doe"
        $filtered = User::filterFuzzy($users, 'name', 'John Doe', 3);

        $this->assertGreaterThan(0, $filtered->count());
    }

    public function test_sort_by_fuzzy_on_collection(): void
    {
        $users = User::all();
        $sorted = User::sortByFuzzy($users, 'name', 'John');

        $this->assertGreaterThan(0, $sorted->count());

        // First result should be closest match
        $firstName = $sorted->first()->name;
        $this->assertStringContainsString('John', $firstName);
    }

    /*
    |--------------------------------------------------------------------------
    | Chaining Tests
    |--------------------------------------------------------------------------
    */

    public function test_fuzzy_with_other_eloquent_methods(): void
    {
        $results = User::fuzzy('john')
            ->select('name', 'email')
            ->orderBy('name')
            ->limit(5)
            ->get();

        $this->assertLessThanOrEqual(5, $results->count());
    }

    public function test_fuzzy_with_where_clause(): void
    {
        $results = User::fuzzy('john')
            ->where('email', 'like', '%example.com')
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Product Model Tests
    |--------------------------------------------------------------------------
    */

    public function test_product_fuzzy_search(): void
    {
        $results = Product::fuzzy('phone')->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_product_fuzzy_in_description(): void
    {
        $results = Product::fuzzy('laptop')->get();

        $this->assertGreaterThan(0, $results->count());
    }
}

