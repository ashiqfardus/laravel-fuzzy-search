<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Tests\ListColumnsUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\ZeroConfigUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../TestModels.php';

/**
 * A model that uses the trait without declaring $searchable['columns'] — the README Quick
 * Start — must be treated as searchable everywhere, not only by search() itself:
 * getSearchableColumns() feeds the indexer, the shadow-column observer,
 * FuzzySearch::tableSearch() and extended search.
 */
class ZeroConfigSearchableTest extends TestCase
{
    public function test_zero_config_model_reports_its_auto_detected_columns(): void
    {
        $this->assertSame(['name', 'email'], (new ZeroConfigUser)->getSearchableColumns());
    }

    public function test_list_form_columns_report_names_not_positions(): void
    {
        $this->assertSame(['name', 'email'], (new ListColumnsUser)->getSearchableColumns());
    }

    public function test_weighted_columns_still_report_their_keys(): void
    {
        $this->assertSame(['name', 'email'], (new User)->getSearchableColumns());
    }

    public function test_zero_config_model_is_indexed(): void
    {
        app(IndexManager::class)->indexModel(ZeroConfigUser::query()->first());

        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_list_form_model_is_indexed(): void
    {
        app(IndexManager::class)->indexModel(ListColumnsUser::query()->first());

        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_table_search_applies_a_predicate_for_a_zero_config_model(): void
    {
        $query = ZeroConfigUser::query();
        $before = $query->toSql();

        FuzzySearch::tableSearch()($query, 'john');

        $this->assertNotSame($before, $query->toSql(), 'tableSearch() added no predicate');

        $names = $query->pluck('name')->all();
        $this->assertContains('John Doe', $names);
        $this->assertNotContains('Charlie Brown', $names);
    }

    public function test_shadow_columns_are_maintained_for_a_zero_config_model(): void
    {
        Schema::table('users', fn ($table) => $table->string('name_metaphone')->nullable());
        SearchableObserver::resetColumnCache();

        $zero = ZeroConfigUser::create(['name' => 'Stephen', 'email' => 'stephen@example.com']);

        $this->assertSame(
            metaphone('Stephen'),
            DB::table('users')->where('id', $zero->getKey())->value('name_metaphone')
        );
    }

    public function test_rebuild_warns_when_no_records_produced_index_terms(): void
    {
        // Searchable columns present but empty: every row is skipped, so "Done." alone would
        // report an index that was never written.
        DB::table('users')->update(['name' => '', 'email' => '']);

        $this->artisan('fuzzy-search:rebuild', ['model' => ZeroConfigUser::class])
            ->expectsOutputToContain('Indexed 0 of 7 records')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_rebuild_reports_what_it_indexed(): void
    {
        $this->artisan('fuzzy-search:rebuild', ['model' => ZeroConfigUser::class])
            ->expectsOutputToContain('Done. Indexed 7 of 7 records.')
            ->assertSuccessful();

        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());
    }
}
