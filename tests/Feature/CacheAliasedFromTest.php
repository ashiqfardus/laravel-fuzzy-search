<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A cache hit re-read models by key through the model's qualified key ("users"."id"), which a FROM
 * under an alias or a subquery does not have: the miss worked, and every hit after it threw until
 * the entry expired. Such rows are now cached as their attributes.
 *
 * The keyed re-read has worked under an alias since ER-107, so the first two tests pass either way.
 * The rule still decides the last one: a fromSub() whose subquery joins one-to-many holds several
 * rows for a key, a re-read returns all of them, and a hit served the last (omega) where the miss
 * served the first (alpha).
 */
class CacheAliasedFromTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('cache_notes');
        parent::tearDown();
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

    /** @return array<string, array{0: \Closure}> */
    public static function oneRowTerminals(): array
    {
        return [
            'take(1)->get()' => [fn (SearchBuilder $search) => $search->take(1)->get()],
            'first()'        => [fn (SearchBuilder $search) => collect([$search->first()])],
        ];
    }

    #[DataProvider('oneRowTerminals')]
    public function test_a_hit_on_a_from_subquery_joining_one_to_many_returns_the_miss_row(\Closure $terminal): void
    {
        Schema::dropIfExists('cache_notes');
        Schema::create('cache_notes', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('body');
        });
        $john = DB::table('users')->where('name', 'John Doe')->value('id');
        DB::table('cache_notes')->insert([['user_id' => $john, 'body' => 'alpha'], ['user_id' => $john, 'body' => 'omega']]);

        $run = fn () => $terminal((new SearchBuilder(
            User::query()->fromSub(DB::table('users')->join('cache_notes', 'cache_notes.user_id', '=', 'users.id')->select('users.*', 'cache_notes.body as note'), 'u')->orderBy('note'),
            app(FuzzySearch::class)
        ))->search('john doe')->searchIn(['name'])->using('like')->cache(60))
            ->map(fn ($user) => $user->name . '/' . $user->note)->all();

        $miss = $run();

        $this->assertSame(['John Doe/alpha'], $miss);
        $this->assertSame($miss, $run());
    }
}
