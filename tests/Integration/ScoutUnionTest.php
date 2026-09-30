<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Laravel\Scout\Searchable;

/** The docs/integrations.md recipe. */
class ScoutUnionUser extends Model
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
}

/**
 * A union in the Scout builder's query() callback (rulings ER-125, ER-127). The engine checked the
 * ranking against it with a key read that added a column to the union's first part only, and
 * failed; under orderBy() its COUNT named "users"."id" outside the union and failed. map() read the
 * page through getScoutModelsByIds(), whose ids restricted only the union's first part, so the
 * union's other part was read whole (at 9eebace, served too: "Alice Smith", who does not match).
 * The engine and map() read the union as one derived table now (RankedCandidates::rows()).
 */
class ScoutUnionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);
        ScoutUnionUser::all()->searchable();
    }

    public function test_a_union_serves_exactly_the_matches_either_part_holds(): void
    {
        $hydrated = 0;
        Event::listen('eloquent.retrieved: ' . ScoutUnionUser::class, function () use (&$hydrated) {
            $hydrated++;
        });

        // Jane Doe, and a union of John Doe (a match) and Alice Smith (none).
        $union = fn () => ScoutUnionUser::scoutSearch('doe')
            ->query(fn ($query) => $query->where('email', 'jane@example.com')->union(ScoutUnionUser::query()->whereIn('name', ['John Doe', 'Alice Smith'])));

        $reads = [
            'get'              => fn () => $union()->get()->pluck('name')->all(),
            'paginate page 2'  => fn () => [($page = $union()->paginate(1, 'page', 2))->total(), $page->pluck('name')->all()],
            'orderBy get'      => fn () => $union()->orderBy('name')->get()->pluck('name')->all(),
            'orderBy paginate' => fn () => [($page = $union()->orderBy('name')->paginate(1, 'page', 2))->total(), $page->pluck('name')->all()],
        ];

        // [what each read serves, the models it hydrates]
        $found = [];
        foreach ($reads as $label => $read) {
            $hydrated = 0;

            try {
                $found[$label] = [$read(), $hydrated];
            } catch (\Illuminate\Database\QueryException $e) {
                $found[$label] = strtok($e->getMessage(), "\n");
            }
        }

        // Scout 10.0 has no PaginatesEloquentModelsUsingDatabase: Scout re-counts a query() callback's
        // matches itself (the documented M1 limit there), and its re-count reads the union's other part
        // whole. From 10.1 the engine paginates with the total it counted.
        $total = interface_exists(\Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase::class) ? 2 : 3;

        $this->assertSame([
            'get'              => [['John Doe', 'Jane Doe'], 2],
            'paginate page 2'  => [[$total, ['Jane Doe']], 1],
            'orderBy get'      => [['Jane Doe', 'John Doe'], 2],
            'orderBy paginate' => [[$total, ['John Doe']], 1],
        ], $found);
    }

    /** L13 (round 10): a union that selects no key throws a LogicException that names the fix, not the database's unknown-column error. */
    public function test_a_union_that_selects_no_key_is_rejected_naming_the_fix(): void
    {
        $union = fn () => ScoutUnionUser::scoutSearch('doe')
            ->query(fn ($query) => $query->select('name', 'email')->union(ScoutUnionUser::query()->select('name', 'email')->where('name', 'Alice Smith')));

        foreach (['get' => fn () => $union()->get(), 'paginate' => fn () => $union()->paginate(5), 'orderBy get' => fn () => $union()->orderBy('name')->get()] as $read => $run) {
            try {
                $run();
                $this->fail("{$read}: served a union without the key");
            } catch (\LogicException $e) {
                $this->assertStringContainsString("select the key ('id') in every part of the union", $e->getMessage(), $read);
            }
        }
    }
}
