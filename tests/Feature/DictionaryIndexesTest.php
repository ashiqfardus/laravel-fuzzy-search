<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../TestModels.php';

/**
 * 2026_10_01_000001. S1: the typo expansion read every dictionary word of a length window and
 * sorted them by doc_count, through an index on term_length alone (1.3 s a search term at 6M rows).
 * It now reads one length at a time through (term_length, doc_count, id), which replaces the
 * term_length index; the id serves the tiebreak (R11-M5) from the index on PostgreSQL too. S5:
 * postings_term_model_idx (term_id, model_type) repeats the leading columns of postings_unique_idx,
 * which covers the same reads; it is dropped.
 */
class DictionaryIndexesTest extends TestCase
{
    private const MIGRATION    = __DIR__ . '/../../database/migrations/2026_10_01_000001_rework_fuzzy_index_terms_and_postings_indexes.php';
    private const LENGTH_COUNT_ID = 'fuzzy_index_terms_term_length_doc_count_id_index';
    private const LENGTH_COUNT    = 'fuzzy_index_terms_term_length_doc_count_index'; // an earlier 2.1 build's
    private const LENGTH          = 'fuzzy_index_terms_term_length_index';
    private const TERM_MODEL      = 'postings_term_model_idx';

    /** @return list<string> the names of the indexes on $table, which the connection prefixes */
    private function indexes(string $table): array
    {
        $name = DB::connection()->getTablePrefix() . $table;
        $rows = match ($this->dbDriver) {
            'sqlite' => DB::select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$name]),
            'pgsql'  => DB::select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$name]),
            'sqlsrv' => DB::select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$name]),
            default  => DB::select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$name]),
        };

        $names = array_map(fn ($row) => strtolower((string) $row->name), $rows);
        sort($names);

        return $names;
    }

    /** @return list<string> the key columns of $index on $table, in key order */
    private function indexColumns(string $table, string $index): array
    {
        $name = DB::connection()->getTablePrefix() . $table;
        $rows = match ($this->dbDriver) {
            'sqlite' => DB::select('select name from pragma_index_info(?) order by seqno', [$index]),
            'pgsql'  => DB::select(
                'select a.attname as name from pg_index i join pg_class c on c.oid = i.indexrelid'
                . ' cross join lateral unnest(i.indkey) with ordinality as k(attnum, n)'
                . ' join pg_attribute a on a.attrelid = i.indrelid and a.attnum = k.attnum'
                . ' where i.indrelid = to_regclass(quote_ident(?)) and c.relname = ? order by k.n',
                [$name, $index]
            ),
            'sqlsrv' => DB::select(
                'select c.name from sys.indexes i join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id'
                . ' join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id'
                . ' where i.object_id = object_id(?) and i.name = ? and ic.is_included_column = 0 order by ic.key_ordinal',
                [$name, $index]
            ),
            default  => DB::select('select column_name as name from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? order by seq_in_index', [$name, $index]),
        };

        return array_map(fn ($row) => strtolower((string) $row->name), $rows);
    }

    private function assertMigrated(): void
    {
        $this->assertContains(self::LENGTH_COUNT_ID, $this->indexes('fuzzy_index_terms'));
        $this->assertSame(['term_length', 'doc_count', 'id'], $this->indexColumns('fuzzy_index_terms', self::LENGTH_COUNT_ID));
        $this->assertNotContains(self::LENGTH, $this->indexes('fuzzy_index_terms'));
        $this->assertNotContains(self::LENGTH_COUNT, $this->indexes('fuzzy_index_terms'));
        $this->assertNotContains(self::TERM_MODEL, $this->indexes('fuzzy_index_postings'));
        $this->assertContains('postings_unique_idx', $this->indexes('fuzzy_index_postings'));
        $this->assertContains('postings_model_idx', $this->indexes('fuzzy_index_postings'));
    }

    private function assertRolledBack(): void
    {
        $this->assertNotContains(self::LENGTH_COUNT_ID, $this->indexes('fuzzy_index_terms'));
        $this->assertNotContains(self::LENGTH_COUNT, $this->indexes('fuzzy_index_terms'));
        $this->assertContains(self::LENGTH, $this->indexes('fuzzy_index_terms'));
        $this->assertContains(self::TERM_MODEL, $this->indexes('fuzzy_index_postings'));
    }

    /** @return list<string> the DDL statements $run issues */
    private function ddl(\Closure $run): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $run();

        return array_values(preg_grep('/^\s*(alter|drop|create)\b/i', $statements));
    }

    public function test_a_fresh_install_reads_the_dictionary_by_length_and_doc_count(): void
    {
        $this->assertMigrated();
    }

    /**
     * An index built before the migration: the rows stay, searches find them, rolling back puts
     * both indexes back, and each direction run a second time changes nothing.
     */
    public function test_the_migration_keeps_an_index_built_before_it_and_runs_twice(): void
    {
        $migration = require self::MIGRATION;
        $migration->down();
        $this->assertRolledBack();
        $this->assertSame([], $this->ddl(fn () => $migration->down()));

        app(IndexManager::class)->indexBatch(User::all());
        $count = fn () => [DB::table('fuzzy_index_terms')->count(), DB::table('fuzzy_index_postings')->count(), DB::table('fuzzy_index_documents')->count()];
        $before = $count();

        $migration->up();
        $this->assertMigrated();
        $this->assertSame([], $this->ddl(fn () => $migration->up()));
        $this->assertSame($before, $count());
        $this->assertSame(['John Doe'], User::search('jonh doe')->useInvertedIndex()->get()->pluck('name')->take(1)->all());
        $this->assertContains('john', array_column(User::search('jonh')->didYouMean(5), 'term'));
    }

    /**
     * ER-161: an install that ran an earlier 2.1 build of this migration has (term_length,
     * doc_count) without the id. up() puts the three-column index in its place, and down() from that
     * state gives back the indexes before the migration.
     */
    public function test_an_install_with_the_earlier_two_column_index_gets_the_three_column_one(): void
    {
        $migration = require self::MIGRATION;
        $earlier   = function () use ($migration) {
            $migration->down();
            Schema::table('fuzzy_index_terms', fn ($table) => $table->index(['term_length', 'doc_count'], self::LENGTH_COUNT));
            Schema::table('fuzzy_index_terms', fn ($table) => $table->dropIndex(self::LENGTH));
            Schema::table('fuzzy_index_postings', fn ($table) => $table->dropIndex(self::TERM_MODEL));
        };

        $earlier();
        $migration->up();
        $this->assertMigrated();

        $earlier();
        $migration->down();
        $this->assertRolledBack();
    }

    /**
     * R11-L4. PostgreSQL and SQL Server run a migration in one transaction, so the lock the index
     * build takes on fuzzy_index_terms (SHARE on PostgreSQL, S on SQL Server: both block writes)
     * lasted until the migration committed, after its drops. An index write that read the
     * dictionary during the build then waited for it, and on PostgreSQL its read lock and the
     * drop's ACCESS EXCLUSIVE deadlocked: `migrate` failed. Each step now commits on its own, so a
     * write is not held once the index is built. A second connection updates a word right after
     * the build, with a lock timeout.
     */
    public function test_an_index_write_after_the_build_is_not_held_until_the_migration_ends(): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('SQLite: an in-memory database has one connection, and SQLite runs a migration outside a transaction; the CI server jobs run this.');
        }

        app(IndexManager::class)->indexBatch(User::all());
        $id   = (int) DB::table('fuzzy_index_terms')->min('id');
        $path = realpath(self::MIGRATION);
        $this->artisan('migrate:rollback', ['--path' => $path, '--realpath' => true])->assertExitCode(0);

        $default = DB::getDefaultConnection();
        config(['database.connections.zz_dictionary_writer' => config("database.connections.{$default}")]);
        $writer = DB::connection('zz_dictionary_writer');
        match ($this->dbDriver) {
            'pgsql'  => $writer->statement("set lock_timeout = '1s'"),
            // Unprepared: a SET run through sp_executesql would end with that call, and the write would wait forever.
            'sqlsrv' => $writer->unprepared('set lock_timeout 1000'),
            default  => $writer->statement('set session lock_wait_timeout = 1, innodb_lock_wait_timeout = 1'),
        };

        $held = null;
        DB::listen(function (QueryExecuted $query) use (&$held, $writer, $id, $default) {
            if ($held !== null || $query->connectionName !== $default
                || !str_contains($query->sql, self::LENGTH_COUNT_ID) || !preg_match('/\b(create|add) index\b/i', $query->sql)) {
                return;
            }
            $writer->beginTransaction();
            try {
                $writer->update('update ' . $writer->getQueryGrammar()->wrapTable('fuzzy_index_terms') . ' set doc_count = doc_count where id = ?', [$id]);
                $held = 'not held';
            } catch (QueryException $e) {
                $held = $e->getMessage();
            } finally {
                $writer->rollBack();
            }
        });
        try {
            $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertExitCode(0);
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
            DB::purge('zz_dictionary_writer');
        }

        $this->assertSame('not held', $held);
        $this->assertMigrated();
    }

    /** MySQL/MariaDB: postings.term_id keeps its foreign key, served by postings_unique_idx. */
    public function test_the_term_foreign_key_survives_the_drop(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB only: InnoDB needs an index that leads with a foreign key column; the CI MySQL and MariaDB jobs run this.');
        }

        $keys = DB::select(
            'select constraint_name from information_schema.referential_constraints where constraint_schema = database() and table_name = ? and referenced_table_name = ?',
            [DB::connection()->getTablePrefix() . 'fuzzy_index_postings', DB::connection()->getTablePrefix() . 'fuzzy_index_terms']
        );
        $this->assertCount(1, $keys);
        $this->assertSame(['postings_unique_idx'], array_values(array_intersect(
            ['postings_unique_idx'],
            array_map(fn ($row) => (string) $row->name, DB::select(
                'select index_name as name from information_schema.statistics where table_schema = database() and table_name = ? and seq_in_index = 1 and column_name = ?',
                [DB::connection()->getTablePrefix() . 'fuzzy_index_postings', 'term_id']
            ))
        )));
    }

    /**
     * S1, MySQL: for a model holding most of the index, MySQL read every posting of the model
     * before each length's read (10–14 s a term at 1M words) instead of probing the postings once
     * per word, in doc_count order, until the pool is full (20–70 ms): the read carries the
     * FirstMatch hint. A small model keeps MySQL's own plan, which reads its few postings; probing
     * would walk the whole length for them (3 s at 1M words). MariaDB, PostgreSQL, SQLite and SQL
     * Server plan both well.
     */
    public function test_mysql_probes_the_postings_for_a_model_that_holds_most_of_the_index(): void
    {
        if ($this->dbDriver !== 'mysql') {
            $this->markTestSkipped('MySQL only: the hint is MySQL syntax, and the other databases plan both shapes well; the CI MySQL job runs this.');
        }

        DB::table('fuzzy_index_meta')->insert([
            ['model_type' => 'App\\Models\\Product', 'total_docs' => 60000, 'total_tokens' => 1000000, 'avg_doc_length' => 16],
            ['model_type' => 'App\\Models\\Category', 'total_docs' => 30, 'total_tokens' => 300, 'avg_doc_length' => 10],
        ]);
        $reads = function (string $model): array {
            $reads = [];
            DB::listen(function ($query) use (&$reads) {
                if (preg_match('/^\s*select\b/i', $query->sql) && str_contains($query->sql, 'term_length')) {
                    $reads[] = $query->sql;
                }
            });
            (new TermExpander)->candidates('kitten', 1, 500, $model, visibleOnly: false);
            DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

            return $reads;
        };

        $this->assertCount(3, $product = $reads('App\\Models\\Product'));
        foreach ($product as $sql) {
            $this->assertStringContainsString('/*+ SEMIJOIN(FIRSTMATCH) */', $sql);
        }
        $this->assertCount(3, $category = $reads('App\\Models\\Category'));
        foreach ($category as $sql) {
            $this->assertStringNotContainsString('SEMIJOIN', $sql);
        }
    }

    /**
     * S1: candidates() reads one length at a time, each in (term_length, doc_count) order: no
     * sort of the window, whose size grows with the dictionary. The plan check runs where EXPLAIN
     * is plain SQL (SQLite, MySQL, MariaDB, PostgreSQL); SQL Server checks the shape only.
     */
    public function test_the_typo_read_takes_each_length_in_index_order(): void
    {
        $rows = [];
        foreach (range(4, 10) as $length) {
            foreach (range(1, 60) as $i) {
                $rows[] = ['term' => substr(str_repeat(chr(96 + $length), $length - 2) . sprintf('%02d', $i), 0, $length), 'doc_count' => $i * 7 % 61, 'term_length' => $length];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('fuzzy_index_terms')->insert($chunk);
        }
        $ids = DB::table('fuzzy_index_terms')->pluck('id');
        foreach ($ids->chunk(100) as $chunk) {
            DB::table('fuzzy_index_postings')->insert($chunk->values()->map(fn ($id, $i) => ['term_id' => $id, 'model_type' => User::class, 'model_id' => (string) ($i + 1), 'frequency' => 1, 'column_name' => 'name'])->all());
        }
        $tables = DB::connection()->getTablePrefix() . 'fuzzy_index_terms, ' . DB::connection()->getTablePrefix() . 'fuzzy_index_postings';
        match ($this->dbDriver) {
            'pgsql'            => DB::statement('analyze ' . $tables),
            'mysql', 'mariadb' => DB::statement('analyze table ' . $tables),
            'sqlite'           => DB::statement('analyze'),
            default            => null,
        };

        $reads = [];
        DB::listen(function ($query) use (&$reads) {
            if (preg_match('/^\s*select\b/i', $query->sql) && str_contains($query->sql, 'fuzzy_index_terms')) {
                $reads[] = [$query->sql, $query->bindings];
            }
        });
        (new TermExpander)->candidates('ggggggg', 2, 10, User::class, visibleOnly: false);

        $this->assertCount(5, $reads, 'one read per length, 5 to 9');
        foreach ($reads as [$sql, $bindings]) {
            $this->assertStringNotContainsStringIgnoringCase('between', $sql);
            $plan = match ($this->dbDriver) {
                'sqlite'           => implode("\n", array_map(fn ($row) => $row->detail, DB::select('explain query plan ' . $sql, $bindings))),
                'pgsql'            => implode("\n", array_map(fn ($row) => $row->{'QUERY PLAN'}, DB::select('explain ' . $sql, $bindings))),
                'mysql', 'mariadb' => json_encode(DB::select('explain ' . $sql, $bindings)),
                default            => null,
            };
            if ($plan === null) {
                continue;
            }
            $this->assertStringContainsString(self::LENGTH_COUNT_ID, $plan);
            $this->assertDoesNotMatchRegularExpression('/temp b-tree for order by|filesort|\bsort\b/i', $plan, $plan);
        }
    }
}
