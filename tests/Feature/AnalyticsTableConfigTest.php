<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The search log migration creates the table analytics.table names, the one SearchAnalytics
 * writes and reads; and its indexed normalized_term column is 191 characters wide, so the index
 * fits MySQL's 767-byte key limit under utf8mb4 (255 characters made it 1,020 bytes).
 */
class AnalyticsTableConfigTest extends TestCase
{
    private const TABLE = 'custom_search_logs';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('fuzzy-search.analytics.table', self::TABLE);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->dropIfExists(self::TABLE); // a crashed run's leftover

        parent::defineDatabaseMigrations();
    }

    public function test_the_migration_creates_the_configured_table_and_searches_are_logged_there(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertFalse(Schema::hasTable('fuzzy_search_logs'));

        config(['fuzzy-search.analytics.enabled' => true]);
        User::search('john')->get();

        $this->assertSame('john', DB::table(self::TABLE)->value('term'));
        $this->assertSame('john', SearchAnalytics::popular()[0]['term']);
        $this->artisan('fuzzy-search:analytics')->expectsOutputToContain('john')->assertExitCode(0);

        $path = realpath(__DIR__ . '/../../database/migrations');
        $this->artisan('migrate:rollback', ['--path' => $path, '--realpath' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable(self::TABLE));
        $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertExitCode(0);
    }

    public function test_normalized_term_is_191_characters_wide(): void
    {
        $row = SearchAnalytics::rowFor(new FuzzySearchExecuted(str_repeat('é', 300), ['name'], 'fuzzy', 0, 1.0, 0));

        $this->assertSame(191, mb_strlen($row['normalized_term']));
        $this->assertSame(255, mb_strlen($row['term'])); // not indexed: keeps its 255

        SearchAnalytics::record($row); // fits the column on every database

        $schema = Schema::getConnection()->getSchemaBuilder();
        if ($this->dbDriver !== 'sqlite' && method_exists($schema, 'getColumns')) { // SQLite has no varchar length; Laravel 10 has no getColumns()
            $type = collect($schema->getColumns(self::TABLE))->firstWhere('name', 'normalized_term')['type'];
            // Laravel reports SQL Server's max_length, in bytes: an nvarchar(191) reads nvarchar(382).
            $this->assertMatchesRegularExpression($this->dbDriver === 'sqlsrv' ? '/^nvarchar\((191|382)\)$/' : '/\b191\b/', $type);
        }
    }
}
