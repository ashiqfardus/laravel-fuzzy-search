<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;

/** The docs/integrations.md recipe, with SoftDeletes. */
class ScoutSoftDeleteUser extends Model
{
    use SoftDeletes, Searchable, FuzzySearchable {
        FuzzySearchable::search insteadof Searchable;
        Searchable::search as scoutSearch;
        FuzzySearchable::bootSearchable insteadof Searchable;
        Searchable::bootSearchable as bootScoutSearchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10]];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

/** A model whose global scope hides John Doe, the way a tenant scope hides another tenant's rows. */
class ScopedIndexUser extends Model
{
    use FuzzySearchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10]];

    protected static function booted(): void
    {
        static::addGlobalScope('hide-john', fn (Builder $query) => $query->where('email', '!=', 'john@example.com'));
    }
}

/**
 * The indexer re-reads a row under its claim (ER-68) with the visibility of whoever asked for
 * the write (ER-72). The package's observer reads through the model's query: its global scopes,
 * and SoftDeletes, which removes a trashed row. The Scout engine reads as Scout's own jobs do:
 * without global scopes, and keeping a trashed row while scout.soft_delete is on, so Scout's
 * onlyTrashed() and withTrashed() still find it.
 */
class ScoutVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false, 'scout.soft_delete' => true]);
    }

    private function documents(string $class, $id): int
    {
        return DB::table('fuzzy_index_documents')->where('model_type', $class)->where('model_id', (string) $id)->count();
    }

    public function test_a_soft_deleted_model_stays_searchable_with_scout_soft_delete(): void
    {
        $john = ScoutSoftDeleteUser::where('name', 'John Doe')->first();
        $john->searchable();
        $john->delete(); // Scout's observer re-indexes a soft-deleted model when soft_delete is on

        $this->assertSame(1, $this->documents(ScoutSoftDeleteUser::class, $john->id));
        $this->assertSame(['John Doe'], ScoutSoftDeleteUser::scoutSearch('john')->onlyTrashed()->get()->pluck('name')->all());
        $this->assertContains('John Doe', ScoutSoftDeleteUser::scoutSearch('john')->withTrashed()->get()->pluck('name')->all());
        $this->assertNotContains('John Doe', ScoutSoftDeleteUser::scoutSearch('john')->get()->pluck('name')->all());
    }

    public function test_without_scout_soft_delete_a_trashed_model_leaves_the_index(): void
    {
        config(['scout.soft_delete' => false]);
        $john = ScoutSoftDeleteUser::where('name', 'John Doe')->first();
        $john->searchable();

        DB::table('users')->where('id', $john->id)->update(['deleted_at' => now()]);
        app(FuzzySearchEngine::class)->update(ScoutSoftDeleteUser::withTrashed()->whereKey($john->id)->get());

        $this->assertSame(0, $this->documents(ScoutSoftDeleteUser::class, $john->id));
    }

    public function test_scout_update_indexes_a_row_a_global_scope_hides(): void
    {
        $john = ScopedIndexUser::withoutGlobalScopes()->where('email', 'john@example.com')->first();

        app(FuzzySearchEngine::class)->update(collect([$john]));

        $this->assertSame(1, $this->documents(ScopedIndexUser::class, $john->id));
    }

    public function test_the_observer_path_still_reads_through_the_models_scopes(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $john = ScopedIndexUser::withoutGlobalScopes()->where('email', 'john@example.com')->first();
        $jane = ScopedIndexUser::where('email', 'jane@example.com')->first();

        $john->update(['name' => 'John Changed']); // a searchable column, so the observer re-indexes
        $jane->update(['name' => 'Jane Changed']);

        $this->assertSame(0, $this->documents(ScopedIndexUser::class, $john->id)); // hidden by its scope: not indexed (ER-68)
        $this->assertSame(1, $this->documents(ScopedIndexUser::class, $jane->id));
    }
}
