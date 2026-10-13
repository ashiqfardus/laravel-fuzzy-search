<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * P6-B1: a re-index deletes only the postings its rows no longer hold, each by its whole primary
 * key, and writes only the ones that change. Its range DELETE through postings_model_idx took
 * next-key locks on MySQL/MariaDB, the gaps a neighbouring row's new postings are inserted into, and
 * one of two single index writes in transactions of yours was the deadlock victim.
 */
class IndexWritePostingsTest extends TestCase
{
    /** Every posting, its words resolved: "Type#id" => sorted "term|column|frequency|doc_length", and each document's length. */
    private function index(): array
    {
        $out  = [];
        $rows = DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->join('fuzzy_index_model_types as mt', 'mt.id', '=', 'p.model_type_id')
            ->join('fuzzy_index_columns as c', 'c.id', '=', 'p.column_id')
            ->get(['mt.model_type', 'p.model_id', 't.term', 'c.name', 'p.frequency', 'p.doc_length']);
        foreach ($rows as $row) {
            $row = (object) $row;
            $out[class_basename($row->model_type) . '#' . $row->model_id][] = "{$row->term}|{$row->name}|{$row->frequency}|{$row->doc_length}";
        }
        foreach (DB::table('fuzzy_index_documents')->get() as $row) {
            $row = (object) $row;
            $out[class_basename($row->model_type) . '#' . $row->model_id][] = "length {$row->doc_length}";
        }
        foreach ($out as &$postings) {
            sort($postings);
        }
        ksort($out);

        return $out;
    }

