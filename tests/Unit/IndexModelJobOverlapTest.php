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
     * Run $child in a forked process (its own connection to the same database) and $parent here,
     * $delay microseconds later. Each process pauses once, $pause microseconds, after its first
     * statement matching $at. Returns [the child's error, the parent's error, the parent's seconds].
     *
     * @return array{0: ?string, 1: ?string, 2: float}
     */
    private function race(\Closure $child, \Closure $parent, string $at, int $pause, int $delay): array
    {
        // function_exists(), not extension_loaded(): disable_functions can remove them too.
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
            $this->markTestSkipped('The race needs two processes: pcntl_fork(), pcntl_waitpid() and posix_kill().');
        }

        // The child's own connection to the same database: the parent's socket is not shared.
        config(['database.connections.race' => config('database.connections.' . config('database.default'))]);

        $paused = false;
        DB::listen(function ($query) use (&$paused, $at, $pause) {
            if (!$paused && preg_match($at, $query->sql)) {
                $paused = true;
                usleep($pause);
            }
        });

        $failed = sys_get_temp_dir() . '/fuzzy-race-child-' . getmypid() . '-' . uniqid();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            try {
                DB::setDefaultConnection('race');
                $child();
            } catch (\Throwable $e) {
                file_put_contents($failed, (string) $e);
            } finally {
                posix_kill(getmypid(), SIGKILL); // no destructors, no PHPUnit shutdown: they belong to the parent
            }
        }

        usleep($delay);
        $started     = microtime(true);
        $parentError = null;
        try {
            $parent();
        } catch (\Throwable $e) {
            $parentError = (string) $e;
        }
        $elapsed = microtime(true) - $started;
        pcntl_waitpid($pid, $status);

        $childError = is_file($failed) ? file_get_contents($failed) : null;
        @unlink($failed);

        return [$childError, $parentError, $elapsed];
    }

    /**
     * The race itself, in two processes: each write pauses after its first read of the document
     * row, so both read "not yet indexed" unless the second waits for the first.
     */
    #[DataProvider('concurrentWriters')]
    public function test_a_concurrent_write_for_the_same_model_counts_it_once(string $writer): void
    {
        $id = User::where('name', 'John Doe')->value('id');

        [$childError, $parentError] = $this->race(
            function () use ($writer, $id) {
                $models = User::whereKey($id)->get();
                match ($writer) {
                    'job'   => (new IndexModelJob(User::class, $id))->handle(app(IndexManager::class)),
                    'scout' => app(FuzzySearchEngine::class)->update($models),
                    'batch' => app(IndexManager::class)->indexBatch($models),
                };
            },
            fn () => (new IndexModelJob(User::class, $id))->handle(app(IndexManager::class)),
            '/^\s*select\b.*fuzzy_index_documents/is', // after the "already indexed?" read, before any write
            600_000,
            50_000,
        );

        $this->assertNull($childError);
        $this->assertNull($parentError);
        $this->assertSame(1, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));
        $this->assertSame(1, DB::table('fuzzy_index_documents')->where('model_type', User::class)->count());
    }

    public static function differentRowShapes(): array
    {
        return [
            'paused after the claim, first index'       => ['select', false],
            'paused after the claim, re-index'          => ['select', true],
            'paused between upsert and read, first index' => ['insert', false],
            'paused between upsert and read, re-index'  => ['insert', true],
        ];
    }

    /**
     * Writes for different rows of one model must not wait on each other's claim, nor deadlock.
     * An integer key bound against the varchar model_id made MySQL compare numerically, scan the
     * model type's whole key prefix under the claim's FOR UPDATE and lock every document row
     * of it: a second row's write waited, and two claims crossing deadlocked (1213).
     * SQLite takes one write lock for the whole database, so there only the absence of errors
     * is checked.
     */
    #[DataProvider('differentRowShapes')]
    public function test_writes_for_different_rows_neither_wait_on_each_other_nor_deadlock(string $pauseAt, bool $reindex): void
    {
        $john = User::where('name', 'John Doe')->value('id');
        $jane = User::where('name', 'Jane Doe')->value('id');
        if ($reindex) {
            app(IndexManager::class)->indexBatch(User::all());
        }

        $at = $pauseAt === 'select'
            ? '/^\s*select\b.*fuzzy_index_documents/is'           // the claim's FOR UPDATE read
            : '/^\s*(insert|merge)\b.*fuzzy_index_documents/is';   // the claim's placeholder upsert

        [$childError, $parentError, $elapsed] = $this->race(
            fn () => (new IndexModelJob(User::class, $john))->handle(app(IndexManager::class)),
            fn () => (new IndexModelJob(User::class, $jane))->handle(app(IndexManager::class)),
            $at,
            1_000_000,
            300_000,
        );

        $this->assertNull($childError);
        $this->assertNull($parentError);
        if ($this->dbDriver !== 'sqlite') {
            // Its own 1 s pause, plus the work: not the child's remaining 0.7 s on top.
            $this->assertLessThan(1.5, $elapsed);
        }
        $this->assertSame($reindex ? 7 : 2, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
    }

    /** Every model_id the indexer binds is a string: an integer against the varchar column cannot use its key. */
    public function test_the_indexer_binds_model_ids_as_strings(): void
    {
        $id = 987654; // an unsaved model: a key no count, length or term id can equal
        $user = (new User(['name' => 'Binding Probe', 'email' => 'probe@example.com']))->forceFill(['id' => $id]);

        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            array_push($bindings, ...$query->bindings);
        });

        app(IndexManager::class)->indexModel($user);
        app(IndexManager::class)->indexBatch(collect([$user]));
        app(IndexManager::class)->removeFromIndex(User::class, $id);

        $this->assertContains((string) $id, $bindings);
        $this->assertNotContains($id, $bindings);
    }
}
