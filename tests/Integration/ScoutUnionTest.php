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

    public function tags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ScoutUnionTag::class, 'user_id');
    }
}

class ScoutUnionTag extends Model
{
    protected $table   = 'scout_union_tags';
    public $timestamps = false;
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
        // matches itself, through the callback, and its re-count read the union's other part whole
        // (3). The engine reads the union as one table there too (SD-1). From 10.1 the engine
        // paginates with the total it counted.
        $this->assertSame([
            'get'              => [['John Doe', 'Jane Doe'], 2],
            'paginate page 2'  => [[2, ['Jane Doe']], 1],
            'orderBy get'      => [['Jane Doe', 'John Doe'], 2],
            'orderBy paginate' => [[2, ['John Doe']], 1],
        ], $found);
    }

    /**
     * SD-1: on Scout 10.0, paginate() hands the engine the caller's Builder, then counts through its
     * query() callback itself. The engine replaces that callback once with one that groups the
     * callback's or and reads a union as one table, as its own reads do: a second paginate() does not
     * wrap it again, a clone shares it, and a later query() replaces it. From Scout 10.1 the engine
     * paginates with its own total, and the callback is left as it is.
     */
    public function test_scout_10_0_counts_through_the_callback_once_wrapped(): void
    {
        // Only Jane Doe: the builder's whereIn() holds for both branches of the callback's or. An
        // ungrouped re-count read "email = john or (email = jane and id in (…))": John Doe too.
        $jane     = ScoutUnionUser::query()->where('name', 'Jane Doe')->value('id');
        $callback = fn ($query) => $query->where('email', 'john@example.com')->orWhere('email', 'jane@example.com');
        $builder  = ScoutUnionUser::scoutSearch('doe')->whereIn('id', [$jane])->query($callback);

        $this->assertSame(1, $builder->paginate(1)->total(), 'page 1');
        $wrapped = $builder->queryCallback;
        $this->assertSame(1, $builder->paginate(1, 'page', 2)->total(), 'page 2, the same Builder');
        $this->assertSame($wrapped, $builder->queryCallback, 'not wrapped twice');
        $this->assertSame(1, (clone $builder)->paginateRaw(1)->total(), 'a clone, paginateRaw()');
        $this->assertSame(['Jane Doe'], $builder->get()->pluck('name')->all(), 'get() after paginate()');

        if (interface_exists(\Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase::class)) {
            $this->assertSame($callback, $wrapped, 'Scout 10.1+: the callback is left as it is');
        } else {
            $this->assertNotSame($callback, $wrapped, 'Scout 10.0: wrapped');
            $other = fn ($query) => $query->where('email', 'john@example.com')->orWhere('email', 'jane@example.com');
            $builder->query($other);
            $this->assertSame($other, $builder->queryCallback, 'a later query() replaces it');
            $this->assertSame(1, $builder->paginate(1)->total(), 'and is wrapped in its turn');
            $this->assertNotSame($other, $builder->queryCallback);
        }
    }

    /**
     * A query() callback that joins a one-to-many table serves each model once. On Scout 10.0 Scout
     * counts paginate()'s total itself, a model once per joined row: the documented limit there
     * (docs/integrations.md: filter with whereHas()). From Scout 10.1 the engine counts models.
     */
    public function test_a_one_to_many_join_in_the_callback_is_counted_per_model_but_by_scout_10_0(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('scout_union_tags');
        \Illuminate\Support\Facades\Schema::create('scout_union_tags', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('tag');
        });

        try {
            $ids = ScoutUnionUser::query()->pluck('id', 'name');
            \Illuminate\Support\Facades\DB::table('scout_union_tags')->insert([
                ['user_id' => $ids['John Doe'], 'tag' => 'a'], ['user_id' => $ids['John Doe'], 'tag' => 'b'], ['user_id' => $ids['John Doe'], 'tag' => 'c'],
                ['user_id' => $ids['Jane Doe'], 'tag' => 'a'],
            ]);
            $join = fn () => ScoutUnionUser::scoutSearch('doe')
                ->query(fn ($query) => $query->join('scout_union_tags', 'scout_union_tags.user_id', '=', 'users.id')->select('users.*'));

            $this->assertSame(['Jane Doe', 'John Doe'], $join()->get()->pluck('name')->sort()->values()->all(), 'each model once');
            $this->assertSame(
                interface_exists(\Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase::class) ? 2 : 4,
                $join()->paginate(1)->total(),
                'Scout 10.0 counts the joined rows'
            );
            $this->assertSame(2, (new \Laravel\Scout\Builder(new ScoutUnionUser, 'doe'))->query(fn ($query) => $query->whereHas('tags'))->paginate(1)->total(), 'whereHas() counts models on every Scout');
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('scout_union_tags');
        }
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
