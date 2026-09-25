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
use Illuminate\Support\Facades\Schema;

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

/** Every query of it joins the user's posts, through a global scope. */
class CacheScopedJoinUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10], 'algorithm' => 'like'];

    protected static function booted(): void
    {
        static::addGlobalScope('posts', fn ($query) => $query->join('cache_posts', 'cache_posts.user_id', '=', 'users.id')
            ->select('users.*', 'cache_posts.title as post_title'));
    }
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
        Schema::dropIfExists('cache_posts');
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
            'like'    => fn () => CacheViewerUser::search('john')->highlight()->debugScore()->cache(),
            'index'   => fn () => CacheViewerUser::search('john')->useInvertedIndex()->highlight()->debugScore()->cache(),
            // No key: cached as attributes and hydrated on a hit (ruling ER-92), retrieved listeners included.
            'keyless' => fn () => CacheViewerUser::search('john')->select('name', 'email')->highlight()->debugScore()->cache(),
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
                    if ($path !== 'index') { // the index path scores in SQL, with no per-column scores
                        $this->assertSame($admin, array_key_exists('email', $row->_debug['column_scores']), "{$who}: _debug.column_scores");
                    }
                }
            }
        }
    }

    /**
     * Rulings ER-92 (N1) and ER-93 (P1): rows that share a key (a one-to-many join) or carry none (a
     * select without it) cannot be re-read by key: a hit returned the last joined row twice, or
     * nothing. Nor can a row of a search with a join or a union, even alone under its key: first(),
     * take() or a page holding one of a key's joined rows, or a search on the joined column, got
     * another joined row back. Those rows are cached as attributes and hydrated on a hit, so it
     * returns the miss's rows.
     */
    public function test_a_hit_returns_joined_and_keyless_rows_as_the_miss_did(): void
    {
        Schema::dropIfExists('cache_posts');
        Schema::create('cache_posts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
        });
        $john = DB::table('users')->where('name', 'John Doe')->value('id');
        DB::table('cache_posts')->insert([['user_id' => $john, 'title' => 'First post'], ['user_id' => $john, 'title' => 'Second post']]);

        $joined = fn (string $term, array $columns) => User::search($term)->join('cache_posts', 'cache_posts.user_id', '=', 'users.id')
            ->select('users.*', 'cache_posts.title as post_title')->using('like')->searchIn($columns)->highlight()->cache();
        $union  = fn () => User::searchOn(
            User::query()->select('users.*', DB::raw("'main' as source"))
                ->union(User::query()->select('users.*', DB::raw("'extra' as source"))->where('name', 'John Doe')),
            'john doe'
        )->using('like')->searchIn(['name'])->withRelevance(false)->cache();

        $searches = [
            'one-to-many join'           => fn () => $joined('john doe', ['name'])->get(),
            'no key selected'            => fn () => User::search('john')->select('name', 'email')->highlight()->debugScore()->cache()->get(),
            // P1: one row per key in the result, but the join holds more rows for that key.
            'join, first()'              => fn () => collect([$joined('john doe', ['name'])->first()]),
            'join, take(1)'              => fn () => $joined('john doe', ['name'])->take(1)->get(),
            'join, skip(1)->take(1)'     => fn () => $joined('john doe', ['name'])->skip(1)->take(1)->get(),
            'join, simplePaginate page 2' => fn () => collect($joined('john doe', ['name'])->simplePaginate(1, 'page', 2)->items()),
            'a search on the joined column' => fn () => $joined('first', ['cache_posts.title'])->get(),
            'a join from a global scope' => fn () => CacheScopedJoinUser::search('first')->searchIn(['cache_posts.title'])->highlight()->cache()->get(),
            'a union, first()'           => fn () => collect([$union()->first()]),
        ];

        $wrong = [];
        foreach ($searches as $label => $run) {
            $miss = $this->rows($run());
            $hit  = $this->rows($run());

            $this->assertNotSame([], $miss, $label);
            if ($miss != $hit) {
                $wrong[$label] = ['miss' => array_column($miss, 'post_title') ?: array_column($miss, 'source'), 'hit' => array_column($hit, 'post_title') ?: array_column($hit, 'source') ?: count($hit) . ' rows'];
            }
        }
        $this->assertSame([], $wrong, 'a hit served other rows than its miss');

        $this->assertEqualsCanonicalizing(['First post', 'Second post'], $searches['one-to-many join']()->pluck('post_title')->all());
        $this->assertSame(['First post'], $searches['a search on the joined column']()->pluck('post_title')->all());
        $this->assertSame(['First post'], $searches['a join from a global scope']()->pluck('post_title')->all());
        $this->assertScalarPayload();
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
