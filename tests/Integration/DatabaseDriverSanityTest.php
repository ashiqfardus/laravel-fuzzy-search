<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Guards the multi-database harness itself.
 *
 * If DB_TEST_DRIVER=mysql is set but the suite silently ran on sqlite, every other
 * "it works on MySQL" assertion in CI would be meaningless. This test fails loudly
 * in that situation.
 */
class DatabaseDriverSanityTest extends TestCase
{
    public function test_default_connection_matches_requested_driver(): void
    {
        $requested = strtolower((string) env('DB_TEST_DRIVER', 'sqlite'));
        $actual    = DB::connection()->getDriverName();

        // Laravel reports MariaDB as its own driver name only on newer versions;
        // accept either when MariaDB was requested.
        if ($requested === 'mariadb') {
            $this->assertContains($actual, ['mariadb', 'mysql']);
            return;
        }

        $this->assertSame($requested, $actual);
    }

    public function test_package_migrations_ran_on_the_active_connection(): void
    {
        foreach (['fuzzy_index_terms', 'fuzzy_index_postings', 'fuzzy_index_meta', 'fuzzy_index_documents'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table} on " . DB::connection()->getDriverName());
        }
    }

    public function test_fixture_tables_exist_on_the_active_connection(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertSame(7, DB::table('users')->count());
    }
}
