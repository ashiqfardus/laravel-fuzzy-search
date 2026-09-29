<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;

/** The docs/integrations.md recipe, with a global scope that hides Zed Beta, as a tenant scope hides another tenant's rows. */
class ScoutHidingUser extends Model
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
        static::addGlobalScope('hide-beta', fn (Builder $query) => $query->where('email', '!=', 'zed.beta@example.com'));
    }
}

/** The same, with SoftDeletes beside the hiding scope. */
class ScoutHidingTrashableUser extends Model
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
        static::addGlobalScope('hide-beta', fn (Builder $query) => $query->where('email', '!=', 'zed.beta@example.com'));
    }
}

/** The recipe with no global scope. */
class ScoutUnscopedUser extends Model
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

/** The recipe with SoftDeletes, whose scope is the only global scope. */
class ScoutUnscopedTrashableUser extends Model
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

/**
 * L5 (round 9): Scout indexes a row whatever the model's global scopes say, and the engine
 * checked the ranking against the model's query only when the Scout builder carried a
 * constraint. In relevance order a row a global scope hides still counted in total() and
 * hasMorePages() (and dropped out of the page, which came back short), while an orderBy()
 * page counted and served only the visible rows. A model with a global scope is now always
 * checked; a model without one keeps the unchecked ranking. Two scopes do not count: SoftDeletes'
 * (the engine handles trashed rows through Scout's __soft_deleted constraint) and Scout's own
 * SearchableScope, which every Scout model has and which only adds builder macros.
 */
class ScoutGlobalScopeTotalsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);

        foreach (['Zed Alpha', 'Zed Beta', 'Zed Gamma'] as $name) {
            DB::table('users')->insert([
                'name'       => $name,
                'email'      => strtolower(str_replace(' ', '.', $name)) . '@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function names(iterable $results): array
    {
        return collect($results instanceof \Illuminate\Contracts\Pagination\Paginator ? $results->items() : $results)
            ->pluck('name')->sort()->values()->all();
    }

    public function test_totals_and_items_are_the_same_in_every_order_under_a_hiding_global_scope(): void
    {
        foreach ([ScoutHidingUser::class, ScoutHidingTrashableUser::class] as $class) {
            foreach ([false, true] as $softDelete) {
                config(['scout.soft_delete' => $softDelete]);
                DB::table('fuzzy_index_documents')->delete();
                DB::table('fuzzy_index_postings')->delete();
                $class::withoutGlobalScopes()->where('name', 'like', 'Zed%')->get()->searchable();

                $label   = class_basename($class) . ', scout.soft_delete ' . var_export($softDelete, true);
                $visible = ['Zed Alpha', 'Zed Gamma'];

                foreach (['relevance' => fn () => $class::scoutSearch('zed'), 'orderBy' => fn () => $class::scoutSearch('zed')->orderBy('name')] as $order => $search) {
                    $this->assertSame(2, $search()->paginate(10)->total(), "{$label}, {$order}: paginate total");
                    $this->assertSame($visible, $this->names($search()->paginate(10)), "{$label}, {$order}: paginate items");
                    $this->assertSame($visible, $this->names([...$search()->paginate(1, 'page', 1)->items(), ...$search()->paginate(1, 'page', 2)->items()]), "{$label}, {$order}: one-row pages");
                    $this->assertFalse($search()->simplePaginate(1, 'page', 2)->hasMorePages(), "{$label}, {$order}: simplePaginate hasMorePages");
                    $this->assertSame($visible, $this->names($search()->simplePaginate(10)), "{$label}, {$order}: simplePaginate items");
                    $this->assertSame($visible, $this->names($search()->get()), "{$label}, {$order}: get");
                    $this->assertContains($search()->first()?->name, $visible, "{$label}, {$order}: first");
                    $this->assertSame(2, $search()->raw()['total'], "{$label}, {$order}: raw total");
                    $this->assertCount(2, $search()->keys(), "{$label}, {$order}: keys");
                }
            }
        }
    }

    public function test_a_model_without_a_global_scope_keeps_the_unchecked_ranking(): void
    {
        config(['scout.soft_delete' => false]);

        foreach ([ScoutUnscopedUser::class, ScoutUnscopedTrashableUser::class] as $class) {
            DB::table('fuzzy_index_documents')->delete();
            DB::table('fuzzy_index_postings')->delete();
            $class::where('name', 'like', 'Zed%')->get()->searchable();

            foreach (['get' => fn () => $class::scoutSearch('zed')->get(), 'paginate' => fn () => $class::scoutSearch('zed')->paginate(10)] as $terminal => $run) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                $results = $run();
                $log     = DB::getQueryLog();
                DB::disableQueryLog();

                $this->assertSame(['Zed Alpha', 'Zed Beta', 'Zed Gamma'], $this->names($results), class_basename($class) . " {$terminal}");

                // Only the page's models: no check of the ranking against the model's table, as at d80e264.
                $modelReads = array_filter($log, fn ($query) => str_contains($query['query'], 'users'));
                $this->assertCount(1, $modelReads, class_basename($class) . " {$terminal}: " . json_encode(array_column($log, 'query')));
            }
        }
    }
}
