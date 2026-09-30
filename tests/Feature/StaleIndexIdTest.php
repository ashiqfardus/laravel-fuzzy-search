<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** The users table with a getSearchScore() that keeps every score: the hook's window path, in the BM25 order. */
class StaleIdHookUser extends \Illuminate\Database\Eloquent\Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10]];

    public function getSearchScore($baseScore): float
    {
        return $baseScore;
    }
}

/**
 * M2 (round 10), ruling ER-133. A ranked id whose row is gone (deleted without a model event: a
 * foreign-key cascade, Model::where()->delete(), DB::table()->delete()) leaves an unconstrained
 * index page one row short, as documented. simplePaginate() then lost its look-ahead row and
 * reported no further page, so every later match was unreachable, and first() returned null while
 * matches existed. simplePaginate() now takes hasMorePages() from the ranking, and first() reads on
 * past the id. A constrained search, a getSearchScore() model and orderBy() read only rows that
 * exist, and serve full pages.
 */
class StaleIndexIdTest extends TestCase
{
    /** @var string[] the 40 matches' names, best first */
    private array $ranked = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();

        // 40 "doe" rows, each one word longer than the one before, so each scores below it.
        DB::table('users')->delete();
        DB::table('users')->insert(array_map(fn ($i) => ['name' => 'Doe ' . str_repeat('pad ', $i) . sprintf('%02d', $i), 'email' => "d{$i}@example.com"], range(0, 39)));

        foreach ([User::class, StaleIdHookUser::class] as $class) {
            app(IndexManager::class)->indexBatch($class::all());
        }

        $this->ranked = $this->search()->take(40)->get()->pluck('name')->all();
        $this->assertCount(40, $this->ranked);
    }

    private function search(string $class = User::class): SearchBuilder
    {
        return $class::search('doe')->typoTolerance(0)->useInvertedIndex();
    }

    /**
     * Deletes the rows at these ranks (from 0) without a model event, as a cascade does.
     *
     * @return string[] the names left, best first
     */
    private function deleteRanks(int ...$ranks): array
    {
        $gone = array_map(fn (int $rank) => $this->ranked[$rank], $ranks);
        DB::table('users')->whereIn('name', $gone)->delete();

        return array_values(array_diff($this->ranked, $gone));
    }

    /** @return array{string[], int[], bool[]} what following hasMorePages() from page 1 serves: the names, each page's size, and each page's hasMorePages() */
    private function followSimplePages(\Closure $make, int $perPage = 15): array
    {
        $names = $sizes = $more = [];

        for ($page = 1; $page <= 5 && ($page === 1 || end($more)); $page++) {
            $paginator = $make()->simplePaginate($perPage, 'page', $page);
            $names     = [...$names, ...collect($paginator->items())->pluck('name')->all()];
            $sizes[]   = count($paginator->items());
            $more[]    = $paginator->hasMorePages();
        }

        return [$names, $sizes, $more];
    }

    public function test_simple_paginate_keeps_its_next_page_past_a_stale_id(): void
    {
        $left = $this->deleteRanks(2);

        // The page is its own ranks, one row short, and the ranking says more follow.
        $this->assertSame([$left, [14, 15, 10], [true, true, false]], $this->followSimplePages(fn () => $this->search()), 'unconstrained');
        $this->assertSame([$left, [14, 15, 10], [true, true, false]], $this->followSimplePages(fn () => $this->search()->cache(60)), 'cache miss');
        $this->assertSame([$left, [14, 15, 10], [true, true, false]], $this->followSimplePages(fn () => $this->search()->cache(60)), 'cache hit');

        // take(16)->get() serves 16 ranks where simplePaginate(15) serves 15: its cache entry is not the page's.
        Cache::flush();
        $this->search()->cache(60)->take(16)->get();
        $this->assertSame([$left, [14, 15, 10], [true, true, false]], $this->followSimplePages(fn () => $this->search()->cache(60)), 'after take(16)->get()');

        // These read only rows that exist.
        $this->assertSame([$left, [15, 15, 9], [true, true, false]], $this->followSimplePages(fn () => $this->search()->where('email', 'like', '%@example.com')), 'constrained');
        $this->assertSame([$left, [15, 15, 9], [true, true, false]], $this->followSimplePages(fn () => $this->search(StaleIdHookUser::class)), 'getSearchScore()');
        // Inside the hook's window the stale id holds no place: 39 rows fill three pages of 13.
        $this->assertSame([$left, [13, 13, 13], [true, true, false]], $this->followSimplePages(fn () => $this->search(StaleIdHookUser::class), 13), 'getSearchScore(), pages of 13');

        $byName = $left;
        sort($byName);
        $this->assertSame([$byName, [15, 15, 9], [true, true, false]], $this->followSimplePages(fn () => $this->search()->orderBy('name')), 'orderBy()');
    }

    public function test_paginate_leaves_a_page_with_a_stale_id_one_row_short(): void
    {
        $left  = $this->deleteRanks(2);
        $pages = array_map(fn (int $page) => $this->search()->paginate(15, 'page', $page), [1, 2, 3]);

        $this->assertSame([14, 15, 10], array_map(fn ($page) => count($page->items()), $pages));
        $this->assertSame($left, collect($pages)->flatMap(fn ($page) => collect($page->items())->pluck('name'))->all());
        $this->assertSame(40, $pages[0]->total());
    }

    public function test_first_reads_on_past_a_stale_id(): void
    {
        $left = $this->deleteRanks(0, 1, 3);

        $this->assertSame($this->ranked[2], $this->search()->first()?->name);
        $this->assertSame($this->ranked[4], $this->search()->skip(3)->first()?->name, 'skip(3): ranks 3 and 4 hold one row');
        $this->assertSame($this->ranked[2], $this->search()->cache(60)->first()?->name, 'cache miss');
        $this->assertSame($this->ranked[2], $this->search()->cache(60)->first()?->name, 'cache hit');

        // take(1)->get() serves the first rank alone: its cache entry is not first()'s.
        Cache::flush();
        $this->assertCount(0, $this->search()->cache(60)->take(1)->get());
        $this->assertSame($this->ranked[2], $this->search()->cache(60)->first()?->name, 'after take(1)->get()');

        // These read only rows that exist.
        $this->assertSame($this->ranked[2], $this->search()->where('email', 'like', '%@example.com')->first()?->name, 'constrained');
        $this->assertSame($this->ranked[2], $this->search(StaleIdHookUser::class)->first()?->name, 'getSearchScore()');
        $this->assertSame(min($left), $this->search()->orderBy('name')->first()?->name, 'orderBy()');

        // Past the last row that exists, there is none.
        DB::table('users')->whereIn('name', array_slice($this->ranked, 30))->delete();
        $this->assertNull($this->search()->skip(30)->first(), 'every later id is stale');
    }
}
