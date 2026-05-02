<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;

class DriverRegistryTest extends TestCase
{
    private FuzzySearch $fuzzySearch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuzzySearch = app(FuzzySearch::class);
    }

    public function test_similar_text_driver_produces_like_sql(): void
    {
        $query = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($query, 'name', 'john', 'similar_text');
        $sql = strtolower($query->toSql());
        $bindings = $query->getBindings();

        $this->assertStringContainsString('like', $sql);
        $this->assertNotEmpty($bindings);
        // Binding will be a pattern like '%joh%' (70% of 'john')
        $this->assertStringContainsString('%', $bindings[0]);
        $this->assertStringContainsString('jo', $bindings[0]);
    }

    public function test_metaphone_driver_queries_shadow_column(): void
    {
        // Add the shadow column to the test table
        $this->app['db']->connection()->getSchemaBuilder()->table('users', function ($table) {
            $table->string('name_metaphone')->nullable();
        });

        $query = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($query, 'name', 'stephen', 'metaphone');
        $sql = strtolower($query->toSql());

        // Must query the shadow column, not the original
        $this->assertStringContainsString('name_metaphone', $sql);
        // Must NOT use SOUNDEX (the old wrong behavior)
        $this->assertStringNotContainsString('soundex', $sql);
    }

    public function test_metaphone_driver_throws_when_shadow_column_missing(): void
    {
        // No shadow column added — uses the plain 'users' table from setUp
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/name_metaphone/');

        $query = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($query, 'name', 'stephen', 'metaphone');
    }
}
