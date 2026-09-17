<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Laravel\Scout\SearchableScope;

/** The README "Scout Driver → Usage" recipe, verbatim apart from the table and columns. */
class DualTraitUser extends Model
{
    use Searchable, FuzzySearchable {
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

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}

class ScoutDualTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null', 'scout.queue' => false]);
    }

    public function test_the_readme_recipe_boots_with_both_traits_wired(): void
    {
        $this->assertInstanceOf(SearchBuilder::class, DualTraitUser::search('john'));
        $this->assertInstanceOf(\Laravel\Scout\Builder::class, DualTraitUser::scoutSearch('john'));
        $this->assertTrue(DualTraitUser::hasGlobalScope(SearchableScope::class), 'Scout\'s bootSearchable() must have run from booted()');
    }

    public function test_the_package_observer_still_indexes_on_save(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        $user = DualTraitUser::create(['name' => 'Dual Trait', 'email' => 'dual@example.com']);

        $this->assertSame(1, DB::table('fuzzy_index_documents')->where('model_type', DualTraitUser::class)->where('model_id', (string) $user->getKey())->count());
    }
}
