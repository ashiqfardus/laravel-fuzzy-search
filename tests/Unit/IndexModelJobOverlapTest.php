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
    private function race(\Closure $child, \Closure $parent, string $at, int $pause, int $delay, bool $pauseParent = true): array
    {
        // function_exists(), not extension_loaded(): disable_functions can remove them too.
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
            $this->markTestSkipped('The race needs two processes: pcntl_fork(), pcntl_waitpid() and posix_kill().');
        }

        // The child's own connection to the same database: the parent's socket is not shared.
        config(['database.connections.race' => config('database.connections.' . config('database.default'))]);

        $paused = false;
        $listen = fn () => DB::listen(function ($query) use (&$paused, $at, $pause) {
            if (!$paused && preg_match($at, $query->sql)) {
                $paused = true;
                usleep($pause);
            }
        });
        if ($pauseParent) {
            $listen();
        }

        $failed = sys_get_temp_dir() . '/fuzzy-race-child-' . getmypid() . '-' . uniqid();

        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->markTestSkipped('pcntl_fork() failed.');
        }

        if ($pid === 0) {
            try {
                DB::setDefaultConnection('race');
                if (!$pauseParent) {
                    $listen();
                }
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

    /**
     * A job that read the row before a newer save must not leave the older text indexed when it
     * commits after that save's own index write (ER-68). The child job pauses right after its
     * read of the row; the parent saves new text meanwhile, with sync indexing. The row is read
     * under the claim now, so the parent's index write waits for the child, then re-reads the row.
     */
    public function test_a_job_that_read_the_row_before_a_newer_save_leaves_the_newer_text_indexed(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $john = User::where('name', 'John Doe')->first();

        [$childError, $parentError] = $this->race(
            fn () => (new IndexModelJob(User::class, $john->id))->handle(app(IndexManager::class)),
            fn () => $john->update(['name' => 'John Waited']),
            '/^\s*select\b.*\busers\b/is', // the job's read of the row
            800_000,
            300_000,
            pauseParent: false,
        );

        $this->assertNull($childError);
        $this->assertNull($parentError);
        $terms = DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', User::class)->where('p.model_id', (string) $john->id)->where('p.column_name', 'name')
            ->orderBy('t.term')->pluck('t.term')->all();
        $this->assertSame(['john', 'waited'], $terms);
    }

    public static function crossedTerms(): array
    {
        return [
            // Each row's new word is the other row's old one, all in the dictionary already.
            'words in the dictionary, texts swapped' => [
                [['alpha', 'qa'], ['bravo', 'qb']], [['bravo', 'qa'], ['alpha', 'qb']],
                '/^\s*update\b.*fuzzy_index_terms/is', // the first change to the dictionary
                ['alpha' => 1, 'bravo' => 1],
            ],
            // First index, every word new to the dictionary.
            'first index, new words' => [
                null, [['kilo lima', 'mike'], ['lima kilo', 'november']],
                '/^\s*(insert|merge)\b.*fuzzy_index_terms/is', // the first insert into the dictionary
                ['kilo' => 2, 'lima' => 2],
            ],
        ];
    }

    /**
     * Two writes for different rows whose dictionary terms cross must not deadlock (ER-71).
     * - Words already in the dictionary: each write used to lock its old terms first (the
     *   doc_count give-back) and its new ones after (the upsert), so A held "alpha" waiting for
     *   "bravo" while B held "bravo" waiting for "alpha". A write now locks the existing terms
     *   it touches in one id order before it changes any, so the second write waits instead.
     * - New words on a first index: on MySQL/MariaDB a postings DELETE that matched nothing took
     *   a gap lock at the end of postings_model_idx, which the other write's postings insert
     *   then waited on while that write waited on this one's new word. A write with nothing
     *   posted sends no DELETE.
     * No attempt may roll back: a retry would hide a deadlock.
     *
     * @param array{array{string, string}, array{string, string}}|null $before [name, email] of rows A and B, indexed first
     * @param array{array{string, string}, array{string, string}}      $after  [name, email] of rows A and B, indexed in the race
     * @param array<string, int>                                        $counts expected doc_count per term afterwards
     */
    #[DataProvider('crossedTerms')]
    public function test_writes_whose_terms_cross_neither_deadlock_nor_retry(?array $before, array $after, string $pauseAt, array $counts): void
    {
        $a = User::where('name', 'John Doe')->value('id');
        $b = User::where('name', 'Jane Doe')->value('id');
        $set = function (array $texts) use ($a, $b) {
            foreach ([$a => $texts[0], $b => $texts[1]] as $id => [$name, $email]) {
                DB::table('users')->where('id', $id)->update(['name' => $name, 'email' => $email]);
            }
        };

        if ($before !== null) {
            $set($before);
            app(IndexManager::class)->indexBatch(User::whereKey([$a, $b])->get());
        }
        $set($after);

        $rollbacks = sys_get_temp_dir() . '/fuzzy-race-rollbacks-' . getmypid() . '-' . uniqid();
        $count     = function () use ($rollbacks) {
            \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, fn () => file_put_contents($rollbacks, 'x', FILE_APPEND));
        };

        [$childError, $parentError] = $this->race(
            function () use ($count, $a) { $count(); (new IndexModelJob(User::class, $a))->handle(app(IndexManager::class)); },
            function () use ($count, $b) { $count(); (new IndexModelJob(User::class, $b))->handle(app(IndexManager::class)); },
            $pauseAt,
            1_000_000,
            300_000,
        );

        $retried = is_file($rollbacks) ? strlen(file_get_contents($rollbacks)) : 0;
        @unlink($rollbacks);

        $this->assertNull($childError);
        $this->assertNull($parentError);
        $this->assertSame(0, $retried, 'a write rolled back and retried');
        foreach ($counts as $term => $count) {
            $this->assertSame($count, (int) DB::table('fuzzy_index_terms')->where('term', $term)->value('doc_count'), $term);
        }
    }

    /**
     * Parallel batches into an empty dictionary (parallel rebuild --async workers, scout:import)
     * insert the same new words. Inserted one statement per doc_count increment, each sorted
     * only within itself, two batches locked the words in opposite orders across statements
     * (P: lima at +1, kilo at +2; Q: kilo at +1, lima at +2), and on PostgreSQL many batches
     * deadlocked on all three attempts. A write now inserts its new words in one sorted order
     * (ER-74). MySQL/MariaDB keep one statement per increment: there InnoDB's duplicate-key gap
     * locks cycle in any order, so a batch may still retry, and only the index is checked.
     */
    public function test_parallel_batches_that_add_the_same_new_words_do_not_lose_all_their_attempts(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid') || !function_exists('posix_kill')) {
            $this->markTestSkipped('The race needs several processes: pcntl_fork(), pcntl_waitpid() and posix_kill().');
        }
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite runs one writer at a time, so there is no lock order to race.');
        }
        config(['database.connections.race' => config('database.connections.' . config('database.default'))]);

        DB::table('users')->insert(array_fill(0, 100, ['name' => 'race', 'email' => 'race', 'created_at' => now(), 'updated_at' => now()]));
        $ids        = DB::table('users')->where('name', 'race')->orderBy('id')->pluck('id')->all();
        $vocabulary = array_map(fn ($i) => 'zq' . strtr((string) $i, '0123456789', 'abcdefghij'), range(100, 159)); // 60 words
        $log        = sys_get_temp_dir() . '/fuzzy-race-batches-' . getmypid() . '-' . uniqid();

        mt_srand(74);
        $words = fn (int $n) => implode(' ', array_map(fn () => $vocabulary[mt_rand(0, 59)], range(1, $n)));

        for ($round = 0; $round < 5; $round++) {
            foreach ($ids as $id) {
                DB::table('users')->where('id', $id)->update(['name' => $words(4), 'email' => $words(2)]);
            }
            app(IndexManager::class)->flush(User::class); // every word is new again

            $pids = [];
            foreach (array_chunk($ids, 25) as $batch) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $this->markTestSkipped('pcntl_fork() failed.');
                }
                if ($pid === 0) {
                    try {
                        DB::setDefaultConnection('race');
                        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, fn () => file_put_contents($log, "rollback\n", FILE_APPEND));
                        app(IndexManager::class)->indexBatch(User::whereKey($batch)->get());
                    } catch (\Throwable $e) {
                        file_put_contents($log, 'failed: ' . strtok($e->getMessage(), "\n") . "\n", FILE_APPEND);
                    } finally {
                        posix_kill(getmypid(), SIGKILL); // no destructors, no PHPUnit shutdown: they belong to the parent
                    }
                }
                $pids[] = $pid;
            }
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
            }
        }

        $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
        @unlink($log);
        $failed = array_values(preg_grep('/^failed/', $lines));
        $report = count($failed) . ' of 20 batches failed, ' . (count($lines) - 2 * count($failed)) . ' retried: ' . ($failed[0] ?? '');

        if ($this->dbDriver === 'pgsql') {
            $this->assertSame([], $failed, $report);
            $this->assertSame(100, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
        }

        // Whatever failed rolled back whole: every term counts the rows posting it, and meta the documents.
        $holders = [];
        foreach (DB::table('fuzzy_index_postings')->get(['term_id', 'model_id']) as $posting) {
            $holders[(int) $posting->term_id][(string) $posting->model_id] = true;
        }
        foreach (DB::table('fuzzy_index_terms')->get(['id', 'term', 'doc_count']) as $term) {
            $this->assertSame(count($holders[(int) $term->id] ?? []), (int) $term->doc_count, "doc_count of {$term->term}; {$report}");
        }
        $this->assertSame(
            DB::table('fuzzy_index_documents')->where('model_type', User::class)->count(),
            (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'),
        );
    }

    public static function orphanSweepPauses(): array
    {
        return [
            'after the dictionary read' => ['/^\s*select\b.*\bfrom\W+fuzzy_index_terms\W+where\W+term\W+in\b/is'],
            'after the reload'          => ['/^\s*select\b.*\busers\b/is'], // MySQL's snapshot then still serves the swept word
        ];
    }

    /**
     * A flush of any model sweeps every word no posting holds, and re-indexing leaves a dropped
     * word in the dictionary with no postings. A write that reuses such a word read it as
     * existing; when the sweep deleted it before the write locked it, the write only updated the
     * other words and its posting then failed the term foreign key. A word the locking read no
     * longer finds is inserted again (ER-75).
     */
    #[DataProvider('orphanSweepPauses')]
    public function test_a_flush_of_another_model_mid_write_does_not_fail_it_on_a_swept_word(string $pauseAt): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite runs one writer at a time, so no flush commits between the write\'s reads.');
        }
        $a = User::where('name', 'John Doe')->value('id');
        foreach (['kilo', 'lima'] as $name) {
            DB::table('users')->where('id', $a)->update(['name' => $name, 'email' => 'qa']);
            app(IndexManager::class)->syncModel(User::class, $a);
        }
        // "kilo" is in the dictionary with no posting; the row's text needs it again.
        DB::table('users')->where('id', $a)->update(['name' => 'kilo lima']);

        [$childError, $parentError] = $this->race(
            fn () => (new IndexModelJob(User::class, $a))->handle(app(IndexManager::class)),
            fn () => app(IndexManager::class)->flush(\Ashiqfardus\LaravelFuzzySearch\Tests\Product::class),
            $pauseAt,
            1_000_000,
            300_000,
            pauseParent: false,
        );

        $this->assertNull($childError);
        $this->assertNull($parentError);
        $terms = DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', User::class)->where('p.model_id', (string) $a)->where('p.column_name', 'name')
            ->orderBy('t.term')->pluck('t.term')->all();
        $this->assertSame(['kilo', 'lima'], $terms);
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'kilo')->value('doc_count'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'lima')->value('doc_count'));
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
