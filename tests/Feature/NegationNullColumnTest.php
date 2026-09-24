<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * NOT (title LIKE x OR description LIKE x) is NULL, not true, when description is NULL, so every
 * negation dropped rows with a NULL searchable column. Each column inside a negated predicate is
 * read as '' when it is NULL.
 */
class NegationNullColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Product::query()->where('title', 'MacBook Pro')->update(['description' => null]);
    }

    /** @return string[] */
    private function titles(string $query): array
    {
        return Product::search('')->extended($query)->get()->pluck('title')->sort()->values()->all();
    }

    public function test_every_negation_form_keeps_a_row_with_a_null_column(): void
    {
        $this->assertSame(['MacBook Pro', 'iPhone 15 Pro'], $this->titles('pro'));

        $dropped = [];
        foreach (['!zzzbanned', '!=zzzbanned', '!^zzz', '!zzz$', "!'zzz", '!~zzzbanned', '!title:zzz', '!description:zzz', '!"zzz banned"'] as $negation) {
            $titles = $this->titles("pro {$negation}");
            if ($titles !== ['MacBook Pro', 'iPhone 15 Pro']) {
                $dropped[] = "pro {$negation}: " . json_encode($titles);
            }
        }

        $this->assertSame([], $dropped);
    }

    public function test_a_lone_negation_keeps_rows_with_a_null_column(): void
    {
        $this->assertSame(['MacBook Pro', 'Samsung Galaxy S24', 'iPad Air', 'iPhone 15 Pro'], $this->titles('!zzzbanned'));
    }

    public function test_a_negation_still_excludes_through_the_other_column(): void
    {
        $this->assertSame(['iPhone 15 Pro'], $this->titles('pro !macbook'));
        $this->assertSame(['iPhone 15 Pro'], $this->titles('pro !^mac'));
        $this->assertSame([], $this->titles('pro !pro$'));
        $this->assertSame(['iPhone 15 Pro'], $this->titles("pro !'macbook"));
        $this->assertSame(['iPhone 15 Pro'], $this->titles('pro !~macbok'));
        $this->assertSame(['iPhone 15 Pro'], $this->titles('pro !title:macbook'));

        Product::create(['title' => 'Pixel', 'description' => null]);
        $this->assertSame(['MacBook Pro', 'Samsung Galaxy S24', 'iPad Air', 'iPhone 15 Pro'], $this->titles('!=pixel'));
    }

    public function test_count_and_paginate_keep_the_row_too(): void
    {
        $this->assertSame(2, Product::search('')->extended('pro !zzzbanned')->count());
        $this->assertSame(2, Product::search('')->extended('pro !zzzbanned')->paginate(10)->total());
    }

    /** Only a negated predicate changes: the SQL of a query without ! has no COALESCE. */
    public function test_sql_without_a_negation_is_unchanged(): void
    {
        foreach (['john', '=john', '^jo', 'doe$', "'john", '~jonh', 'name:john', 'john | alice', '(john | jane) doe', 'email:^jo name:doe$'] as $query) {
            $this->assertStringNotContainsStringIgnoringCase('coalesce', User::search('')->extended($query)->toSql(), $query);
        }

        foreach (['john !doe', '!=john', '!^jo', '!doe$', "!'john", '!name:john'] as $query) {
            $this->assertStringContainsStringIgnoringCase('coalesce', User::search('')->extended($query)->toSql(), $query);
        }
    }
}
