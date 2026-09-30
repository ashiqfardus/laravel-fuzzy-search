<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrItem extends Model
{
    use Searchable;

    protected $table   = 'or_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

class SoftOrItem extends OrItem
{
    use SoftDeletes;
}

/** A global scope that hides the rows ranked past 30. */
class ScopedOrItem extends OrItem
{
    protected static function booted(): void
    {
        static::addGlobalScope('top', fn (Builder $query) => $query->where('rank', '<=', 30));
    }
}

class OrScoutItem extends OrItem
{
    use \Laravel\Scout\Searchable, Searchable {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::search as scoutSearch;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::bootSearchable as bootScoutSearchable;
    }

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

/**
 * RE-1 (round 10 deep review). The search predicate and every filter() were appended to the
 * caller's wheres ungrouped, so search($term)->where(A)->orWhere(B) compiled to
 * A or (B and <search>): every row of A came back whatever the term, on the LIKE and extended
 * paths, while the index path bracketed the caller's wheres and served only matches. A filter()
 * after such an orWhere() bound to its last branch alone, on every path. The caller's wheres are
 * now one group when one of them is an or, before anything is added; a query without an or
 * compiles as it did.
 */
class UngroupedOrWhereTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('or_items');
        Schema::create('or_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->integer('cat');
            $table->integer('rank');
            $table->timestamp('deleted_at')->nullable();
        });

        // 40 rows: the odd ones hold "garden", cat cycles 1-2-3, every 7th is trashed.
        DB::table('or_items')->insert(array_map(fn ($i) => [
            'name'       => ($i % 2 === 1 ? 'garden' : 'house') . " item {$i}",
            'cat'        => $i % 3 + 1,
            'rank'       => $i,
            'deleted_at' => $i % 7 === 0 ? now() : null,
        ], range(1, 40)));

        foreach ([OrItem::class, SoftOrItem::class, ScopedOrItem::class] as $class) {
            app(IndexManager::class)->indexBatch($class::withoutGlobalScopes()->get());
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('or_items');
        parent::tearDown();
    }

    /** @return array<string, \Closure(string): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder> */
    private function paths(): array
    {
        return [
            'like'      => fn (string $class) => $class::search('garden')->using('like'),
            'default'   => fn (string $class) => $class::search('garden'),
            'tokenized' => fn (string $class) => $class::search('garden item')->tokenize()->matchAll(),
            'extended'  => fn (string $class) => $class::search("'garden")->extended(),
            'index'     => fn (string $class) => $class::search('garden')->typoTolerance(0)->useInvertedIndex(),
        ];
    }

    /** @return string[] the names $query serves among the rows holding "garden", sorted */
    private function truth(Builder $query): array
    {
        return $query->where('name', 'like', 'garden%')->pluck('name')->sort()->values()->all();
    }

    public function test_an_ungrouped_or_where_stays_inside_the_search_on_every_path(): void
    {
        $models = [
            'plain'        => [OrItem::class, OrItem::query()],
            'SoftDeletes'  => [SoftOrItem::class, SoftOrItem::query()],
            'global scope' => [ScopedOrItem::class, ScopedOrItem::query()],
        ];

        foreach ($models as $model => [$class, $query]) {
            $expected = $this->truth($query->where(fn ($q) => $q->where('cat', 1)->orWhere('cat', 2)));
            $this->assertNotSame([], $expected);

            foreach ($this->paths() as $path => $make) {
                $search = fn () => $make($class)->where('cat', 1)->orWhere('cat', 2);
                $names  = $search()->take(100)->get()->pluck('name')->sort()->values()->all();

                $this->assertSame($expected, $names, "{$model}, {$path}: get");
                $this->assertSame(count($expected), $search()->count(), "{$model}, {$path}: count");
                $this->assertSame(count($expected), $search()->paginate(5)->total(), "{$model}, {$path}: total");
            }
        }
    }

    public function test_a_filter_after_an_ungrouped_or_where_applies_to_every_row(): void
    {
        $expected = $this->truth(OrItem::query()->where(fn ($q) => $q->where('cat', 1)->orWhere('cat', 2))->where('rank', '<=', 20));

        foreach ($this->paths() as $path => $make) {
            $search = fn () => $make(OrItem::class)->where('cat', 1)->orWhere('cat', 2)->filter('rank', '<=', 20);

            $this->assertSame($expected, $search()->take(100)->get()->pluck('name')->sort()->values()->all(), "{$path}: get");
            $this->assertSame(count($expected), $search()->count(), "{$path}: count");
        }
    }

    public function test_the_caller_wheres_are_grouped_only_when_one_is_an_or(): void
    {
        $q = '[`"\[]?'; // an identifier's opening quote, if the grammar writes one
        $e = '[`"\]]?';

        // No or: as before, no bracket around the caller's wheres, bindings in the caller's order.
        $and = OrItem::search('garden')->using('like')->where('cat', 1)->where('rank', '>', 5);
        $this->assertMatchesRegularExpression("/where {$q}cat{$e} = \\? and {$q}rank{$e} > \\? and \\(/", $and->toSql());
        $this->assertSame([1, 5], array_slice($and->getBindings(), 0, 2));

        // An or: one group, before the search, bindings unchanged.
        $or = OrItem::search('garden')->using('like')->where('cat', 1)->orWhere('cat', 2)->filter('rank', '<=', 20);
        $this->assertMatchesRegularExpression("/where \\({$q}cat{$e} = \\? or {$q}cat{$e} = \\?\\) and \\(.*\\) and {$q}rank{$e} <= \\?/", $or->toSql());
        $bindings = $or->getBindings();
        $this->assertSame([1, 2], array_slice($bindings, 0, 2));
        $this->assertGreaterThan(2, array_search(20, $bindings, true), 'the filter binds after the search');
    }

    /**
     * suggest()'s table scan reads limit * 3 rows: under the ungrouped or, the rows of its first
     * branch filled them whatever their words (the three first "house" rows here), and "garden", held
     * by the rows ranked past 30, was
     * never offered.
     */
    public function test_suggestions_under_an_ungrouped_or_where_read_rows_holding_the_prefix(): void
    {
        $this->assertSame(['garden'], array_map('strtolower', OrItem::search('gar')->suggestFrom('table')->where('name', 'like', 'house%')->orWhere('rank', '>', 30)->suggest(1)));
    }

    public function test_a_scout_query_callback_or_stays_inside_the_builder_wheres(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false]);
        OrScoutItem::all()->searchable();

        // where(cat, 1) must hold for both branches of the callback's or.
        $expected = $this->truth(OrItem::query()->where('cat', 1)->where(fn ($q) => $q->where('rank', '<', 10)->orWhere('rank', '>', 30)));
        $search   = fn () => OrScoutItem::scoutSearch('garden')->where('cat', 1)->query(fn ($q) => $q->where('rank', '<', 10)->orWhere('rank', '>', 30));

        // The page's models load through the callback too: its or kept the page's ids to one branch,
        // and every row of the other was hydrated.
        $hydrated = 0;
        \Illuminate\Support\Facades\Event::listen('eloquent.retrieved: ' . OrScoutItem::class, function () use (&$hydrated) {
            $hydrated++;
        });

        $this->assertSame($expected, $search()->take(100)->get()->pluck('name')->sort()->values()->all(), 'get');
        $this->assertSame(count($expected), $hydrated, 'models hydrated');
        // Scout 10.0 counts the total itself, through the callback and an ungrouped or: the
        // documented limit of the engine's pagination there (Scout 10.1+ takes the engine's total).
        if (interface_exists(\Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase::class)) {
            $this->assertSame(count($expected), $search()->paginate(5)->total(), 'total');
        }
        $this->assertSame($expected, $search()->orderBy('rank')->take(100)->get()->pluck('name')->sort()->values()->all(), 'orderBy get');
    }
}