    /** The statements $write sends that name fuzzy_index_postings: [sql, bindings]. */
    private function postingStatements(\Closure $write): array
    {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            if (str_contains($query->sql, 'fuzzy_index_postings')) {
                $log[] = [$query->sql, $query->bindings];
            }
        });
        $write();

        return $log;
    }

    /** Users 1 to 3 changed without events, each one way a re-index changes a row's postings. */
    private function changeUsers(): void
    {
        // A word dropped (doe), one kept at a new frequency (john, 1 to 2), a new one (smith); the
        // length goes from 5 to 6, which every kept posting carries.
        DB::table('users')->where('name', 'John Doe')->update(['name' => 'John John Smith']);
        // Words moved between columns (example to name, jane to email), the length kept at 5: the
        // postings the row holds as they were are not written.
        DB::table('users')->where('name', 'Jane Doe')->update(['name' => 'Example Doe', 'email' => 'jane@doe.com']);
        // A row gone: all of its postings leave.
        DB::table('users')->where('name', 'Jon Snow')->delete();
    }

    public function test_a_reindex_leaves_the_postings_a_rebuild_writes(): void
    {
        $manager = app(IndexManager::class);
        $manager->indexBatch(User::all());
        $ids = User::query()->orderBy('id')->take(3)->pluck('id')->all();

        $this->changeUsers();
        $manager->indexBatch(User::whereKey($ids)->get());
        $manager->syncModel(User::class, $ids[2]);
        $written = $this->index();
        $this->assertModelTermsMirrorPostings('the re-index');

        $this->assertSame(
            ['com|email|1|6', 'example|email|1|6', 'john|email|1|6', 'john|name|2|6', 'length 6', 'smith|name|1|6'],
            $written['User#' . $ids[0]]
        );
        $this->assertSame(
            ['com|email|1|5', 'doe|email|1|5', 'doe|name|1|5', 'example|name|1|5', 'jane|email|1|5', 'length 5'],
            $written['User#' . $ids[1]]
        );
        $this->assertArrayNotHasKey('User#' . $ids[2], $written);

        $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true]);
        $this->assertSame($this->index(), $written, 'what rebuild --fresh writes');
    }

    public function test_a_reindex_deletes_postings_by_key_and_writes_only_those_that_change(): void
    {
        $manager = app(IndexManager::class);
        $manager->indexBatch(User::all());
        $ids = User::query()->orderBy('id')->take(3)->pluck('id')->all();
        $this->changeUsers();

        // Inside a transaction of yours that read first: the write checks its snapshot (MySQL/MariaDB).
        $log = $this->postingStatements(fn () => DB::transaction(function () use ($manager, $ids) {
            User::query()->find($ids[0]);
            $manager->indexBatch(User::whereKey($ids)->get());
            $manager->syncModel(User::class, $ids[2]);
        }));

        $deletes = array_values(array_filter($log, fn (array $statement) => stripos(ltrim($statement[0]), 'delete') === 0));
        $this->assertCount(2, $deletes, 'one DELETE for the batch, one for the row gone');
        foreach ($deletes as [$sql]) {
            $this->assertMatchesRegularExpression('/term_id in \(\d+(,\d+)*\)/', $sql, 'each posting by its whole key');
            if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
                $this->assertStringContainsString('force index (primary)', $sql);
            }
        }
        foreach ($log as [$sql]) {
            if (stripos($sql, 'for update') !== false) {
                $this->assertStringContainsString('term_id', $sql, 'no locking read of a row\'s postings by model: only the snapshot check, by key');
            }
        }

        // An unchanged row's re-index writes no posting.
        $writes = array_filter(
            $this->postingStatements(fn () => $manager->indexBatch(User::whereKey($ids)->get())),
            fn (array $statement) => preg_match('/^\s*(insert|update|delete|merge)\b/i', $statement[0]) === 1
        );
        $this->assertSame([], array_values($writes));

        // A removal deletes the row's postings by key too.
        $deletes = array_values(array_filter(
            $this->postingStatements(fn () => $manager->removeFromIndex(User::class, $ids[0])),
            fn (array $statement) => stripos(ltrim($statement[0]), 'delete') === 0
        ));
        $this->assertCount(1, $deletes);
        $this->assertMatchesRegularExpression('/term_id in \(\d+(,\d+)*\)/', $deletes[0][0]);
        $this->assertArrayNotHasKey('User#' . $ids[0], $this->index());
        $this->assertModelTermsMirrorPostings('the removal');
    }

    /**
     * MySQL: a re-index inside an open transaction holds no lock on postings_model_idx and no gap
     * lock on the postings, only its own postings' records (performance_schema.data_locks; MariaDB
     * has no such view, and CI runs this on MySQL).
     */
    public function test_a_reindex_in_a_transaction_holds_no_gap_lock_on_the_postings(): void
    {
        if ($this->dbDriver !== 'mysql') {
            $this->markTestSkipped('performance_schema.data_locks is MySQL\'s (8.0+); MariaDB has none.');
        }
        $manager = app(IndexManager::class);
        $manager->indexBatch(User::all());
        $ids = User::query()->orderBy('id')->take(3)->pluck('id')->all();
        $this->changeUsers();

        DB::beginTransaction();
        try {
            $manager->indexBatch(User::whereKey($ids)->get());
            $manager->syncModel(User::class, $ids[2]);
            try {
                $locks = DB::select(
                    'select index_name, lock_type, lock_mode from performance_schema.data_locks where object_name = ? and engine_transaction_id ='
                    . ' (select trx_id from information_schema.innodb_trx where trx_mysql_thread_id = connection_id())',
                    [DB::getTablePrefix() . 'fuzzy_index_postings']
                );
            } catch (\Illuminate\Database\QueryException $e) {
                $this->markTestSkipped('performance_schema.data_locks cannot be read here: ' . $e->getMessage());
            }
        } finally {
            DB::rollBack();
        }

        $records = array_values(array_filter(array_map(fn ($lock) => (object) $lock, $locks), fn ($lock) => $lock->lock_type === 'RECORD'));
        $this->assertNotSame([], $records, 'the write locked its postings');
        foreach ($records as $lock) {
            $this->assertSame(['PRIMARY', 'X,REC_NOT_GAP'], [$lock->index_name, $lock->lock_mode]);
        }
    }
}
