<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * A cache hit re-read models by key through the model's qualified key ("users"."id"), which a FROM
 * under an alias or a subquery does not have: the miss worked, and every hit after it threw until
 * the entry expired. Such rows are now cached as their attributes.
 */
class CacheAliasedFromTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    /** @return array{0: string[], 1: string[]} the miss's rows, then the hit's */
    private function missThenHit(\Closure $query): array
    {
        $run = fn () => (new SearchBuilder($query(), app(FuzzySearch::class)))
            ->search('doe')->searchIn(['name'])->using('like')->cache(60)->get()
            ->map(fn ($user) => $user->name . '/' . $user->email)->sort()->values()->all();

        return [$run(), $run()];
    }

    public function test_a_hit_on_an_aliased_from_returns_the_miss_rows(): void
    {
        [$miss, $hit] = $this->missThenHit(fn () => User::query()->from('users as u'));

        $this->assertSame(['Jane Doe/jane@example.com', 'John Doe/john@example.com'], $miss);
        $this->assertSame($miss, $hit);
    }

    public function test_a_hit_on_a_from_subquery_returns_the_miss_rows(): void
    {
        [$miss, $hit] = $this->missThenHit(fn () => User::query()->fromSub(DB::table('users')->where('email', 'like', '%@example.com'), 'u'));

        $this->assertSame(['Jane Doe/jane@example.com', 'John Doe/john@example.com'], $miss);
        $this->assertSame($miss, $hit);
    }
}
