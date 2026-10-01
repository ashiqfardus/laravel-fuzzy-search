<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CacheKeyMainItem extends Model
{
    use Searchable;

    protected $table   = 'cache_key_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

/** The same table name on another connection, without the shadow column. */
class CacheKeyLegacyItem extends CacheKeyMainItem
{
    protected $connection = 'cache_key_legacy';
}

class CacheKeyTenantNote extends Model
{
    use Searchable;

    protected $connection = 'cache_key_tenant';
    protected $table      = 'cache_key_notes';
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

/** Zero-config: its columns are detected from the tenant's schema. */
class CacheKeyTenantZeroNote extends Model
{
    use Searchable;

    protected $connection = 'cache_key_tenant';
    protected $table      = 'cache_key_zero_notes';
    protected $guarded    = [];
    public $timestamps    = false;
}

/**
 * SD-2. SearchableObserver cached whether a table has its `*_metaphone` column by table and column
 * only, so the first model a process saved decided for every table of that name on any connection
 * or database: a save on a connection without the column then threw (the row already written), or
 * one with it was never given its codes, until the worker restarted. Auto-detection
 * (Searchable::getAutoDetectedColumns()) and SearchableColumns::onTable()/typesOn() left the
 * database out the same way, so a tenancy package that swaps the database behind one connection
 * name was answered from the first tenant's schema. All four are now keyed by connection name,
 * database and table prefix, as Bm25Scorer::modelIdCollation() is. The second connection and the
 * tenant databases are SQLite, beside whatever database the suite runs on.
 */
class SchemaCacheKeyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fuzzy-search.indexing.enabled' => false]);

        Schema::dropIfExists('cache_key_items');
        Schema::create('cache_key_items', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('title_metaphone')->nullable();
        });

        config(['database.connections.cache_key_legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('cache_key_legacy');
        Schema::connection('cache_key_legacy')->create('cache_key_items', function ($table) {
            $table->id();
            $table->string('title');
        });

        // Two tenant databases behind one connection name. A: the shadow column and a description
        // column; B: migrated before either existed.
        $this->dir = sys_get_temp_dir() . '/fuzzy-cache-key-' . getmypid() . '-' . uniqid();
        mkdir($this->dir);
        config(['database.connections.cache_key_tenant' => ['driver' => 'sqlite', 'database' => "{$this->dir}/a.sqlite", 'prefix' => '', 'foreign_key_constraints' => true]]);
        foreach (['a', 'b'] as $tenant) {
            touch("{$this->dir}/{$tenant}.sqlite");
            $this->useTenant($tenant);
            Schema::connection('cache_key_tenant')->create('cache_key_notes', function ($table) use ($tenant) {
                $table->id();
                $table->string('title');
                if ($tenant === 'a') {
                    $table->string('title_metaphone')->nullable();
                }
            });
            Schema::connection('cache_key_tenant')->create('cache_key_zero_notes', function ($table) use ($tenant) {
                $table->id();
                $table->string('title');
                if ($tenant === 'a') {
                    $table->string('description')->nullable();
                }
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cache_key_items');
        DB::purge('cache_key_legacy');
        DB::purge('cache_key_tenant');
        array_map('unlink', glob($this->dir . '/*.sqlite'));
        rmdir($this->dir);
        parent::tearDown();
    }

    /** What a tenancy package does per request: point the connection at the tenant's database. */
    private function useTenant(string $tenant): void
    {
        config(['database.connections.cache_key_tenant.database' => "{$this->dir}/{$tenant}.sqlite"]);
        DB::purge('cache_key_tenant');
    }

    private function mainCode(int|string $id): ?string
    {
        return DB::table('cache_key_items')->where('id', $id)->value('title_metaphone');
    }

    public function test_a_connection_without_the_shadow_column_saves_after_one_with_it(): void
    {
        $main = CacheKeyMainItem::create(['title' => 'Stephen']);
        $this->assertSame(metaphone('Stephen'), $this->mainCode($main->id));

        $legacy = CacheKeyLegacyItem::create(['title' => 'World']);
        $this->assertSame('World', DB::connection('cache_key_legacy')->table('cache_key_items')->where('id', $legacy->id)->value('title'));
    }

    public function test_a_connection_with_the_shadow_column_gets_its_codes_after_one_without_it(): void
    {
        CacheKeyLegacyItem::create(['title' => 'World']);
        $main = CacheKeyMainItem::create(['title' => 'Stephen']);

        $this->assertSame(metaphone('Stephen'), $this->mainCode($main->id));
        $this->assertSame(['Stephen'], CacheKeyMainItem::search('Steven')->using('metaphone')->get()->pluck('title')->all());
    }

    public function test_the_shadow_column_cache_follows_the_tenant_database(): void
    {
        // A, then B (no column there: the save must not write one), then A again.
        $this->useTenant('a');
        $first = CacheKeyTenantNote::create(['title' => 'Hello']);
        $this->useTenant('b');
        $b = CacheKeyTenantNote::create(['title' => 'World']);
        $this->assertSame('World', DB::connection('cache_key_tenant')->table('cache_key_notes')->where('id', $b->id)->value('title'));
        $this->useTenant('a');
        $again = CacheKeyTenantNote::create(['title' => 'Hello again']);

        foreach ([$first->id => 'Hello', $again->id => 'Hello again'] as $id => $title) {
            $this->assertSame(metaphone($title), DB::connection('cache_key_tenant')->table('cache_key_notes')->where('id', $id)->value('title_metaphone'), $title);
        }
    }

    public function test_the_shadow_column_cache_follows_the_tenant_database_b_first(): void
    {
        $this->useTenant('b');
        CacheKeyTenantNote::create(['title' => 'World']);
        $this->useTenant('a');
        $a = CacheKeyTenantNote::create(['title' => 'Hello']);

        $this->assertSame(metaphone('Hello'), DB::connection('cache_key_tenant')->table('cache_key_notes')->where('id', $a->id)->value('title_metaphone'));
    }

    public function test_auto_detected_columns_and_listings_follow_the_tenant_database(): void
    {
        $this->useTenant('a');
        $this->assertSame(['title', 'description'], (new CacheKeyTenantZeroNote)->getSearchableColumns());
        $this->assertSame(['id', 'title', 'description'], SearchableColumns::onTable(DB::connection('cache_key_tenant'), 'cache_key_zero_notes'));

        $this->useTenant('b');
        CacheKeyTenantZeroNote::create(['title' => 'alpha two']);
        $this->assertSame(['title'], (new CacheKeyTenantZeroNote)->getSearchableColumns());
        $this->assertSame(['id', 'title'], SearchableColumns::onTable(DB::connection('cache_key_tenant'), 'cache_key_zero_notes'));
        $this->assertSame(['alpha two'], CacheKeyTenantZeroNote::search('alpha')->get()->pluck('title')->all());

        if (method_exists(Schema::connection('cache_key_tenant'), 'getColumns')) { // Laravel 10 before getColumns(): no types
            $this->assertSame(['id', 'title'], array_keys(SearchableColumns::typesOn(DB::connection('cache_key_tenant'), 'cache_key_zero_notes')));
        }
    }
}
