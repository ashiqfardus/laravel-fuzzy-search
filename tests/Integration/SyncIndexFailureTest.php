<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * With indexing.async off, the index is written after the save's transaction commits. A failure
 * there (a deadlock, a lock-wait timeout) must not reach the caller, whose write has already
 * committed, nor stop the application's own after-commit callbacks: it is reported and the save
 * goes on. `fuzzy-search:rebuild` repairs the index.
 */
class SyncIndexFailureTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        $this->app->instance(IndexManager::class, new class(new WhitespaceTokenizer(), new NullStemmer()) extends IndexManager {
            public function syncModel(string $modelClass, int|string $key): void { throw new \RuntimeException('index write failed'); }
            public function removeFromIndex(string $modelType, int|string $modelId): void { throw new \RuntimeException('index delete failed'); }
        });

        $reported = &$this->reported;
        $this->app->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler {
            public function __construct(private array &$reported) {}
            public function report(\Throwable $e) { $this->reported[] = $e; }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw $e; }
            public function renderForConsole($output, \Throwable $e) { throw $e; }
        });
    }

    public function test_an_index_failure_after_the_commit_is_reported_and_the_apps_callbacks_still_run(): void
    {
        $ran  = [];
        $john = User::where('name', 'John Doe')->first();

        DB::transaction(function () use ($john, &$ran) {
            $john->update(['name' => 'John Changed']);
            DB::afterCommit(function () use (&$ran) { $ran[] = 'saved'; });
        });

        DB::transaction(function () use ($john, &$ran) {
            $john->delete();
            DB::afterCommit(function () use (&$ran) { $ran[] = 'deleted'; });
        });

        $this->assertSame(['saved', 'deleted'], $ran);
        $this->assertSame(['index write failed', 'index delete failed'], array_map(fn ($e) => $e->getMessage(), $this->reported));
        $this->assertSame(0, User::where('name', 'John Changed')->count()); // the delete committed too
    }

    public function test_outside_a_transaction_the_save_returns_normally(): void
    {
        $john = User::where('name', 'John Doe')->first();

        $this->assertTrue($john->update(['name' => 'John Changed']));
        $this->assertCount(1, $this->reported);
    }
}
