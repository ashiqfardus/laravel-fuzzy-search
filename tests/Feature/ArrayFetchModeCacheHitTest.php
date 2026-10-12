<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Under an app-wide array fetch mode (a StatementPrepared listener setting PDO::FETCH_ASSOC) the LIKE
 * path works, and so must its cache hit: a hit re-reads the cached models by key through
 * RankedCandidates::models(), which took each row as an object and threw "Attempt to read property
 * on array".
 */
class ArrayFetchModeCacheHitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Event::forget(StatementPrepared::class); // the teardown's migrator reads objects

        parent::tearDown();
    }

    public function test_a_cache_hit_of_a_like_search_reads_under_an_array_fetch_mode(): void
    {
        $expected = User::search('doe')->using('like')->get()->pluck('name')->sort()->values()->all();
        $this->assertNotSame([], $expected);

        Event::listen(StatementPrepared::class, fn (StatementPrepared $event) => $event->statement->setFetchMode(\PDO::FETCH_ASSOC));
        $run = fn () => User::search('doe')->using('like')->cache(60)->get()->pluck('name')->sort()->values()->all();

        $this->assertSame($expected, $run(), 'the miss');
        $this->assertSame($expected, $run(), 'the hit');
    }

    /**
     * UA-3. Rows that are not models were cached as arrays and every hit rebuilt them as stdClass, so
     * a search whose miss served arrays (a query builder under an array fetch mode, or an afterQuery()
     * that maps models to arrays on Laravel 11+) served objects from the second request on. A hit
     * now serves the rows in the miss's shape, in the miss's collection class.
     */
    public function test_a_cache_hit_serves_rows_in_the_shape_the_miss_served(): void
    {
        $shape = fn (Collection $rows) => [get_class($rows), $rows->map(fn ($row) => get_debug_type($row) . ':' . data_get($row, 'name'))->sort()->values()->all()];
        $same  = function (string $label, \Closure $make) use ($shape): void {
            $miss = $shape($make()->cache(60, "shape-{$label}")->get());
            $this->assertNotSame([], $miss[1], $label);
            $this->assertSame($miss, $shape($make()->cache(60, "shape-{$label}")->get()), "{$label}: the hit");
        };
        $builder = fn () => (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('doe')->searchIn(['name'])->using('like');

        $same('query builder', $builder);
        if (method_exists(\Illuminate\Database\Eloquent\Builder::class, 'afterQuery')) { // Laravel 11+
            $same('afterQuery to arrays', fn () => User::search('doe')->using('like')->query(fn ($q) => $q->afterQuery(fn ($rows) => $rows->map->only(['id', 'name']))));
            $same('afterQuery to objects', fn () => User::search('doe')->using('like')->query(fn ($q) => $q->afterQuery(fn ($rows) => $rows->map(fn ($user) => (object) ['name' => $user->name]))));
        }

        Event::listen(StatementPrepared::class, fn (StatementPrepared $event) => $event->statement->setFetchMode(\PDO::FETCH_ASSOC));
        $same('query builder, array fetch mode', $builder);
    }
}
