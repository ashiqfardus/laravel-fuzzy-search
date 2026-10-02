<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

/**
 * Under an app-wide array fetch mode (a StatementPrepared listener setting PDO::FETCH_ASSOC) the LIKE
 * path works, and so must its cache hit: a hit re-reads the cached models by key through
 * RankedCandidates::models(), which took each row as an object and threw "Attempt to read property
 * on array".
 */
class ArrayFetchModeCacheHitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Event::forget(StatementPrepared::class); // the teardown's migrator reads objects

        parent::tearDown();
    }

    public function test_a_cache_hit_of_a_like_search_reads_under_an_array_fetch_mode(): void
    {
        $expected = User::search('doe')->using('like')->get()->pluck('name')->sort()->values()->all();
        $this->assertNotSame([], $expected);

        Event::listen(StatementPrepared::class, fn (StatementPrepared $event) => $event->statement->setFetchMode(\PDO::FETCH_ASSOC));
        $run = fn () => User::search('doe')->using('like')->cache(60)->get()->pluck('name')->sort()->values()->all();

        $this->assertSame($expected, $run(), 'the miss');
        $this->assertSame($expected, $run(), 'the hit');
    }
}
