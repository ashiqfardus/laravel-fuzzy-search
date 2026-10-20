<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * fuzzy-search:benchmark and :explain run real searches for an operator: they leave no analytics
 * rows behind, time the search and not the cache, restore both settings, and report an unknown
 * algorithm or an empty term as a failed command instead of a stack trace.
 */
class BenchmarkCommandSideEffectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['fuzzy-search.analytics.enabled' => true, 'fuzzy-search.analytics.queue' => null, 'fuzzy-search.cache.enabled' => true]);
    }

    private function analyticsRows(): int
    {
        return DB::table(config('fuzzy-search.analytics.table', 'fuzzy_search_logs'))->count();
    }

    public function test_benchmark_records_no_analytics_and_times_the_search_not_the_cache(): void
    {
        $before = $this->analyticsRows();
        $reads  = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads) {
            if (stripos($query->sql, 'users') !== false) {
                $reads++;
            }
        });

        $this->artisan('fuzzy-search:benchmark', ['model' => User::class, '--iterations' => 5, '--term' => 'john'])->assertExitCode(0);

        $this->assertSame($before, $this->analyticsRows(), 'no analytics row per iteration');
        $this->assertGreaterThanOrEqual(5, $reads, 'every iteration reads the database, none is a cache hit');
        $this->assertTrue(config('fuzzy-search.analytics.enabled'), 'analytics is restored');
        $this->assertTrue(config('fuzzy-search.cache.enabled'), 'the cache is restored');
    }

    public function test_explain_records_no_analytics(): void
    {
        $before = $this->analyticsRows();

        $this->artisan('fuzzy-search:explain', ['model' => User::class, '--term' => 'john'])->assertExitCode(0);

        $this->assertSame($before, $this->analyticsRows());
        $this->assertTrue(config('fuzzy-search.analytics.enabled'));
    }

    public function test_an_unknown_algorithm_or_an_empty_term_fails_with_a_message(): void
    {
        foreach (['fuzzy-search:benchmark', 'fuzzy-search:explain'] as $command) {
            $this->artisan($command, ['model' => User::class, '--algorithm' => 'nope', '--term' => 'john'])
                ->expectsOutputToContain('nope')
                ->assertExitCode(1);
            $this->artisan($command, ['model' => User::class, '--term' => ''])
                ->assertExitCode(1);
        }

        $this->assertTrue(config('fuzzy-search.analytics.enabled'), 'restored after a failure too');
        $this->assertTrue(config('fuzzy-search.cache.enabled'));
    }
}
