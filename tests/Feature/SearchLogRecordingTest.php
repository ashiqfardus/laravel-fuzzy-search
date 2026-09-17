<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Jobs\RecordSearchLogJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class SearchLogRecordingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('fuzzy-search.analytics', [
            'enabled'        => true,
            'queue'          => null,
            'sample_rate'    => 1.0,
            'retention_days' => 30,
            'hash_terms'     => false,
            'table'          => 'fuzzy_search_logs',
        ]);
    }

    public function test_an_enabled_search_writes_one_row(): void
    {
        $count = User::search('john')->get()->count(); // the one and only search this test runs

        $row = DB::table('fuzzy_search_logs')->first();
        $this->assertNotNull($row);
        $this->assertSame('john', $row->term);
        $this->assertSame('john', $row->normalized_term);
        $this->assertSame(User::class, $row->model_type);
        $this->assertSame('like', $row->path);
        $this->assertSame($count, (int) $row->result_count);
        $this->assertSame(now()->toDateString(), (string) $row->day);
        $this->assertGreaterThanOrEqual(0, (float) $row->latency_ms);
        $this->assertSame(1, DB::table('fuzzy_search_logs')->count());
    }

    public function test_disabled_analytics_writes_nothing(): void
    {
        config(['fuzzy-search.analytics.enabled' => false]);

        User::search('john')->get();

        $this->assertSame(0, DB::table('fuzzy_search_logs')->count());
    }

    public function test_a_zero_sample_rate_writes_nothing_and_one_writes_everything(): void
    {
        config(['fuzzy-search.analytics.sample_rate' => 0]);
        User::search('john')->get();
        $this->assertSame(0, DB::table('fuzzy_search_logs')->count());

        config(['fuzzy-search.analytics.sample_rate' => 1]);
        User::search('john')->get();
        User::search('jane')->get();
        $this->assertSame(2, DB::table('fuzzy_search_logs')->count());
    }

    public function test_the_normalized_term_folds_case_and_whitespace(): void
    {
        User::search("  JoHn   Doe ")->get();

        $this->assertSame('john doe', DB::table('fuzzy_search_logs')->value('normalized_term'));
    }

    public function test_hashed_terms_store_no_plain_text(): void
    {
        config(['fuzzy-search.analytics.hash_terms' => true]);

        User::search('john')->get();

        $row = DB::table('fuzzy_search_logs')->first();
        $this->assertSame('', $row->term);
        $this->assertSame(hash('sha256', 'john'), $row->normalized_term);
    }

    public function test_a_configured_queue_dispatches_the_job_instead_of_inserting(): void
    {
        Bus::fake();
        config(['fuzzy-search.analytics.queue' => 'analytics']);

        User::search('john')->get();

        Bus::assertDispatched(RecordSearchLogJob::class, fn (RecordSearchLogJob $job) => $job->queue === 'analytics' && $job->row['normalized_term'] === 'john');
        $this->assertSame(0, DB::table('fuzzy_search_logs')->count());
    }

    public function test_the_job_inserts_the_row(): void
    {
        (new RecordSearchLogJob(['term' => 'x', 'normalized_term' => 'x', 'model_type' => null, 'algorithm' => 'fuzzy', 'path' => 'like', 'result_count' => 0, 'latency_ms' => 1.2, 'day' => now()->toDateString(), 'created_at' => now()]))->handle();

        $this->assertSame(1, DB::table('fuzzy_search_logs')->count());
    }

    public function test_zero_result_and_extended_searches_are_logged_with_their_path(): void
    {
        User::search('zzzz')->get();
        User::search('x')->extended('name:john')->get();

        $this->assertSame(0, (int) DB::table('fuzzy_search_logs')->where('term', 'zzzz')->value('result_count'));
        $this->assertSame('extended', DB::table('fuzzy_search_logs')->where('term', 'name:john')->value('path'));
    }
}
