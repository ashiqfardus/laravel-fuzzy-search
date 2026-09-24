<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Two index writes for one model must not overlap: both read the row as not yet indexed and
 * both added it to doc_count and total_docs. IndexManager claims and locks the model's document
 * row first, in the same transaction as the write, so IndexModelJob, the Scout engine's update()
 * and the rebuild batches all wait for each other on the database, never on a cache lock.
 *
 * On SQLite the database is a file here, so two processes can share it.
 */
class IndexModelJobOverlapTest extends TestCase
{
    private ?string $file = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($app['config']->get('database.default') === 'testing') {
            $this->file = sys_get_temp_dir() . '/fuzzy-race-' . getmypid() . '-' . uniqid() . '.sqlite';
            touch($this->file);
            $app['config']->set('database.connections.testing.database', $this->file);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->file !== null) {
            @unlink($this->file);
        }
    }

    private function snapshot(): array
    {
        return [
            'postings'  => DB::table('fuzzy_index_postings')->where('model_type', User::class)->orderBy('term_id')->orderBy('column_name')->get(['term_id', 'model_id', 'column_name', 'frequency'])->map(fn ($r) => (array) $r)->all(),
            'doc_count' => DB::table('fuzzy_index_terms')->orderBy('term')->pluck('doc_count', 'term')->map(fn ($c) => (int) $c)->all(),
            'meta'      => (array) DB::table('fuzzy_index_meta')->where('model_type', User::class)->first(['total_docs', 'total_tokens']),
        ];
    }

    public function test_running_the_job_twice_leaves_the_index_as_one_run_does(): void
    {
        $user = User::where('name', 'John Doe')->first();

        (new IndexModelJob(User::class, $user->getKey()))->handle(app(IndexManager::class));
        $once = $this->snapshot();

        (new IndexModelJob(User::class, $user->getKey()))->handle(app(IndexManager::class));
        $this->assertSame($once, $this->snapshot());
        $this->assertSame(1, (int) $once['meta']['total_docs']);

        // The Scout engine and a rebuild batch write the same state.
        app(FuzzySearchEngine::class)->update(User::whereKey($user->getKey())->get());
        app(IndexManager::class)->indexBatch(User::whereKey($user->getKey())->get());
        $this->assertSame($once, $this->snapshot());
    }

    public static function concurrentWriters(): array
    {
        return ['IndexModelJob' => ['job'], 'Scout update()' => ['scout'], 'rebuild batch' => ['batch']];
    }

    /**
     * The race itself, in two processes: each write pauses after its first read of the document
     * row, so both read "not yet indexed" unless the second waits for the first.
     */
    #[DataProvider('concurrentWriters')]
    public function test_a_concurrent_write_for_the_same_model_counts_it_once(string $writer): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            $this->markTestSkipped('The race needs two processes: the pcntl and posix extensions.');
        }

        // The child's own connection to the same database: the parent's socket is not shared.
        config(['database.connections.race' => config('database.connections.' . config('database.default'))]);

        $paused = false;
        DB::listen(function ($query) use (&$paused) {
            if (!$paused && preg_match('/^\s*select\b/i', $query->sql) && str_contains($query->sql, 'fuzzy_index_documents')) {
                $paused = true;
                usleep(600_000); // after the "already indexed?" read, before any write
            }
        });

        $id     = User::where('name', 'John Doe')->value('id');
        $failed = sys_get_temp_dir() . '/fuzzy-race-child-' . getmypid() . '-' . uniqid();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            try {
                DB::setDefaultConnection('race');
                $models = User::whereKey($id)->get();
                match ($writer) {
                    'job'   => (new IndexModelJob(User::class, $id))->handle(app(IndexManager::class)),
                    'scout' => app(FuzzySearchEngine::class)->update($models),
                    'batch' => app(IndexManager::class)->indexBatch($models),
                };
            } catch (\Throwable $e) {
                file_put_contents($failed, (string) $e);
            } finally {
                posix_kill(getmypid(), SIGKILL); // no destructors, no PHPUnit shutdown: they belong to the parent
            }
        }

        usleep(50_000);
        (new IndexModelJob(User::class, $id))->handle(app(IndexManager::class));
        pcntl_waitpid($pid, $status);

        $childError = is_file($failed) ? file_get_contents($failed) : null;
        @unlink($failed);

        $this->assertNull($childError);
        $this->assertSame(1, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));
        $this->assertSame(1, DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
    }
}
