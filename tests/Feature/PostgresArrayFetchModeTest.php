<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Support\Facades\Event;

/**
 * TC-3. Under an app-wide array fetch mode (a StatementPrepared listener setting PDO::FETCH_ASSOC)
 * selectOne() returns an array. Three PostgreSQL reads took its row as an object: the result-cache
 * key's search_path (every cached LIKE search threw "Attempt to read property on array"), and the
 * post-write statistics check's search_path and pg_class row, and analyzeIndex()'s page count (each
 * index write, and the end of rebuild --fresh, reported that error and skipped its ANALYZE). They now
 * read the value whatever the fetch mode.
 */
class PostgresArrayFetchModeTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('The search_path and pg_class reads are PostgreSQL-only; the CI PostgreSQL jobs run this.');
        }

        $reported = &$this->reported;
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler {
            public function __construct(private array &$reported) {}
            public function report(\Throwable $e) { $this->reported[] = $e; }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw $e; }
            public function renderForConsole($output, \Throwable $e) { throw $e; }
        });
    }

    protected function tearDown(): void
    {
        Event::forget(StatementPrepared::class); // Laravel's migrator, in the teardown, reads objects too

        parent::tearDown();
    }

    private function arrayFetchMode(): void
    {
        Event::listen(StatementPrepared::class, fn (StatementPrepared $event) => $event->statement->setFetchMode(\PDO::FETCH_ASSOC));
    }

    public function test_a_cached_like_search_reads_under_an_array_fetch_mode(): void
    {
        $expected = User::search('john')->get()->pluck('name')->all();
        $this->arrayFetchMode();

        $this->assertSame($expected, User::search('john')->cache(10)->get()->pluck('name')->all(), '->cache()');

        config(['fuzzy-search.cache.enabled' => true]);
        $this->assertSame($expected, User::search('john')->get()->pluck('name')->all(), 'cache.enabled');
    }

    public function test_an_index_write_and_analyze_report_nothing_under_an_array_fetch_mode(): void
    {
        $this->arrayFetchMode();

        foreach (User::all() as $user) {
            app(IndexManager::class)->indexModel($user); // a new row: the post-write statistics check reads pg_class
        }
        app(IndexManager::class)->analyzeIndex(); // the end of rebuild --fresh

        $this->assertSame([], array_map(fn (\Throwable $e) => $e->getMessage(), $this->reported));
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('fuzzy_index_postings')->count());
    }
}
