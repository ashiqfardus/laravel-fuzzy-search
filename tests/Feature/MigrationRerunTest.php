<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SC-1. MySQL and MariaDB commit each DDL statement on its own, and the migrator wraps no migration
 * in a transaction there. A failure after the first statement of the term_length, column_name or
 * search-log migration (a lock-wait timeout, a killed deploy) left it half applied and unrecorded,
 * and every later `migrate` stopped at "1060 Duplicate column name" (1050 for the log table); a
 * failed key swap left the postings table with no unique key. Each now changes its table in one
 * ALTER where it can, which InnoDB applies whole, and skips what an earlier run did. The failure is
 * simulated: the statement after the migration's first DDL throws. PostgreSQL, SQLite and SQL
 * Server roll the migration back whole, and run the same checks.
 */
class MigrationRerunTest extends TestCase
{
    private const PATH = __DIR__ . '/../../database/migrations';

    public static function interruptions(): array
    {
        return [
            'term_length' => ['2026_09_17_000001_add_term_length_to_fuzzy_index_terms_table', 'fuzzy_index_terms', '/^\s*alter table\b.*term_length/i'],
            'column_name' => ['2026_09_18_000001_add_column_name_to_fuzzy_index_postings_table', 'fuzzy_index_postings', '/^\s*alter table\b.*column_name/i'],
            'search_logs' => ['2026_09_19_000001_create_fuzzy_search_logs_table', 'fuzzy_search_logs', '/^\s*create table\b.*fuzzy_search_logs/i'],
        ];
    }

    /** Roll back $migration and every later one, as `migrate:rollback --step` does. */
    private function rollBackTo(string $migration): void
    {
        $steps = count(array_filter(glob(self::PATH . '/*.php'), fn (string $file) => basename($file, '.php') >= $migration));
        $this->artisan('migrate:rollback', ['--path' => realpath(self::PATH), '--realpath' => true, '--step' => $steps])->assertExitCode(0);
    }

    private function migrate(): void
    {
        $this->artisan('migrate', ['--path' => realpath(self::PATH), '--realpath' => true])->run();
    }

    /** `migrate`, with the first statement matching $after throwing once it has run. */
    private function migrateFailingAfter(string $after): void
    {
        $armed = false;
        DB::listen(function (QueryExecuted $query) use (&$armed, $after) {
            if (!$armed && preg_match($after, $query->sql)) {
                $armed = true;
                throw new \RuntimeException('simulated failure after: ' . $query->sql);
            }
        });

        try {
            $this->migrate();
            $this->fail('the simulated failure did not happen');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('simulated failure after: ', $e->getMessage());
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }
    }

    /**
     * $table's columns and indexes, each index with its uniqueness and columns in key order.
     *
     * @return array{columns: list<string>, indexes: array<string, string>}
     */
    private function schema(string $table): array
    {
        $name    = DB::connection()->getTablePrefix() . $table;
        $indexes = [];

        switch ($this->dbDriver) {
            case 'sqlite':
                foreach (DB::select("select name, \"unique\" as is_unique from pragma_index_list(?)", [$name]) as $index) {
                    $columns = array_column(DB::select('select name from pragma_index_info(?) order by seqno', [$index->name]), 'name');
                    $indexes[$index->name] = ($index->is_unique ? 'unique ' : '') . implode(',', $columns);
                }
                break;
            case 'pgsql':
                foreach (DB::select('select indexname, indexdef from pg_indexes where tablename = ? and schemaname = current_schema()', [$name]) as $index) {
                    $indexes[$index->indexname] = preg_replace('/^.* USING /', '', $index->indexdef) . (str_contains($index->indexdef, 'UNIQUE') ? ' unique' : '');
                }
                break;
            case 'sqlsrv':
                foreach (DB::select(
                    'select i.name, i.is_unique, c.name as col from sys.indexes i join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id'
                    // Not the primary key: SQL Server names it anew each time the table is created.
                    . ' join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id where i.object_id = object_id(?) and i.is_primary_key = 0 order by i.name, ic.key_ordinal',
                    [$name]
                ) as $row) {
                    $indexes[$row->name] = ($indexes[$row->name] ?? ($row->is_unique ? 'unique ' : '')) . $row->col . ',';
                }
                break;
            default:
                foreach (DB::select(
                    'select index_name as name, non_unique as non_unique, column_name as col from information_schema.statistics'
                    . ' where table_schema = database() and table_name = ? order by index_name, seq_in_index',
                    [$name]
                ) as $row) {
                    $indexes[$row->name] = ($indexes[$row->name] ?? ($row->non_unique ? '' : 'unique ')) . $row->col . ',';
                }
        }
        ksort($indexes);

        $columns = Schema::getColumnListing($table);
        sort($columns);

        return ['columns' => $columns, 'indexes' => $indexes];
    }

