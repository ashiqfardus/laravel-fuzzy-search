<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class FromQueryNotForwardedTest extends TestCase
{
    public function test_from_query_is_not_forwarded(): void
    {
        $this->expectException(\BadMethodCallException::class);

        User::search('john')->fromQuery('select * from users');
    }

    public function test_the_other_from_methods_still_chain(): void
    {
        $this->assertNotEmpty(User::search('john')->fromRaw('users')->searchIn(['name'])->using('like')->get());
    }
}
