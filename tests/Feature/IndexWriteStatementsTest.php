<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Tests\ListColumnsUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * ER-227. On SQL Server every index write holds its index's application lock (ER-180) from its
 * first statement to its commit, so no other write inserts or changes one of its rows meanwhile:
 * it inserts the rows it adds with plain INSERTs and updates the ones it changes by key. Its
 * MERGEs (the document claim, the new words, the model terms, the postings, the documents, the
 * meta row) held a rebuild past 1M rows to about 120 documents a second. The other databases
 * keep their upserts: their writes run in parallel, and a row another write inserts meanwhile
 * takes the conflict branch.
 */
class IndexWriteStatementsTest extends TestCase
{
    /** The tables an index write inserts into with an upsert on every database but SQL Server. */
    private const UPSERTED = ['fuzzy_index_documents', 'fuzzy_index_terms', 'fuzzy_index_model_terms', 'fuzzy_index_postings', 'fuzzy_index_meta'];

    /** @return list<array{string, string, string}> each write $run sends to an index table: [verb, table, sql] */
    private function writes(\Closure $run): array
    {
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|merge)\b[^;]*?(fuzzy_index_\w+)/is', $query->sql, $match) === 1) {
                $writes[] = [strtolower($match[1]), $match[2], $query->sql];
            }
        });
        try {
            $run();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $writes;
    }

    /**
     * SQL Server: no MERGE, and every row added with a plain INSERT. Elsewhere, unchanged: every
     * insert into those tables an upsert (ON DUPLICATE KEY UPDATE on MySQL/MariaDB, ON CONFLICT on
     * PostgreSQL and SQLite).
     */
    private function assertWritesOfThisDatabase(array $writes): void
    {
        $inserts = array_values(array_filter($writes, fn (array $write) => in_array($write[1], self::UPSERTED, true) && in_array($write[0], ['insert', 'merge'], true)));
        $this->assertNotEmpty($inserts);

        if ($this->dbDriver === 'sqlsrv') {
            $this->assertSame([], array_column(array_filter($writes, fn (array $write) => $write[0] === 'merge'), 2), 'no MERGE under the index write lock');

            return;
        }

        $clause = DbDialect::isMySqlFamily($this->dbDriver) ? '/\bon duplicate key update\b/i' : '/\bon conflict\b/i';
        foreach ($inserts as [$verb, $table, $sql]) {
            $this->assertSame('insert', $verb, $table);
            $this->assertMatchesRegularExpression($clause, $sql, "{$table}: {$sql}");
        }
    }

    /** $verb statements on each of $tables are among $writes. */
    private function assertWritten(array $writes, string $verb, array $tables): void
    {
        foreach ($tables as $table) {
            $this->assertNotEmpty(array_filter($writes, fn (array $write) => $write[0] === $verb && $write[1] === $table), "no {$verb} on {$table}");
        }
    }

    /**
     * The index tables by name, as two writes of the same rows leave them whatever ids they
     * numbered: documents, each word a document holds (a word no posting holds any more stays in
     * the dictionary at 0 until a flush sweeps it, so it is left out), each type's own counts, the
     * meta totals and the postings.
     */
    private function tables(): array
    {
        $tables = ['documents' => [], 'terms' => [], 'model_terms' => [], 'meta' => [], 'postings' => []];
        foreach (DB::table('fuzzy_index_documents')->get() as $row) {
            $row = (object) $row;
            $tables['documents'][$row->model_type . '|' . $row->model_id] = (int) $row->doc_length;
        }
        foreach (DB::table('fuzzy_index_terms')->where('doc_count', '>', 0)->get() as $row) {
            $row = (object) $row;
            $tables['terms'][(string) $row->term] = (int) $row->doc_count . '|' . (int) $row->term_length;
        }
        foreach (DB::table('fuzzy_index_model_terms as mt')->join('fuzzy_index_model_types as ty', 'ty.id', '=', 'mt.model_type_id')->get(['ty.model_type', 'mt.term', 'mt.term_length', 'mt.doc_count']) as $row) {
            $row = (object) $row;
            $tables['model_terms'][$row->model_type . '|' . $row->term] = (int) $row->doc_count . '|' . (int) $row->term_length;
        }
        foreach (DB::table('fuzzy_index_meta')->get() as $row) {
            $row = (object) $row;
            $tables['meta'][$row->model_type] = (int) $row->total_docs . '|' . (int) $row->total_tokens . '|' . round((float) $row->avg_doc_length, 4);
        }
        foreach ($this->namedPostings('p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')->get(['t.term', 'p.model_type', 'p.model_id', 'p.column_name', 'p.frequency', 'p.doc_length']) as $row) {
            $row = (object) $row;
            $tables['postings']["{$row->term}|{$row->model_type}|{$row->model_id}|{$row->column_name}"] = (int) $row->frequency . '|' . (int) $row->doc_length;
        }

        return array_map(function (array $table) {
            ksort($table, SORT_STRING);

            return $table;
        }, $tables);
    }

    /**
     * rebuild --fresh in three chunks, after another model type has indexed one row: the first chunk
     * inserts every word and row new to the index, the later ones also meet words and the type's own
     * counts the earlier chunks wrote, and some words the other type holds already.
     */
    public function test_a_fresh_rebuild_adds_its_rows_with_inserts_on_sql_server_and_upserts_elsewhere(): void
    {
        app(IndexManager::class)->indexBatch(ListColumnsUser::where('name', 'Alice Smith')->get());
        config(['fuzzy-search.indexing.chunk_size' => 3]);

        $writes = $this->writes(fn () => $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true])));

        $this->assertWritesOfThisDatabase($writes);
        if ($this->dbDriver === 'sqlsrv') {
            $this->assertWritten($writes, 'insert', self::UPSERTED);
        }
        $this->assertSame(7, (int) DB::table('fuzzy_index_meta')->where('model_type', User::class)->value('total_docs'));
        $this->assertModelTermsMirrorPostings('the rebuild');
    }

    /**
     * Every way a re-index changes a row's entries, and both removals, leave the tables as a
     * rebuild of the same rows from an empty index writes them; on SQL Server without a MERGE.
     */
    public function test_a_re_index_of_changed_rows_leaves_the_tables_a_fresh_rebuild_writes(): void
    {
        $manager = app(IndexManager::class);
        $ids     = User::query()->orderBy('id')->pluck('id')->all(); // John, Jane, Jon, Johnny, Alice, Bob, Charlie
        $users   = [$ids[0], $ids[1], $ids[2], $ids[3], $ids[5], $ids[6]];
        $manager->indexBatch(User::whereKey($users)->get());
        // Another type's words: smith is in the dictionary, but not among User's own words.
        $manager->indexBatch(ListColumnsUser::whereKey([$ids[4]])->get());

        // John: a word dropped (doe), one at a new frequency (john), one new to the type (smith),
        // the length up, which every posting it keeps carries.
        DB::table('users')->where('id', $ids[0])->update(['name' => 'John John Smith']);
        // Jane: words moved between columns, the length kept.
        DB::table('users')->where('id', $ids[1])->update(['name' => 'Example Doe', 'email' => 'jane@doe.com']);
        // Jon: two words new to the dictionary; snow leaves User's own words.
        DB::table('users')->where('id', $ids[2])->update(['name' => 'Zebra Quokka']);
        // Johnny: no text left, so the row leaves the index.
        DB::table('users')->where('id', $ids[3])->update(['name' => '', 'email' => '']);
        // Bob and Charlie: gone, one re-read (a queued job), one removed (Scout's delete()).
        DB::table('users')->whereIn('id', [$ids[5], $ids[6]])->delete();

        $writes = $this->writes(function () use ($manager, $ids) {
            $manager->indexBatch(User::whereKey(array_slice($ids, 0, 4))->get());
            $manager->syncModel(User::class, $ids[5]);
            $manager->removeFromIndex(User::class, $ids[6]);
        });

        $this->assertWritesOfThisDatabase($writes);
        if ($this->dbDriver === 'sqlsrv') {
            $this->assertWritten($writes, 'update', ['fuzzy_index_documents', 'fuzzy_index_terms', 'fuzzy_index_model_terms', 'fuzzy_index_postings', 'fuzzy_index_meta']);
            $this->assertWritten($writes, 'delete', ['fuzzy_index_documents', 'fuzzy_index_model_terms', 'fuzzy_index_postings']);
        }
        $this->assertModelTermsMirrorPostings('the re-index');
        $reindexed = $this->tables();
        $this->assertSame('1|4', $reindexed['model_terms'][User::class . '|john'] ?? null); // one document still: a frequency is not a count of documents
        $this->assertSame('1|5', $reindexed['model_terms'][User::class . '|smith'] ?? null);
        $this->assertArrayNotHasKey(User::class . '|snow', $reindexed['model_terms']);
        $this->assertSame('1|5', $reindexed['model_terms'][User::class . '|zebra'] ?? null);

        $manager->flush(User::class);
        $manager->flush(ListColumnsUser::class);
        $this->assertSame([], DB::table('fuzzy_index_terms')->pluck('term')->all());
        $manager->indexBatch(User::whereKey($users)->get());
        $manager->indexBatch(ListColumnsUser::whereKey([$ids[4]])->get());

        $this->assertSame($this->tables(), $reindexed);
    }
}
