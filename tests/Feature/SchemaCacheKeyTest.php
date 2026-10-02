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

/** A tenant model on a PostgreSQL connection whose search_path a tenancy package swaps. */
class CacheKeySchemaNote extends Model
{
    use Searchable;

    protected $connection = 'cache_key_schema';
    protected $table      = 'cache_key_notes';
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

class CacheKeySchemaZeroNote extends CacheKeySchemaNote
{
    protected $table = 'cache_key_zero_notes';

    protected array $searchable = [];
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

    /**
     * TC-2. The key also holds where the connection points: host and port (database-per-tenant
     * across servers, with alike database names), and on PostgreSQL the configured search_path
     * (schema-per-tenant: one connection name and database, search_path set per tenant, then purged).
     */
    public function test_the_key_holds_the_host_the_port_and_the_postgresql_search_path(): void
    {
        $key = function (array $config): string {
            config(['database.connections.cache_key_shape' => $config + ['prefix' => '', 'database' => 'tenant', 'username' => 'u', 'password' => '']]);
            DB::purge('cache_key_shape');

            return SearchableColumns::connectionKey(DB::connection('cache_key_shape')); // no query: the PDO is opened lazily
        };

        $mysql = ['driver' => 'mysql', 'host' => '10.0.0.1', 'port' => 3306];
        $this->assertNotSame($key($mysql), $key(['host' => '10.0.0.2'] + $mysql), 'host');
        $this->assertNotSame($key($mysql), $key(['port' => 3307] + $mysql), 'port');
        $this->assertSame($key($mysql), $key($mysql));

        $pgsql = ['driver' => 'pgsql', 'host' => '10.0.0.1', 'port' => 5432];
        $this->assertNotSame($key(['search_path' => 'tenant_a'] + $pgsql), $key(['search_path' => 'tenant_b'] + $pgsql), 'search_path');
        $this->assertNotSame($key(['schema' => 'tenant_a'] + $pgsql), $key(['schema' => 'tenant_b'] + $pgsql), 'schema (Laravel 10)');
        $this->assertNotSame($key(['search_path' => ['a', 'public']] + $pgsql), $key(['search_path' => ['b', 'public']] + $pgsql), 'search_path array');

        DB::purge('cache_key_shape');
    }

    public function test_the_schema_caches_follow_a_postgresql_search_path_swap(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (CI runs it): schema-per-tenant swaps search_path.');
        }

        foreach (['cache_key_ta', 'cache_key_tb'] as $schema) {
            DB::statement("drop schema if exists {$schema} cascade");
            DB::statement("create schema {$schema}");
        }
        // Tenant A: the shadow column and a description column; tenant B: migrated before either.
        DB::statement('create table cache_key_ta.cache_key_notes (id bigserial primary key, title varchar(255) not null, title_metaphone varchar(255) null)');
        DB::statement('create table cache_key_tb.cache_key_notes (id bigserial primary key, title varchar(255) not null)');
        DB::statement('create table cache_key_ta.cache_key_zero_notes (id bigserial primary key, title varchar(255) not null, description varchar(255) null)');
        DB::statement('create table cache_key_tb.cache_key_zero_notes (id bigserial primary key, title varchar(255) not null)');

        $useSchema = function (string $schema): void {
            config(['database.connections.cache_key_schema' => ['search_path' => $schema, 'schema' => $schema] + config('database.connections.' . config('database.default'))]);
            DB::purge('cache_key_schema');
        };
        $code = fn (string $schema, int|string $id) => DB::table("{$schema}.cache_key_notes")->where('id', $id)->value('title_metaphone');

