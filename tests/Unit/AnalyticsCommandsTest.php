<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // One instant for the whole test: the fixtures and the assertions both read now(),
        // and a run that straddles midnight would otherwise compare different days.
        Carbon::setTestNow(now());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function log(string $term, int $results, string $path = 'like', int $daysAgo = 0): void
    {
        $at = now()->subDays($daysAgo);
        DB::table('fuzzy_search_logs')->insert([
            'term' => $term, 'normalized_term' => SearchAnalytics::normalize($term), 'model_type' => null,
            'algorithm' => 'fuzzy', 'path' => $path, 'result_count' => $results, 'latency_ms' => 12.5,
            'day' => $at->toDateString(), 'created_at' => $at,
        ]);
    }

    public function test_analytics_prints_popular_terms_and_latency(): void
    {
        $this->log('john', 3);
        $this->log('john', 1);
        $this->log('jane', 0);

        $this->artisan('fuzzy-search:analytics', ['--days' => 7])
            ->assertSuccessful()
            ->expectsOutputToContain('Popular searches (last 7 days)')
            ->expectsOutputToContain('Average latency by path (ms)')
            ->run();
    }

    public function test_zero_results_flag_lists_only_zero_result_terms(): void
    {
        $this->log('nothing', 0);
        $this->log('john', 2);

        $this->artisan('fuzzy-search:analytics', ['--zero-results' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Zero-result searches (last 30 days)')
            ->doesntExpectOutputToContain('Popular searches')
            ->run();
    }

    public function test_analytics_says_so_when_there_is_nothing_to_report(): void
    {
        $this->artisan('fuzzy-search:analytics')
            ->assertSuccessful()
            ->expectsOutputToContain('No searches recorded in the last 30 days')
            ->run();
    }

    public function test_prune_reports_the_deleted_count_and_honours_days(): void
    {
        $this->log('old', 1, 'like', 45);
        $this->log('older', 1, 'like', 60);
        $this->log('new', 1, 'like', 1);

        $this->artisan('fuzzy-search:analytics:prune')
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 2 search log row(s) older than 30 days')
            ->run();
        $this->assertSame(1, DB::table('fuzzy_search_logs')->count());

        $this->artisan('fuzzy-search:analytics:prune', ['--days' => 0])
            ->assertSuccessful()
            ->expectsOutputToContain('Deleted 1 search log row(s) older than 0 days')
            ->run();
    }
}
