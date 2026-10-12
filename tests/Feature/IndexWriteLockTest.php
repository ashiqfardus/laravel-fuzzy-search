<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\ListColumnsUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * SQL Server: every index write to one index takes that index's application lock first (ruling
 * ER-180). Parallel writers deadlocked on each other's locks: the claim's MERGE (HOLDLOCK range
 * locks on the varchar model_id key, whose string order interleaves id ranges: '1000' < '10000' <
 * '1001'), the dictionary's locking read (HOLDLOCK's range lock on the newest term id blocked every
 * other writer's new word), the doc_count UPDATE against the dictionary MERGE, and lock escalation
 * on fuzzy_index_terms. Three immediate retries could not break a sustained cycle: 3 of 4 rebuild
 * workers died with 40001 and left the index partial. The lock is per index, not per model type:
 * every model type shares fuzzy_index_terms.
 *
 * The writers run in PHP processes of their own (Fixtures/index-writer.php, through proc_open):
 * SQL Server's ODBC driver is not fork-safe.
 */
class IndexWriteLockTest extends TestCase
{
    private function skipUnlessSqlServer(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('The index write lock is SQL Server\'s (ER-180); the other databases\' parallel writes are covered by IndexModelJobOverlapTest.');
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('The writers need processes of their own: proc_open().');
        }
    }

    /**
     * 12,500 rows over a 2,000-word vocabulary, every word new to the index. Returns the ids up to
     * 2,500 and from 10,000 to 12,500, two ranges whose string order interleaves.
     *
     * @return array{list<int>, list<int>}
     */
    private function seedRows(): array
    {
        $vocabulary = array_map(fn ($i) => 'zq' . strtr((string) $i, '0123456789', 'abcdefghij'), range(1000, 2999));
        mt_srand(180);
        $words = fn (int $n) => implode(' ', array_map(fn () => $vocabulary[mt_rand(0, 1999)], range(1, $n)));

        for ($row = 0; $row < 12500; $row += 400) { // 4 bindings a row, under SQL Server's 2,100
            DB::table('users')->insert(array_map(
                fn () => ['name' => $words(4), 'email' => $words(2), 'created_at' => now(), 'updated_at' => now()],
                range(1, 400)
            ));
        }
        $ids = DB::table('users')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            array_values(array_filter($ids, fn (int $id) => $id <= 2500)),
            array_values(array_filter($ids, fn (int $id) => $id >= 10000 && $id <= 12500)),
        ];
    }

    /** A rebuild worker's steps: one indexBatch() per 250 of $ids. */
    private static function rebuild(string $class, array $ids): array
    {
        return array_map(fn (array $chunk) => ['index', $class, $chunk], array_chunk($ids, 250));
    }

    /** $ids cut in two halves. */
    private static function halves(array $ids): array
    {
        return array_chunk($ids, intdiv(count($ids) + 1, 2));
    }

    /**
     * Run each writer's steps in a process of its own, all starting at once.
     *
     * @param  list<list<array{string, string, list<int>}>> $writers
     * @return list<array{failed: list<string>, rollbacks: int}>
     */
    private function runWriters(array $writers, array $names = []): array
    {
        $base       = sys_get_temp_dir() . '/fuzzy-writer-' . getmypid() . '-' . uniqid();
        $connection = config('database.connections.' . config('database.default'));
        $procs      = [];

        try {
            foreach ($writers as $i => $steps) {
                file_put_contents("{$base}.{$i}.json", json_encode(['connection' => $connection, 'go' => "{$base}.go", 'steps' => $steps, 'names' => $names]));
                $proc = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/index-writer.php', "{$base}.{$i}.json"], [1 => ['pipe', 'w'], 2 => ['file', "{$base}.{$i}.err", 'w']], $pipes);
                $procs[$i] = [$proc, $pipes[1]];
            }
            foreach ($procs as [$proc, $out]) {
                fgets($out); // "ready", or nothing from a writer that died: its result says why
            }
            touch("{$base}.go");

            $results = [];
            foreach ($procs as $i => [$proc, $out]) {
                $output = trim((string) stream_get_contents($out));
                fclose($out);
                proc_close($proc);
                $lines       = explode("\n", $output);
                $results[$i] = json_decode((string) end($lines), true)
                    ?? ['failed' => ["writer {$i} gave no result: {$output} " . file_get_contents("{$base}.{$i}.err")], 'rollbacks' => 0];
            }

            return $results;
        } finally {
            foreach (glob("{$base}*") ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    /** No writer failed, thrown or reported. */
    private function assertNoneFailed(array $results): void
    {
        $failed = array_merge(...array_column($results, 'failed'));

        $this->assertSame([], $failed, count($failed) . ' failures, ' . array_sum(array_column($results, 'rollbacks')) . ' rollbacks');
    }

    /** The index by name: documents, each word's doc_count, the meta totals and the postings. */
    private function snapshot(): array
    {
        $index = ['documents' => [], 'terms' => [], 'meta' => [], 'postings' => []];
        foreach (DB::table('fuzzy_index_documents')->get() as $row) {
            $index['documents'][$row->model_type . '|' . $row->model_id] = (int) $row->doc_length;
        }
        foreach (DB::table('fuzzy_index_terms')->get() as $row) {
            $index['terms'][(string) $row->term] = (int) $row->doc_count;
        }
        foreach (DB::table('fuzzy_index_meta')->get() as $row) {
            $index['meta'][$row->model_type] = (int) $row->total_docs . '|' . (int) $row->total_tokens;
        }
        $postings = $this->namedPostings('p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->get(['t.term', 'p.model_type', 'p.model_id', 'p.column_name', 'p.frequency', 'p.doc_length']);
        foreach ($postings as $row) {
            $index['postings']["{$row->term}|{$row->model_type}|{$row->model_id}|{$row->column_name}"] = (int) $row->frequency . '|' . (int) $row->doc_length;
        }

        return array_map(function (array $section) {
            ksort($section, SORT_STRING);

            return $section;
        }, $index);
    }

    /** The index a sequential rebuild of each model's ids writes, from an empty index. */
    private function sequentialRebuild(array $idsByClass): array
    {
        $manager = app(IndexManager::class);
        foreach (array_keys($idsByClass) as $class) {
            $manager->flush($class);
        }
        $this->assertSame([], $this->snapshot()['terms']);
        foreach ($idsByClass as $class => $ids) {
            foreach (array_chunk($ids, 250) as $chunk) {
                $manager->indexBatch($class::whereKey($chunk)->get());
            }
        }

        return $this->snapshot();
    }

    /** $actual equals $expected, section by section, naming the first differences. */
    private function assertSameIndex(array $expected, array $actual): void
    {
        foreach ($expected as $section => $rows) {
            $missing = array_diff_assoc($rows, $actual[$section]);
            $extra   = array_diff_assoc($actual[$section], $rows);
            $this->assertSame(
                [[], []],
                [$missing, $extra],
                sprintf('%s: %d missing or different, %d extra; first: %s', $section, count($missing), count($extra), json_encode(array_slice($missing + $extra, 0, 5, true)))
            );
        }
    }

    public function test_four_parallel_writers_over_interleaving_ids_complete_and_index_as_a_sequential_rebuild(): void
    {
        $this->skipUnlessSqlServer();
        [$low, $high] = $this->seedRows();

        $writers = [];
        foreach ([...self::halves($low), ...self::halves($high)] as $range) {
            $writers[] = self::rebuild(User::class, $range);
        }
        $this->assertNoneFailed($this->runWriters($writers));

        $parallel = $this->snapshot();
        $this->assertSame(count($low) + count($high), (int) $parallel['meta'][User::class]); // total_docs
        $this->assertSameIndex($this->sequentialRebuild([User::class => [...$low, ...$high]]), $parallel);
    }

    /** Every model type shares fuzzy_index_terms: a User and a ListColumnsUser written at once. */
    public function test_two_model_types_written_at_once_complete_and_index_as_a_sequential_rebuild(): void
    {
        $this->skipUnlessSqlServer();
        [$low, $high] = $this->seedRows();
        [$lowA, $lowB]   = self::halves($low);
        [$highA, $highB] = self::halves($high);

        $this->assertNoneFailed($this->runWriters([
            self::rebuild(User::class, $lowA),
            self::rebuild(ListColumnsUser::class, $lowB),
            self::rebuild(User::class, $highA),
            self::rebuild(ListColumnsUser::class, $highB),
        ]));

        $parallel = $this->snapshot();
        $this->assertCount(2, $parallel['meta']);
        $this->assertSameIndex($this->sequentialRebuild([User::class => [...$lowA, ...$highA], ListColumnsUser::class => [...$lowB, ...$highB]]), $parallel);
    }

    /** A save indexed synchronously (indexing.async off) while three rebuild workers run. */
    public function test_a_sync_save_during_a_parallel_rebuild_completes(): void
    {
        $this->skipUnlessSqlServer();
        [$low, $high] = $this->seedRows();
        [$lowA, $lowB] = self::halves($low);
        $all   = [...$low, ...$high];
        $saved = array_values(array_filter($all, fn (int $id) => $id % 97 === 0)); // spread over both ranges
        $names = array_map(fn ($i) => 'zzsave' . strtr((string) $i, '0123456789', 'abcdefghij') . ' zqbaaa', range(10, 29));

        $this->assertNoneFailed($this->runWriters([
            self::rebuild(User::class, $lowA),
            self::rebuild(User::class, $lowB),
            self::rebuild(User::class, $high),
            [['save', User::class, $saved]],
        ], $names));

        $parallel = $this->snapshot();
        foreach ($saved as $i => $id) {
            $this->assertArrayHasKey(explode(' ', $names[$i % count($names)])[0] . '|' . User::class . "|{$id}|name", $parallel['postings']);
        }
        $this->assertSameIndex($this->sequentialRebuild([User::class => $all]), $parallel);
    }

    /**
     * An app's transaction that updated a row and indexes it before its commit, while another write
     * of that row has claimed it and waits on the update in its re-read (SQL Server's locking read
     * committed): that write holds the index lock the transaction's write waits for. SQL Server's
     * deadlock detector covers application locks: the lock request is the victim (-3), thrown in
     * the words of SQL Server's own deadlock error, so Laravel throws a DeadlockException out of the
     * app's transaction, and the other write completes. If the other write is the victim instead,
     * its retry waits for the app's commit. Either way nothing hangs, and the index holds the row
     * as committed.
     */
    public function test_an_app_transaction_indexing_a_row_another_write_is_re_reading_fails_as_a_deadlock_or_completes(): void
    {
        $this->skipUnlessSqlServer();
        $id = (int) User::orderBy('id')->value('id');
        app(IndexManager::class)->indexBatch(User::whereKey([$id])->get());

        [$app, $write] = $this->runWriters([
            [['update-then-index', User::class, [$id]]],
            [['index-after-update', User::class, [$id]]],
        ]);

        $this->assertSame([], $write['failed']);
        foreach ($app['failed'] as $failure) {
            $this->assertStringStartsWith(\Illuminate\Database\DeadlockException::class . ': ', $failure);
        }
        $this->assertSame($app['failed'] === [] ? 'zzapp' : 'John Doe', User::whereKey($id)->value('name'));
        $this->assertSameIndex($this->sequentialRebuild([User::class => [$id]]), $this->snapshot());
    }

    /**
     * The statements every index write starts its transaction with: on SQL Server the index's
     * lock, named after the database and the table prefix; elsewhere no lock at all. write() and
     * removeFromIndex() (Scout's delete()) both take it, first.
     */
    public function test_an_index_write_starts_with_the_index_lock_on_sql_server_only(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = [$query->sql, $query->bindings];
        });
        Event::listen(TransactionBeginning::class, function () use (&$statements) {
            $statements[] = ['begin', []];
        });

        $user = User::first();
        app(IndexManager::class)->indexModel($user);
        app(IndexManager::class)->removeFromIndex(User::class, $user->getKey());

        $firsts = [];
        foreach ($statements as $i => [$sql]) {
            if ($sql === 'begin') {
                $firsts[] = $statements[$i + 1];
            }
        }
        $this->assertCount(2, $firsts);
        $locks = array_filter($statements, fn (array $statement) => str_contains($statement[0], 'sp_getapplock'));

        if ($this->dbDriver !== 'sqlsrv') {
            $this->assertSame([], $locks);

            return;
        }

        $resource = 'fuzzy-search:write:' . DB::connection()->getDatabaseName() . ':';
        foreach ($firsts as [$sql, $bindings]) {
            $this->assertStringContainsString("EXEC @result = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = @@LOCK_TIMEOUT", $sql);
            $this->assertSame([$resource], $bindings);
        }
        $this->assertCount(2, $locks);

        // Under a table prefix the lock names it: two installs in one database, each under its own
        // prefix, are two indexes. The prefixed tables do not exist, so the write fails just after it.
        config(['database.connections.prefixed' => ['prefix' => 'wl_'] + config('database.connections.integration')]);
        DB::setDefaultConnection('prefixed');
        $statements = [];
        try {
            app(IndexManager::class)->removeFromIndex(User::class, 1);
            $this->fail('The prefixed tables do not exist.');
        } catch (\Illuminate\Database\QueryException) {
        } finally {
            DB::setDefaultConnection('integration');
            DB::purge('prefixed');
        }
        $this->assertSame('begin', $statements[0][0]);
        $this->assertStringContainsString('sp_getapplock', $statements[1][0]);
        $this->assertSame([$resource . 'wl_'], $statements[1][1]);
    }

    /**
     * A lock not granted throws, naming the lock and sp_getapplock's code, and writes nothing.
     * The wait is the connection's LOCK_TIMEOUT (-1, no bound, unless the app sets one).
     */
    public function test_a_lock_not_granted_throws_and_writes_nothing(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('The index write lock is SQL Server\'s (ER-180).');
        }
        $resource = 'fuzzy-search:write:' . DB::connection()->getDatabaseName() . ':';
        config(['database.connections.holder' => config('database.connections.integration')]);
        $holder = DB::connection('holder');
        $holder->beginTransaction();
        $this->assertSame(0, (int) $holder->scalar("DECLARE @result int; EXEC @result = sp_getapplock @Resource = ?, @LockMode = 'Exclusive', @LockOwner = 'Transaction'; SELECT @result AS result", [$resource]));

        $attempts = 0;
        DB::listen(function ($query) use (&$attempts) {
            $attempts += (int) str_contains($query->sql, 'sp_getapplock');
        });
        DB::unprepared('SET LOCK_TIMEOUT 200'); // not statement(): a SET inside sp_prepexec ends with it
        $this->assertSame(200, (int) DB::scalar('select @@LOCK_TIMEOUT')); // else the write below waits for ever
        $thrown = null;
        try {
            app(IndexManager::class)->indexModel(User::first());
        } catch (\RuntimeException $e) {
            $thrown = $e;
        } finally {
            DB::unprepared('SET LOCK_TIMEOUT -1');
            $holder->rollBack();
            DB::purge('holder');
        }
        $this->assertNotNull($thrown, 'The lock is held by another connection.');
        $this->assertStringContainsString("[{$resource}]", $thrown->getMessage());
        $this->assertStringContainsString('returned -1', $thrown->getMessage());
        $this->assertSame(1, $attempts); // a timeout is not a deadlock: thrown at once, as SQL Server's own 1222
        $this->assertSame(0, DB::table('fuzzy_index_documents')->count());

        // Released: the write goes through.
        app(IndexManager::class)->indexModel(User::first());
        $this->assertSame(1, DB::table('fuzzy_index_documents')->count());
    }
}