        try {
            // A, then B (the save must not write a column B lacks), then A again.
            $useSchema('cache_key_ta');
            $first = CacheKeySchemaNote::create(['title' => 'Stephen']);
            $useSchema('cache_key_tb');
            $b = CacheKeySchemaNote::create(['title' => 'World']);
            $this->assertSame(['World'], DB::table('cache_key_tb.cache_key_notes')->where('id', $b->id)->pluck('title')->all());
            $useSchema('cache_key_ta');
            $again = CacheKeySchemaNote::create(['title' => 'Hello again']);
            $this->assertSame(metaphone('Stephen'), $code('cache_key_ta', $first->id));
            $this->assertSame(metaphone('Hello again'), $code('cache_key_ta', $again->id));
            $this->assertSame(['Stephen'], CacheKeySchemaNote::search('Steven')->using('metaphone')->get()->pluck('title')->all());

            // Detection: A's columns, then B's own.
            DB::table('cache_key_ta.cache_key_zero_notes')->insert(['title' => 'alpha one', 'description' => 'gamma']);
            DB::table('cache_key_tb.cache_key_zero_notes')->insert(['title' => 'alpha two']);
            $this->assertSame(['title', 'description'], (new CacheKeySchemaZeroNote)->getSearchableColumns());
            $useSchema('cache_key_tb');
            $this->assertSame(['title'], (new CacheKeySchemaZeroNote)->getSearchableColumns());
            $this->assertSame(['id', 'title'], SearchableColumns::onTable(DB::connection('cache_key_schema'), 'cache_key_zero_notes'));
            $this->assertSame(['alpha two'], CacheKeySchemaZeroNote::search('alpha')->get()->pluck('title')->all());

            // The statistics check's memory: tenant A's tables described, tenant B's still read.
            $manager  = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
            $analyzed = new \ReflectionProperty($manager, 'analyzed');
            $analyzed->setAccessible(true);
            $stale    = new \ReflectionMethod($manager, 'statisticsStale');
            $stale->setAccessible(true);
            $useSchema('cache_key_ta');
            $a = DB::connection('cache_key_schema');
            $analyzed->setValue(null, [SearchableColumns::connectionKey($a) . '|' . $a->scalar("select current_setting('search_path')") => true]);
            $this->assertFalse($stale->invoke($manager, $a), 'tenant A remembered');
            $useSchema('cache_key_tb');
            $this->assertTrue($stale->invoke($manager, DB::connection('cache_key_schema')), 'tenant B answered from tenant A');
        } finally {
            \Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::resetPipelineCache();
            DB::purge('cache_key_schema');
            foreach (['cache_key_ta', 'cache_key_tb'] as $schema) {
                DB::statement("drop schema if exists {$schema} cascade");
            }
        }
    }

    /**
     * TC-2's addendum. Three more per-process caches built "name|database|prefix" by hand: the
     * collation Bm25Scorer reads for its string-key lookups, and the PostgreSQL statistics check's
     * memory and its pending after-commit check. They take connectionKey() too. The collation's
     * entry for one server must not answer a connection re-pointed at another: a hit returns the
     * remembered collation without a query, a miss reads information_schema (here a refused port).
     */
    public function test_the_collation_cache_follows_connection_key(): void
    {
        $collations = new \ReflectionProperty(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class, 'collations');
        $collations->setAccessible(true);
        $saved  = $collations->getValue();
        $lookup = new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class, 'columnCollation');
        $lookup->setAccessible(true);
        $point  = function (int $port): \Illuminate\Database\Connection {
            config(['database.connections.cache_key_shape' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => $port, 'database' => 'tenant', 'username' => 'u', 'password' => '', 'prefix' => '']]);
            DB::purge('cache_key_shape');

            return DB::connection('cache_key_shape'); // the PDO opens only on a query
        };

        try {
            $first = $point(1);
            $collations->setValue(null, [SearchableColumns::connectionKey($first) . '|items|code' => ['utf8mb4', 'utf8mb4_bin']]);
            $this->assertSame(['utf8mb4', 'utf8mb4_bin'], $lookup->invoke(null, $first, 'items', 'code'), 'a hit reads nothing');

            $other = $point(2);
            try {
                $lookup->invoke(null, $other, 'items', 'code');
                $this->fail('the other server was answered from the first one\'s collation');
            } catch (\Illuminate\Database\QueryException|\PDOException) {
                $this->addToAssertionCount(1); // a miss: it read information_schema on port 2
            }
        } finally {
            $collations->setValue(null, $saved);
            DB::purge('cache_key_shape');
        }
    }

    public function test_the_postgresql_statistics_checks_follow_connection_key(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (CI runs it): the package analyzes its index tables there.');
        }

        $manager  = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
        $analyzed = new \ReflectionProperty($manager, 'analyzed');
        $analyzed->setAccessible(true);
        $stale    = new \ReflectionMethod($manager, 'statisticsStale');
        $stale->setAccessible(true);
        $pending  = new \ReflectionProperty($manager, 'pendingChecks');
        $pending->setAccessible(true);
        $connection = DB::connection();
        $searchPath = $connection->scalar("select current_setting('search_path')");

        try {
            // The postings table is empty here, so a miss reads pg_class and finds it stale.
            $analyzed->setValue(null, [SearchableColumns::connectionKey($connection) . '|' . $searchPath => true]);
            $this->assertFalse($stale->invoke($manager, $connection), 'remembered under connectionKey()');
            $analyzed->setValue(null, [$connection->getName() . '|' . $connection->getDatabaseName() . '|' . $connection->getTablePrefix() . '|' . $searchPath => true]);
            $this->assertTrue($stale->invoke($manager, $connection), 'not under the old name|database|prefix key');

            $connection->beginTransaction();
            (new \ReflectionMethod($manager, 'analyzeUnanalyzedIndex'))->invoke($manager);
            $this->assertSame([SearchableColumns::connectionKey($connection)], array_keys($pending->getValue()));
            $connection->rollBack();
        } finally {
            \Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::resetPipelineCache();
        }
    }
}
