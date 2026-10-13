<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * P6-S1 (ruling ER-201): an index write inside a transaction of yours that read before it indexed
 * (User::find() then save(), Scout with after_commit off). On MySQL/MariaDB at REPEATABLE READ that
 * read starts the transaction's snapshot. Another process saves the same row, and its index write
 * commits, before this write claims the row: the write read the row's old words from its snapshot,
 * gave the counts back for the words the row held before the other write, and left the other
 * write's words counted with no posting, for good. The other process is a PHP process of its own
 * (Fixtures/index-writer.php): SQL Server's ODBC driver is not fork-safe.
 */
class IndexWriteSnapshotTest extends TestCase
{
    /**
     * Opens a transaction, reads user $id, lets another process save the user as "Zzchild
     * Alphaword" (its index written by the observer, committed), then runs $write inside the
     * transaction and commits it.
     */
    private function afterAnotherWrite(int $id, \Closure $write): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite has one writer: the other process waits for this transaction.');
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('The other writer needs a process of its own: proc_open().');
        }

        $base = sys_get_temp_dir() . '/fuzzy-snapshot-' . getmypid();
        file_put_contents("{$base}.json", json_encode([
            'connection' => config('database.connections.' . config('database.default')),
            'go'         => "{$base}.go",
            'steps'      => [['save', User::class, [$id]]],
            'names'      => ['Zzchild Alphaword'],
        ]));

        try {
            DB::beginTransaction();
            $this->assertSame('John Doe', User::query()->find($id)->name); // the snapshot starts here on MySQL/MariaDB

            $proc = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/index-writer.php', "{$base}.json"], [1 => ['pipe', 'w'], 2 => ['file', "{$base}.err", 'w']], $pipes);
            fgets($pipes[1]); // "ready"
            touch("{$base}.go");
            $output = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            proc_close($proc);
            $lines = explode("\n", $output);
            $this->assertSame(['failed' => [], 'rollbacks' => 0], json_decode((string) end($lines), true), "the other writer: {$output} " . @file_get_contents("{$base}.err"));

            $write();
            DB::commit();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach (glob("{$base}*") ?: [] as $file) {
                @unlink($file);
            }
        }
    }

    /** Every word's doc_count, and each model type's, equals the documents its postings hold. */
    private function assertCountsExact(string $after): void
    {
        $documents = [];
        foreach (DB::table('fuzzy_index_postings')->select('term_id', 'model_type_id', 'model_id')->distinct()->get() as $row) {
            $row = (object) $row;
            $documents[(int) $row->term_id] = ($documents[(int) $row->term_id] ?? 0) + 1;
        }
        $counts = DB::table('fuzzy_index_terms')->pluck('doc_count', 'id')->map(fn ($count) => (int) $count)->all();
        ksort($documents);
        ksort($counts);

        $this->assertSame($documents, array_filter($counts), "the dictionary's doc_count against the postings, after {$after}");
        $this->assertModelTermsMirrorPostings($after);
    }

    /** The words the user's postings hold, sorted. */
    private function postedWords(int $id): array
    {
        return DB::table('fuzzy_index_postings as p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type_id', IndexManager::modelTypeId(User::class))->where('p.model_id', (string) $id)
            ->distinct()->orderBy('t.term')->pluck('t.term')->all();
    }

    public function test_a_write_in_a_transaction_that_read_first_gives_back_the_words_the_row_holds_now(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $id = (int) User::query()->where('name', 'John Doe')->value('id');

        $this->afterAnotherWrite($id, function () use ($id) {
            DB::table('users')->where('id', $id)->update(['name' => 'Zzparent Betaword']);
            app(IndexManager::class)->indexModel(User::query()->find($id));
        });

        $this->assertSame(['betaword', 'com', 'example', 'john', 'zzparent'], $this->postedWords($id));
        $this->assertCountsExact('the write');
        // Before the fix: 'doe' (Jane Doe holds it too) was given back twice, by the other write and
        // again by this one, and the other write's words kept their count with no posting.
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'alphaword')->value('doc_count'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'doe')->value('doc_count'));
    }

    public function test_a_removal_in_a_transaction_that_read_first_gives_back_the_words_the_row_holds_now(): void
    {
        app(IndexManager::class)->indexBatch(User::all());
        $id = (int) User::query()->where('name', 'John Doe')->value('id');

        $this->afterAnotherWrite($id, fn () => app(IndexManager::class)->removeFromIndex(User::class, $id));

        $this->assertSame([], $this->postedWords($id));
        $this->assertCountsExact('the removal');
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'zzchild')->value('doc_count'));
        $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'doe')->value('doc_count'));
    }
}
