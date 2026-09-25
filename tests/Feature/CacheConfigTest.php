<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Same table as User, another class: the key must tell them apart. */
class OtherClassUser extends Model
{
    protected $table   = 'users';
    protected $guarded = [];
}

/**
 * The cache key left out what changes a search's output (highlight tags, withRelevance(), debug
 * mode, the index model class, the connection and its database), so one search was served
 * another's rows: `highlight('mark')->cache()` then a plain `cache()` returned <mark> markup,
 * and tenant B got tenant A's rows. And the `cache.*` config was never read.
 */
class CacheConfigTest extends TestCase
{
    use CreatesRelationTables;

    /** @var string[] */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.stores.fuzzy_array' => ['driver' => 'array']]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach (['fuzzy_tenant_a', 'fuzzy_tenant_b', 'fuzzy_tenant'] as $connection) {
            DB::purge($connection);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $this->dropRelationTables();
        parent::tearDown();
    }

    /** @return string[] the keys an array store holds */
    private function storedKeys(?string $store = null): array
    {
        return (fn () => array_keys($this->storage))->call(Cache::store($store)->getStore());
    }

    /** A builder whose generated key the test can read. */
    private function probe($query): SearchBuilder
    {
        return new class ($query, app(FuzzySearch::class)) extends SearchBuilder {
            public function key(): ?string
            {
                return $this->generateCacheKey();
            }
        };
    }

    private function names($builder): array
    {
        return $builder->get()->pluck('name')->sort()->values()->all();
    }

    // --- the key --------------------------------------------------------------------------

    public function test_highlight_tags_are_part_of_the_key(): void
    {
        User::search('john')->highlight('mark')->cache()->get();

        $plain = User::search('john')->cache()->get();
        $this->assertNull($plain->first()->_highlighted, 'a plain search was served the highlighted entry');

        $em = User::search('john')->highlight('em')->cache()->get()->firstWhere('name', 'John Doe');
        $this->assertStringContainsString('<em>', $em->_highlighted['name']);
    }

    public function test_relevance_and_debug_are_part_of_the_key(): void
    {
        User::search('john')->withRelevance(false)->cache()->get();
        $this->assertNotNull(User::search('john')->cache()->get()->first()->_score);

        User::search('jane')->debugScore()->cache()->get();
        $this->assertNull(User::search('jane')->cache()->get()->first()->_debug);
    }

    public function test_every_audited_property_changes_the_key(): void
    {
        config([
            'database.connections.fuzzy_tenant_a' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'database.connections.fuzzy_tenant_b' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        $base = fn ($query = null) => $this->probe($query ?? User::query())->search('john')->searchIn(['name']);
        $key  = $base()->key();

        $variants = [
            'highlight'       => $base()->highlight('mark')->key(),
            'highlight tags'  => $base()->highlight('<b>', '</b>')->key(),
            'withRelevance'   => $base()->withRelevance(false)->key(),
            'debugScore'      => $base()->debugScore()->key(),
            'model class'     => $base(OtherClassUser::query())->key(),
            'eager loads'     => $base(User::query()->with('roles'))->key(),
            'index model'     => $base()->useInvertedIndex(OtherClassUser::class)->key(),
            'connection'      => $base(DB::connection('fuzzy_tenant_a')->table('users'))->key(),
        ];

        foreach ($variants as $property => $variant) {
            $this->assertNotSame($key, $variant, $property);
        }

        $this->assertNotSame(
            $base(DB::connection('fuzzy_tenant_a')->table('users'))->key(),
            $base(DB::connection('fuzzy_tenant_b')->table('users'))->key(),
            'two connections with the same SQL'
        );
        $this->assertSame($key, $base()->key(), 'the key is stable');
    }

    public function test_two_connections_never_share_an_entry(): void
    {
        foreach (['fuzzy_tenant_a' => 'John Alpha', 'fuzzy_tenant_b' => 'John Beta'] as $connection => $name) {
            config(["database.connections.{$connection}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
            Schema::connection($connection)->create('users', function ($table) {
                $table->id();
                $table->string('name');
            });
            DB::connection($connection)->table('users')->insert(['name' => $name]);
        }

        $search = fn (string $connection) => $this->names((new SearchBuilder(DB::connection($connection)->table('users'), app(FuzzySearch::class)))
            ->search('john')->searchIn(['name'])->using('like')->cache());

        $this->assertSame(['John Alpha'], $search('fuzzy_tenant_a'));
        $this->assertSame(['John Beta'], $search('fuzzy_tenant_b'));
    }

    public function test_one_connection_switched_to_another_database_never_shares_an_entry(): void
    {
        $search = fn () => $this->names((new SearchBuilder(DB::connection('fuzzy_tenant')->table('users'), app(FuzzySearch::class)))
            ->search('john')->searchIn(['name'])->using('like')->cache());

        foreach (['a' => 'John Alpha', 'b' => 'John Beta'] as $tenant => $name) {
            $this->files[] = $file = sys_get_temp_dir() . "/fuzzy-cache-tenant-{$tenant}-" . getmypid() . '.sqlite';
            @unlink($file);
            touch($file);

            DB::purge('fuzzy_tenant');
            config(['database.connections.fuzzy_tenant' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
            Schema::connection('fuzzy_tenant')->create('users', function ($table) {
                $table->id();
                $table->string('name');
            });
            DB::connection('fuzzy_tenant')->table('users')->insert(['name' => $name]);

            $this->assertSame([$name], $search(), "tenant {$tenant}");
        }
    }

    // --- the cache.* config ---------------------------------------------------------------

    /** unicode.accent_insensitive is read once, into the builder: folding on and off are two searches. */
    public function test_the_global_accent_folding_default_is_part_of_the_key(): void
    {
        User::create(['name' => 'Muller', 'email' => 'muller@example.com']);

        $search = function (bool $fold) {
            config(['fuzzy-search.unicode.accent_insensitive' => $fold]);

            return $this->probe(User::query())->search('Müller')->searchIn(['name'])->using('like');
        };

        $this->assertNotSame($search(true)->key(), $search(false)->key());

        $folded = $search(true)->cache()->get()->pluck('_raw_score', 'name')->all();
        $this->assertSame(['Muller' => 100.0], $folded);
        $this->assertNotSame($folded, $search(false)->cache()->get()->pluck('_raw_score', 'name')->all(), 'folding off was served the folded entry');
    }

    private function addJohn(): void
    {
        User::create(['name' => 'John Newman', 'email' => 'newman@example.com']);
    }

    public function test_the_shipped_config_caches_nothing(): void
    {
        $before = count($this->names(User::search('john')->using('like')));
        $this->addJohn();

        $this->assertCount($before + 1, $this->names(User::search('john')->using('like')));
        $this->assertSame([], $this->storedKeys());
    }

    public function test_cache_enabled_caches_every_search_without_cache(): void
    {
        config(['fuzzy-search.cache.enabled' => true]);

        $before = count($this->names(User::search('john')->using('like')));
        $this->addJohn();

        $this->assertCount($before, $this->names(User::search('john')->using('like')), 'served from the cache');
        $this->assertNotSame([], $this->storedKeys());
    }

    public function test_the_ttl_is_seconds_and_is_honoured(): void
    {
        config(['fuzzy-search.cache.enabled' => true, 'fuzzy-search.cache.ttl' => 120]);

        $before = count($this->names(User::search('john')->using('like')));
        $this->addJohn();

        $this->travel(119)->seconds();
        $this->assertCount($before, $this->names(User::search('john')->using('like')), 'within the ttl');

        $this->travel(2)->seconds();
        $this->assertCount($before + 1, $this->names(User::search('john')->using('like')), 'past the ttl');
    }

    public function test_an_explicit_cache_overrides_the_ttl(): void
    {
        config(['fuzzy-search.cache.enabled' => true, 'fuzzy-search.cache.ttl' => 120]);
        $search = fn () => $this->names(User::search('john')->using('like')->cache(5)); // minutes

        $before = count($search());
        $this->addJohn();

        $this->travel(121)->seconds();
        $this->assertCount($before, $search(), 'cache(5) outlives the 120-second ttl');

        $this->travel(180)->seconds();
        $this->assertCount($before + 1, $search(), 'past 5 minutes');
    }

    public function test_the_store_is_honoured(): void
    {
        config(['fuzzy-search.cache.enabled' => true, 'fuzzy-search.cache.driver' => 'fuzzy_array']);

        User::search('john')->get();
        User::search('jane')->cache(10)->get();

        $this->assertCount(2, $this->storedKeys('fuzzy_array'));
        $this->assertSame([], $this->storedKeys());
    }

    public function test_default_is_the_apps_default_store(): void
    {
        config(['fuzzy-search.cache.enabled' => true, 'fuzzy-search.cache.driver' => 'default']);

        User::search('john')->get();

        $this->assertCount(1, $this->storedKeys());
    }

    public function test_generated_keys_start_with_the_prefix(): void
    {
        config(['fuzzy-search.cache.enabled' => true, 'fuzzy-search.cache.prefix' => 'fz_test_']);

        User::search('john')->get();
        Product::search('phone')->cache(10)->get();
        User::search('jane')->cache(10, 'my-own-key')->get(); // a key the caller names is used as given

        $keys = $this->storedKeys();
        sort($keys);
        $this->assertCount(3, $keys);
        $this->assertSame('my-own-key', $keys[2]);
        $this->assertStringStartsWith('fz_test_', $keys[0]);
        $this->assertStringStartsWith('fz_test_', $keys[1]);
    }

    public function test_cache_zero_opts_one_query_out(): void
    {
        config(['fuzzy-search.cache.enabled' => true]);
        $search = fn () => $this->names(User::search('john')->using('like')->cache(0));

        $before = count($search());
        $this->addJohn();

        $this->assertCount($before + 1, $search());
        $this->assertSame([], $this->storedKeys());
    }
    /** Finding 2: schema-per-tenant on PostgreSQL switches search_path on one connection and database. */
    public function test_a_postgres_connection_switched_to_another_schema_never_shares_an_entry(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('search_path is PostgreSQL-only; the CI PostgreSQL job runs this.');
        }

        $tenants = ['zz_fz_ta' => 'John Alpha', 'zz_fz_tb' => 'John Beta'];
        $default = config('database.connections.' . config('database.default'));

        try {
            foreach ($tenants as $schema => $name) {
                DB::statement("drop schema if exists {$schema} cascade");
                DB::statement("create schema {$schema}");
                DB::statement("create table {$schema}.users (id serial primary key, name varchar(255))");
                DB::table("{$schema}.users")->insert(['name' => $name]);
            }

            foreach ($tenants as $schema => $name) {
                DB::purge('fuzzy_tenant');
                config(['database.connections.fuzzy_tenant' => ['search_path' => $schema] + $default]);

                $this->assertSame([$name], $this->names((new SearchBuilder(DB::connection('fuzzy_tenant')->table('users'), app(FuzzySearch::class)))
                    ->search('john')->searchIn(['name'])->using('like')->cache()), $schema);
            }
        } finally {
            DB::purge('fuzzy_tenant');
            foreach (array_keys($tenants) as $schema) {
                DB::statement("drop schema if exists {$schema} cascade");
            }
        }
    }

    /**
     * Ruling ER-58: a cached payload carries no relations; each read loads the current request's
     * eager loads, constraint closures included, which the key cannot see.
     */
    public function test_a_cache_hit_loads_the_current_requests_eager_loads(): void
    {
        $this->createRelationTables();
        $this->seedRelationFixtures();

        $tags = fn (string $name) => Post::search('ring')->with(['tags' => fn ($q) => $q->where('name', $name)])->cache()->get()
            ->first()->tags->pluck('name')->all();

        $this->assertSame(['fantasy'], $tags('fantasy'));
        $this->assertSame(['epic'], $tags('epic'), 'served the first request\'s related rows');

        // The relation a searchIn() column reads is loaded on a hit as on a miss, and never stored.
        $search = fn () => Post::search('tolkien')->searchIn(['title', 'author.name'])->cache()->get();
        $search();
        $hit = $search();
        $this->assertTrue($hit->first()->relationLoaded('author'));
        $this->assertSame('Tolkien', $hit->first()->author->name);

        foreach ($this->storedKeys() as $key) {
            foreach (Cache::get($key) as $row) {
                $this->assertSame([], $row->getRelations(), 'a stored row carries no relation');
            }
        }
    }
}
