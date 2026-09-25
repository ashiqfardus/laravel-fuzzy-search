<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A model with Scout's trait only, on the fuzzy-search driver. */
class ScoutTraitOnlyUser extends Model
{
    use \Laravel\Scout\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    public function toSearchableArray(): array
    {
        return ['name' => $this->name];
    }
}

/**
 * M7 (round 8), ruling D3. The engine indexes and searches through the package's Searchable trait.
 * v2.0.1 failed loudly on a model without it; 2.1 indexed nothing and searched nothing, silently.
 * Indexing and searching throw again, naming the fix; delete() and flush() only remove rows, as in
 * 2.0.1, so deleting a record still works.
 */
class ScoutWithoutPackageTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);
    }

    public function test_indexing_and_searching_throw_naming_the_trait(): void
    {
        $calls = [
            'searchable()'         => fn () => ScoutTraitOnlyUser::all()->searchable(),
            'makeAllSearchable()'  => fn () => ScoutTraitOnlyUser::makeAllSearchable(),
            'save()'               => fn () => ScoutTraitOnlyUser::create(['name' => 'Scout Only', 'email' => 'scout-only@example.com']),
            'search()->get()'      => fn () => ScoutTraitOnlyUser::search('john')->get(),
            'search()->paginate()' => fn () => ScoutTraitOnlyUser::search('john')->paginate(),
            'search()->keys()'     => fn () => ScoutTraitOnlyUser::search('john')->keys(),
        ];

        foreach ($calls as $call => $run) {
            try {
                $run();
                $this->fail("{$call} ran without the package trait");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable', $e->getMessage(), $call);
                $this->assertStringContainsString(ScoutTraitOnlyUser::class, $e->getMessage(), $call);
            }
        }

        $this->assertSame(0, DB::table('fuzzy_index_documents')->count());
    }

    public function test_delete_and_flush_still_run(): void
    {
        $john = ScoutTraitOnlyUser::where('name', 'John Doe')->first();

        $john->unsearchable();
        ScoutTraitOnlyUser::removeAllFromSearch();
        $john->delete(); // Scout's observer removes it from the index

        $this->assertNull(ScoutTraitOnlyUser::find($john->id));
    }
}
