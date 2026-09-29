<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;

/** The docs/integrations.md recipe. */
class ScoutSlimUser extends Model
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

/** The recipe, with a global scope that selects without the key. */
class ScoutSlimScopeUser extends Model
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
        static::addGlobalScope('slim', fn (Builder $query) => $query->select('name', 'email'));
    }
}

/**
 * The Scout side of L10 (round 9), ruling D13. map() read the page's models through Scout's
 * getScoutModelsByIds() and put them in the page's order, and gave them their scores, by their
 * keys. A query() callback whose select() leaves the key out (a slim payload) gave models with no
 * key: every row scored 0 and came back in the database's order, so first() served the wrong row.
 * The key is now read beside the select list under an alias, which never reaches the models, and
 * each model is served once, so a join that repeats it no longer fills the page with copies.
 */
class ScoutSelectWithoutKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);

        // Three orders that all differ: relevance Top, Mid, Low; the ids Mid, Top, Low; the names Low, Mid, Top.
        foreach (['Zed Mid zed' => 'mid', 'Zed Top zed zed' => 'top', 'Zed Low' => 'low'] as $name => $email) {
            DB::table('users')->insert(['name' => $name, 'email' => "{$email}@example.com", 'created_at' => now(), 'updated_at' => now()]);
        }

        ScoutSlimUser::where('name', 'like', 'Zed%')->get()->searchable();
    }

    /** @return array<string, \Closure(\Closure(): \Laravel\Scout\Builder): iterable> each terminal's rows */
    private static function terminals(): array
    {
        return [
            'get'                => fn ($make) => $make()->get(),
            'first'              => fn ($make) => array_filter([$make()->first()]),
            'cursor'             => fn ($make) => $make()->cursor()->all(),
            'paginate'           => fn ($make) => $make()->paginate(2)->items(),
            'paginate page 2'    => fn ($make) => $make()->paginate(2, 'page', 2)->items(),
            'simplePaginate'     => fn ($make) => $make()->simplePaginate(2)->items(),
            'orderBy get'        => fn ($make) => $make()->orderBy('name')->get(),
            'orderBy paginate'   => fn ($make) => $make()->orderBy('name')->paginate(2)->items(),
            'constrained get'    => fn ($make) => $make()->whereIn('email', ['top@example.com', 'low@example.com'])->get(),
        ];
    }

    public function test_a_select_without_the_key_serves_its_rows_in_order_and_scored(): void
    {
        $make = fn () => ScoutSlimUser::scoutSearch('zed')->query(fn ($query) => $query->select('name', 'email'));

        $this->assertSame(3, $make()->paginate(2)->total());
        $this->assertSame(3, $make()->orderBy('name')->paginate(2)->total());

        // Scored as the rows of the same search with every column.
        $scores = ScoutSlimUser::scoutSearch('zed')->get()->pluck('_score', 'name')->all();
        $this->assertSame(['Zed Top zed zed', 'Zed Mid zed', 'Zed Low'], array_keys($scores));
        $this->assertGreaterThan(0, min($scores));

        $served = array_map(fn ($terminal) => collect($terminal($make))->map(fn ($user) => [
            $user->name,
            $user->_score,
            array_keys($user->getAttributes()),
            array_keys($user->getOriginal()),
            array_keys($user->toArray()),
        ])->all(), self::terminals());

        $row = fn (string $name) => [$name, $scores[$name], ['name', 'email', '_score'], ['name', 'email'], ['name', 'email', '_score']];

        $this->assertSame([
            'get'                => [$row('Zed Top zed zed'), $row('Zed Mid zed'), $row('Zed Low')],
            'first'              => [$row('Zed Top zed zed')],
            'cursor'             => [$row('Zed Top zed zed'), $row('Zed Mid zed'), $row('Zed Low')],
            'paginate'           => [$row('Zed Top zed zed'), $row('Zed Mid zed')],
            'paginate page 2'    => [$row('Zed Low')],
            'simplePaginate'     => [$row('Zed Top zed zed'), $row('Zed Mid zed')],
            'orderBy get'        => [$row('Zed Low'), $row('Zed Mid zed'), $row('Zed Top zed zed')],
            'orderBy paginate'   => [$row('Zed Low'), $row('Zed Mid zed')],
            'constrained get'    => [$row('Zed Top zed zed'), $row('Zed Low')],
        ], $served);
    }

    public function test_a_global_scope_that_selects_without_the_key(): void
    {
        DB::table('fuzzy_index_documents')->delete();
        ScoutSlimScopeUser::withoutGlobalScopes()->where('name', 'like', 'Zed%')->get()->searchable();

        $users = ScoutSlimScopeUser::scoutSearch('zed')->get();

        $this->assertSame(['Zed Top zed zed', 'Zed Mid zed', 'Zed Low'], $users->pluck('name')->all());
        $this->assertGreaterThan(0, $users->min('_score'));
        $this->assertSame(['name', 'email', '_score'], array_keys($users->first()->getAttributes()));
    }

    public function test_a_join_that_repeats_a_model_serves_it_once(): void
    {
        // Every user once per product (4): the page counted 3 matches and served 4 copies of each.
        $make = fn () => ScoutSlimUser::scoutSearch('zed')->query(fn ($query) => $query->crossJoin('products')->select('users.*'));

        $this->assertSame(['Zed Top zed zed', 'Zed Mid zed', 'Zed Low'], $make()->get()->pluck('name')->all());

        // raw()'s total, the engine's: on Scout 10.0 paginate() re-counts under query(), the joined rows (M1).
        $this->assertSame(3, $make()->raw()['total']);
        $this->assertSame(['Zed Top zed zed', 'Zed Mid zed'], collect($make()->paginate(2)->items())->pluck('name')->all());
        $this->assertSame(['Zed Top zed zed'], collect($make()->orderBy('name')->paginate(2, 'page', 2)->items())->pluck('name')->all());
    }
}