    /** A rollback, then migrate, of each 2.1 migration that changes a table ends at the fresh install's schema. */
    #[DataProvider('interruptions')]
    public function test_a_rollback_then_migrate_ends_at_the_fresh_schema(string $migration, string $table, string $after): void
    {
        $table = $table === 'fuzzy_search_logs' ? SearchAnalytics::table() : $table;
        $fresh = $this->schema($table);

        $this->rollBackTo($migration);
        $this->migrate();

        // On Laravel 10 with doctrine/dbal, SQLite's dropColumn() rebuilt fuzzy_index_postings
        // through Doctrine, which added an index of its own on the term_id foreign key (IDX_…).
        $this->assertSame($fresh, $this->schema($table));
    }

    #[DataProvider('interruptions')]
    public function test_migrate_runs_again_after_a_failure_after_the_first_statement(string $migration, string $table, string $after): void
    {
        $table = $table === 'fuzzy_search_logs' ? SearchAnalytics::table() : $table;

        // The reference is an uninterrupted run from the same starting point, not the fresh
        // install: on Laravel 10 with doctrine/dbal, SQLite's down() of the column_name migration
        // rebuilds the table through Doctrine, which adds an index of its own on term_id
        // (IDX_…), and a migrate after it keeps that index whether or not it was interrupted.
        $this->rollBackTo($migration);
        $this->migrate();
        $clean = $this->schema($table);

        $this->rollBackTo($migration);
        if ($table === 'fuzzy_index_terms') {
            // Words a 2.0 index holds, for the backfill: it runs on the second migrate.
            DB::table('fuzzy_index_terms')->insert([['term' => 'zebra', 'doc_count' => 1], ['term' => 'café', 'doc_count' => 1], ['term' => '東京', 'doc_count' => 1]]);
        }

        $this->migrateFailingAfter($after);
        $this->migrate();

        $this->assertTrue(DB::table('migrations')->where('migration', $migration)->exists(), 'the second migrate records it');
        $this->assertSame($clean, $this->schema($table));
        if ($table === 'fuzzy_index_terms') {
            $this->assertSame(['café' => 4, 'zebra' => 5, '東京' => 2], DB::table('fuzzy_index_terms')->pluck('term_length', 'term')->map(fn ($l) => (int) $l)->sortKeys(SORT_STRING)->all());
        }
    }

    /**
     * What a failure in the old column_name migration left on MySQL/MariaDB: the column added and
     * the old key kept, or the column added and no unique key at all. migrate finishes either.
     */
    public function test_the_column_name_migration_finishes_a_key_swap_an_earlier_run_left(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB only: the other databases roll a failed migration back whole, so no run leaves a half-swapped key; the CI MySQL and MariaDB jobs run this.');
        }

        $migration = '2026_09_18_000001_add_column_name_to_fuzzy_index_postings_table';
        $clean     = $this->schema('fuzzy_index_postings');

        foreach (['the old key kept' => false, 'no unique key' => true] as $label => $dropKey) {
            $this->rollBackTo($migration);
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->string('column_name', 64)->default(''));
            if ($dropKey) {
                Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->dropUnique('postings_unique_idx'));
            }

            $this->migrate();

            $this->assertTrue(DB::table('migrations')->where('migration', $migration)->exists(), $label);
            $this->assertSame($clean, $this->schema('fuzzy_index_postings'), $label);
        }
    }

    /** A term_length column an earlier run added: migrate fills the words it left at 0. */
    public function test_the_term_length_migration_backfills_the_words_an_earlier_run_left_at_zero(): void
    {
        $migration = '2026_09_17_000001_add_term_length_to_fuzzy_index_terms_table';
        $this->rollBackTo($migration);
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->unsignedSmallInteger('term_length')->default(0)->index('fuzzy_index_terms_term_length_index'));
        DB::table('fuzzy_index_terms')->insert([['term' => 'zebra', 'doc_count' => 1, 'term_length' => 0], ['term' => 'kept', 'doc_count' => 1, 'term_length' => 9]]);

        $this->migrate();

        $this->assertTrue(DB::table('migrations')->where('migration', $migration)->exists());
        $this->assertSame(['kept' => 9, 'zebra' => 5], DB::table('fuzzy_index_terms')->pluck('term_length', 'term')->map(fn ($l) => (int) $l)->sortKeys(SORT_STRING)->all());
    }
}
