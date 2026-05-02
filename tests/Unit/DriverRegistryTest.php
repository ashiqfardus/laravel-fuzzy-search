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

    public function test_fuzzy_algorithm_produces_different_sql_from_levenshtein(): void
    {
        $fuzzyQ = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($fuzzyQ, 'name', 'john', 'fuzzy');

        $levenQ = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($levenQ, 'name', 'john', 'levenshtein');

        // Before the fix these were identical (both fell to LevenshteinDriver default).
        $this->assertNotEquals($fuzzyQ->toSql(), $levenQ->toSql());
    }

    public function test_simple_algorithm_generates_single_like_clause(): void
    {
        $query = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($query, 'name', 'john', 'simple');
        $sql = strtolower($query->toSql());
        $bindings = $query->getBindings();

        // toSql() uses ? placeholders — verify the single LIKE clause and binding separately
        $this->assertStringContainsString('like', $sql);
        // Confirm it's not generating the multi-pattern levenshtein output
        $this->assertEquals(1, substr_count($sql, 'like'));
        // Binding must be the wildcard-wrapped value
        $this->assertCount(1, $bindings);
        $this->assertEquals('%john%', $bindings[0]);
    }

    public function test_trigram_produces_different_sql_from_levenshtein(): void
    {
        $trigramQ = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($trigramQ, 'name', 'john', 'trigram');

        $levenQ = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($levenQ, 'name', 'john', 'levenshtein');

        $this->assertNotEquals($trigramQ->toSql(), $levenQ->toSql());
    }

    public function test_like_algorithm_is_alias_for_simple(): void
    {
        $likeQ = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($likeQ, 'name', 'test', 'like');

        $simpleQ = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($simpleQ, 'name', 'test', 'simple');

        $this->assertEquals($simpleQ->toSql(), $likeQ->toSql());
    }

    public function test_unknown_algorithm_throws_invalid_algorithm_exception(): void
    {
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidAlgorithmException::class);
        $query = $this->app['db']->table('users');
        $this->fuzzySearch->applyFuzzyWhere($query, 'name', 'john', 'does_not_exist');
    }

    public function test_legacy_dispatch_flag_suppresses_unknown_algorithm_exception(): void
    {
        config(['fuzzy-search.legacy_dispatch' => true]);
        $this->fuzzySearch = new \Ashiqfardus\LaravelFuzzySearch\FuzzySearch(config('fuzzy-search'));

        $query = $this->app['db']->table('users');
        // Must not throw; falls back to LevenshteinDriver silently
        $this->fuzzySearch->applyFuzzyWhere($query, 'name', 'john', 'does_not_exist');
        $this->assertTrue(true);
    }
}
