<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Facades\SearchAnalytics as Facade;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../TestModels.php';

class SearchAnalyticsTest extends TestCase
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

    private function log(string $term, int $results, string $path = 'like', float $latency = 10.0, int $daysAgo = 0): void
    {
        $at = now()->subDays($daysAgo);
        DB::table('fuzzy_search_logs')->insert([
            'term' => $term, 'normalized_term' => SearchAnalytics::normalize($term), 'model_type' => null,
            'algorithm' => 'fuzzy', 'path' => $path, 'result_count' => $results, 'latency_ms' => $latency,
            'day' => $at->toDateString(), 'created_at' => $at,
        ]);
    }

    public function test_enabling_analytics_after_boot_records_searches(): void
    {
        // TestCase boots with analytics.enabled = false; the listener must still be there.
        config(['fuzzy-search.analytics.enabled' => true]);

        \Ashiqfardus\LaravelFuzzySearch\Tests\User::search('john')->get();

        $this->assertSame('john', DB::table('fuzzy_search_logs')->value('term'));
    }

    public function test_popular_groups_by_normalized_term_and_orders_by_count(): void
    {
        $this->log('John', 3);
        $this->log('john ', 4); // 3 and 4 average to 3.5 — an integer AVG would report 3
        $this->log('jane', 0);

        $rows = SearchAnalytics::popular(30, 10);

        $this->assertSame('john', $rows[0]['term']);
        $this->assertSame(2, $rows[0]['searches']);
        $this->assertEqualsWithDelta(3.5, $rows[0]['avg_results'], 0.001);
        $this->assertSame('jane', $rows[1]['term']);
        $this->assertCount(2, $rows);
        $this->assertCount(1, SearchAnalytics::popular(30, 1));
    }

    public function test_zero_results_lists_only_terms_that_never_matched(): void
    {
        $this->log('nothing', 0);
        $this->log('nothing', 0);
        $this->log('john', 0);
        $this->log('john', 2);

        $rows = SearchAnalytics::zeroResults(30, 10);

        $this->assertSame([['term' => 'nothing', 'searches' => 2]], $rows);
    }

    public function test_average_latency_is_grouped_by_path(): void
    {
        $this->log('a', 1, 'like', 10.0);
        $this->log('b', 1, 'like', 30.0);
        $this->log('c', 1, 'bm25', 4.0);

        $avg = SearchAnalytics::averageLatency(30);

        $this->assertEqualsWithDelta(20.0, $avg['like'], 0.001);
        $this->assertEqualsWithDelta(4.0, $avg['bm25'], 0.001);
        $this->assertArrayNotHasKey('extended', $avg);
    }

    public function test_volume_is_grouped_per_day_ascending_and_windowed(): void
    {
        $this->log('a', 1, 'like', 1.0, 0);
        $this->log('b', 1, 'like', 1.0, 0);
        $this->log('c', 1, 'like', 1.0, 1);
        $this->log('old', 1, 'like', 1.0, 40);

        $volume = SearchAnalytics::volume(30);

        $this->assertSame([now()->subDay()->toDateString() => 1, now()->toDateString() => 2], $volume);
    }

    public function test_prune_deletes_rows_older_than_retention(): void
    {
        $this->log('keep', 1, 'like', 1.0, 5);
        $this->log('drop', 1, 'like', 1.0, 45);

        $this->assertSame(1, SearchAnalytics::prune());   // "drop" (45 days) goes, retention_days = 30
        $this->assertSame(1, SearchAnalytics::prune(3));  // "keep" (5 days) goes with an explicit 3-day window
        $this->assertSame(0, DB::table('fuzzy_search_logs')->count());
    }

    public function test_the_facade_resolves(): void
    {
        $this->log('john', 1);

        $this->assertSame('john', Facade::popular()[0]['term']);
    }
}
