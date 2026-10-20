<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Exceptions\LaravelFuzzySearchException;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * With indexing.async off, the index is written after the save's transaction commits. A failure
 * there (a deadlock, a lock-wait timeout) must not reach the caller, whose write has already
 * committed, nor stop the application's own after-commit callbacks: it is reported, naming the
 * model and key, and the save goes on. IndexModelJob::dispatch() of that key repairs the index.
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
            public ?\Throwable $failure;
            public function syncModel(string $modelClass, int|string $key): void
            {
                if ($this->failure) {
                    throw $this->failure;
                }
                parent::syncModel($modelClass, $key);
            }
        });

        app(IndexManager::class)->failure = new \RuntimeException('index write failed');

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
        // A delete syncs the row as a save does, and fails the same way (SB-2).
        $this->assertSame(['index write failed', 'index write failed'], array_map(fn ($e) => $e->getPrevious()?->getMessage(), $this->reported));
        $this->assertSame(0, User::where('name', 'John Changed')->count()); // the delete committed too
    }

    public function test_outside_a_transaction_the_save_returns_normally(): void
    {
        $john = User::where('name', 'John Doe')->first();

        $this->assertTrue($john->update(['name' => 'John Changed']));
        $this->assertCount(1, $this->reported);
    }

    /**
     * P7W-2. A failed write was reported as the database's own error, which names neither the model
     * nor the key. A plain fuzzy-search:rebuild reads only the rows the model's query returns, so
     * it leaves a deleted row in the index, counted by count() and total() and taking a slot on a
     * page. The report names both, and IndexModelJob of that key removes the row.
     */
    public function test_a_failed_removal_is_reported_with_its_model_and_key_and_its_job_repairs_it(): void
    {
        $manager          = app(IndexManager::class);
        $manager->failure = null;
        $manager->indexBatch(User::all());
        $john     = User::where('name', 'John Doe')->first();
        $document = fn () => DB::table('fuzzy_index_documents')->where('model_id', (string) $john->getKey())->count();
        $this->assertSame(1, $document());

        $manager->failure = new \Illuminate\Database\DeadlockException('Deadlock found when trying to get lock');
        $john->delete();

        $this->assertCount(1, $this->reported);
        $e = $this->reported[0];
        $this->assertInstanceOf(LaravelFuzzySearchException::class, $e);
        $this->assertSame(['model' => User::class, 'key' => $john->getKey()], $e->context(), 'Laravel\'s handler logs it');
        $this->assertStringContainsString('Indexing ' . User::class . ' ' . $john->getKey() . ' failed: Deadlock found', $e->getMessage());
        $this->assertStringContainsString('IndexModelJob::dispatch()', $e->getMessage());
        $this->assertSame($manager->failure, $e->getPrevious());
        $this->assertSame(1, $document(), 'the deleted row is still in the index');

        $manager->failure = null;
        IndexModelJob::dispatchSync($e->context()['model'], $e->context()['key']);

        $this->assertSame(0, $document());
    }
}
