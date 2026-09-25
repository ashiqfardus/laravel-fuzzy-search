<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Hides email; an admin sees it (a retrieved listener, the README's per-viewer recipe). */
class CacheViewerUser extends Model
{
    use Searchable;

    public static bool $admin = false;

    protected $table   = 'users';
    protected $guarded = [];
    protected $hidden  = ['email'];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'like',
    ];
}

/**
 * H2 and F2 (rulings ER-83, ER-85). get() cached a Collection of models or stdClass rows. Laravel
 * 13's skeleton ships cache.serializable_classes = false, so every serialising store handed back
 * __PHP_Incomplete_Class and the hit threw a TypeError; and a hit served the rows with the
 * visibility and highlighting of the request that cached them, so a non-admin was shown the
 * email an admin's request had made visible. The cache now holds scalars and arrays only: the
 * row keys and scores (a plain query builder's rows as arrays). A hit re-reads the rows and
 * highlights and debugs them for the current request.
 */
class CachePayloadTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/fuzzy-cache-payload-' . getmypid();
        config([
            'cache.stores.fuzzy_file'     => ['driver' => 'file', 'path' => $this->path],
            'cache.serializable_classes'  => false,
            'fuzzy-search.cache.driver'   => 'fuzzy_file',
        ]);
        Cache::store('fuzzy_file')->flush();
        CacheViewerUser::$admin = false;
    }

    protected function tearDown(): void
    {
        Cache::store('fuzzy_file')->flush();
        @rmdir($this->path);
        CacheViewerUser::$admin = false;
        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> each row as the caller sees it */
    private function rows(Collection $results): array
    {
        return $results->map(fn ($row) => $row instanceof Model ? $row->toArray() : (array) $row)->all();
    }

    /** The stored entry: arrays and scalars only, so it unserialises with allowed_classes = false. */
    private function assertScalarPayload(): void
    {
        $files = glob($this->path . '/*/*/*') ?: [];
        $this->assertNotSame([], $files, 'nothing was cached');

        foreach ($files as $file) {
            $value = unserialize(substr((string) file_get_contents($file), 10), ['allowed_classes' => false]);
            array_walk_recursive($value, fn ($leaf) => $this->assertFalse(is_object($leaf), 'the cache holds an object'));
        }
    }

    public function test_a_hit_on_a_serialising_store_without_serializable_classes_returns_the_miss_rows(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        $searches = [
            'eloquent'       => fn () => User::search('john')->highlight()->debugScore()->cache(),
            'index'          => fn () => User::search('john')->useInvertedIndex()->highlight()->debugScore()->cache(),
            'extended'       => fn () => User::search('')->extended('john | jane')->highlight()->cache(),
            'query builder'  => fn () => (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('john')->searchIn(['name', 'email'])->highlight()->debugScore()->cache(),
            'builder index'  => fn () => (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('john')->useInvertedIndex(User::class)->highlight()->cache(),
            'simplePaginate' => fn () => User::search('jo')->cache(),
            'first'          => fn () => User::search('jane')->highlight()->cache(),
        ];
        $run = fn (string $label, \Closure $make) => match ($label) {
            'simplePaginate' => collect($make()->simplePaginate(2, 'page', 2)->items()),
            'first'          => collect([$make()->first()]),
            default          => $make()->get(),
        };

        foreach ($searches as $label => $make) {
            $miss = $run($label, $make);
            $hit  = $run($label, $make);

            $this->assertNotSame([], $this->rows($miss), $label);
            $this->assertEquals($this->rows($miss), $this->rows($hit), $label);
            $this->assertSame($miss->map(fn ($row) => $row::class)->all(), $hit->map(fn ($row) => $row::class)->all(), $label);
        }

        $this->assertScalarPayload();
    }

    public function test_each_viewer_gets_their_own_visibility_and_highlighting_on_a_hit(): void
    {
        app(IndexManager::class)->indexBatch(CacheViewerUser::all());
        CacheViewerUser::retrieved(fn (CacheViewerUser $user) => CacheViewerUser::$admin && $user->makeVisible('email'));

        $paths = [
            'like'  => fn () => CacheViewerUser::search('john')->highlight()->debugScore()->cache(),
            'index' => fn () => CacheViewerUser::search('john')->useInvertedIndex()->highlight()->debugScore()->cache(),
        ];

        foreach ($paths as $path => $make) {
            foreach (['admin first' => [true, false], 'viewer first' => [false, true]] as $order => $viewers) {
                Cache::store('fuzzy_file')->flush();

                foreach ($viewers as $admin) {
                    CacheViewerUser::$admin = $admin;
                    $row  = $make()->get()->firstWhere('name', 'John Doe');
                    $data = $row->toArray();
                    $who  = "{$path}, {$order}, " . ($admin ? 'admin' : 'viewer');

                    $this->assertSame($admin, array_key_exists('email', $data), "{$who}: email in toArray()");
                    $this->assertSame($admin, array_key_exists('email', $row->_highlighted), "{$who}: _highlighted");
                    $this->assertSame($admin, in_array('email', array_column($row->_matches, 'column'), true), "{$who}: _matches");
                    $this->assertSame($admin, in_array('email', $row->_debug['columns'], true), "{$who}: _debug.columns");
                    if ($path === 'like') { // the index path scores in SQL, with no per-column scores
                        $this->assertSame($admin, array_key_exists('email', $row->_debug['column_scores']), "{$who}: _debug.column_scores");
                    }
                }
            }
        }
    }

    public function test_a_row_deleted_after_caching_is_dropped_from_a_hit(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        foreach (['index' => fn () => User::search('john')->useInvertedIndex()->cache(), 'like' => fn () => User::search('john')->cache()] as $path => $make) {
            $miss = $make()->get();
            $this->assertContains('John Doe', $miss->pluck('name')->all(), $path);

            DB::table('users')->where('name', 'John Doe')->delete();
            $hit = $make()->get();
            DB::table('users')->insert(['name' => 'John Doe', 'email' => 'john@example.com']);

            $this->assertSame(
                $miss->reject(fn ($row) => $row->name === 'John Doe')->map(fn ($row) => [$row->name, $row->_score])->values()->all(),
                $hit->map(fn ($row) => [$row->name, $row->_score])->all(),
                $path
            );
        }
    }
}
