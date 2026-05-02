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
}
