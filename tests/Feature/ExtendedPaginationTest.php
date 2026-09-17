<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class ExtendedPaginationTest extends TestCase
{
    private function builder()
    {
        return User::search('o')->extended("'o");
    }

    public function test_pages_partition_the_extended_result_set(): void
    {
        $all   = $this->builder()->get()->pluck('id')->all();
        $total = count($all);
        $this->assertGreaterThanOrEqual(5, $total);

        $seen = [];
        for ($page = 1; $page <= (int) ceil($total / 2); $page++) {
            $p = $this->builder()->paginate(2, 'page', $page);
            $this->assertSame($total, $p->total());
            $seen = array_merge($seen, $p->pluck('id')->all());
        }

        sort($seen);
        sort($all);
        $this->assertSame($all, $seen); // no duplicates, no gaps across pages
    }

    public function test_simple_paginate_and_count_agree_with_get(): void
    {
        $total = $this->builder()->get()->count();

        $this->assertSame($total, $this->builder()->count());
        $this->assertTrue($this->builder()->simplePaginate(2)->hasMorePages());
        $this->assertCount(2, $this->builder()->simplePaginate(2)->items());
    }

    public function test_new_operators_paginate_too(): void
    {
        $query = fn () => User::search('jonh')->extended('name:~jonh | email:^bob');
        $names = $query()->get()->pluck('name')->all();
        $p     = $query()->paginate(1);

        $this->assertContains('John Doe', $names);    // name:~jonh (typo)
        $this->assertContains('Bob Johnson', $names); // email:^bob (prefix)
        $this->assertSame(count($names), $p->total()); // whatever else the typo driver admits, the total agrees with get()
        $this->assertCount(1, $p->items());
    }

    public function test_use_inverted_index_does_not_hijack_extended_count_or_pagination(): void
    {
        $builder = fn () => User::search('john')->extended('email:^bob')->useInvertedIndex();

        $this->assertSame(['Bob Johnson'], $builder()->get()->pluck('name')->all());
        $this->assertSame(1, $builder()->count());
        $this->assertSame(1, $builder()->paginate(5)->total());
        $this->assertSame(['Bob Johnson'], $builder()->paginate(5)->pluck('name')->all());
        $this->assertSame(['Bob Johnson'], $builder()->simplePaginate(5)->pluck('name')->all());
    }
}
