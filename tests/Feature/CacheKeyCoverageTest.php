<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Same table as User, another class: the index model it names is another search. */
class IndexModelOtherUser extends Model
{
    protected $table   = 'users';
    protected $guarded = [];
}

/**
 * Cache-key parts no other test noticed when removed: the base query's SQL, the connection's host
 * and port, and the index model. Without each, two searches with different rows shared an entry.
 */
class CacheKeyCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::purge('fuzzy_key_tenant');
        parent::tearDown();
    }

    private function key($query, ?string $indexModel = null): ?string
    {
        $builder = new class ($query, app(FuzzySearch::class)) extends SearchBuilder {
            public function key(): ?string
            {
                return $this->generateCacheKey();
            }
        };
        $builder->search('doe')->searchIn(['name']);

        return ($indexModel === null ? $builder : $builder->useInvertedIndex($indexModel))->key();
    }

    /** base_sql: the same bound value on another column is another search (plain builder rows are served as stored). */
    public function test_a_where_on_another_column_with_the_same_value_is_not_served_the_first_entry(): void
    {
        User::create(['name' => 'Jake Doe', 'email' => 'Jane Doe']);
        $run = fn ($query) => (new SearchBuilder($query, app(FuzzySearch::class)))
            ->search('doe')->searchIn(['name'])->using('like')->cache(60)->get()->pluck('name')->sort()->values()->all();

        // An authorization-shaped filter: owner_id = 5 against assignee_id = 5.
        $byName  = $run(DB::table('users')->where('name', 'Jane Doe'));
        $byEmail = $run(DB::table('users')->where('email', 'Jane Doe'));

        $this->assertSame(['Jane Doe'], $byName);
        $this->assertSame(['Jake Doe'], $byEmail, "served the other column's cached rows");
    }

    /** host and port: one connection name re-pointed at another server (a tenant per host) is another key. */
    public function test_a_connection_re_pointed_at_another_host_or_port_gets_another_key(): void
    {
        $name = 'fuzzy_key_tenant';
        $keys = [];

        foreach ([['tenant-a.internal', 5432], ['tenant-b.internal', 5432], ['tenant-b.internal', 6432]] as [$host, $port]) {
            config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'host' => $host, 'port' => $port]]);
            DB::purge($name);
            // SQLite ignores host and port; the key must not.
            $keys["{$host}:{$port}"] = $this->key(DB::connection($name)->table('users'));
        }

        $this->assertCount(3, array_unique($keys), json_encode($keys));
    }

    /** index_model: the same plain query ranked against two models' postings is two searches. */
    public function test_the_index_model_alone_changes_the_key(): void
    {
        $this->assertNotSame(
            $this->key(DB::table('users'), User::class),
            $this->key(DB::table('users'), IndexModelOtherUser::class)
        );
    }
}
