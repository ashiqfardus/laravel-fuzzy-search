<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'fuzzy_index_terms_term_length_index';

    /** 2026_10_01_000001's indexes on term_length: the current one, and an earlier 2.1 build's. */
    private const LATER = ['fuzzy_index_terms_term_length_doc_count_id_index', 'fuzzy_index_terms_term_length_doc_count_index'];

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        // `migrate --pretend` runs no select: it prints what an upgrade runs, on a 2.0.1 dictionary.
        $pretending = DB::connection()->pretending();

        // A run that failed after a step left it: on MySQL, MariaDB and SQLite the migrator runs a
        // migration outside a transaction, so each statement stays, and it records the migration
        // only once up() returns. Neither the column nor its index is added twice. Two statements
        // on purpose: InnoDB adds the column instantly and builds the index in place, while one
        // ALTER doing both rebuilt the whole dictionary.
        if ($pretending || !Schema::hasColumn('fuzzy_index_terms', 'term_length')) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->unsignedSmallInteger('term_length')->default(0));
        }
        if ($pretending || !in_array(self::INDEX, $this->indexes(), true)) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->index('term_length', self::INDEX));
        }

        // Backfill dictionaries built before this column existed, so TermExpander can filter by
        // length immediately; on every run, for the words a failed run left at 0. The character
        // (not byte) length, as the indexer's mb_strlen(): CHAR_LENGTH (MySQL/MariaDB), LENGTH
        // (PostgreSQL, SQLite). SQL Server's LEN counts a character outside the BMP (𠮷, 𝓳) as two
        // under a collation without _SC, the database's default included, so it counts under one:
        // such a word got a length its typos and didYouMean() never reach.
        $length = $driver === DbDialect::SQLSRV
            ? 'LEN(term COLLATE Latin1_General_100_CI_AS_SC)'
            : DbDialect::lengthFunction($driver) . '(term)';
        DB::statement('UPDATE ' . DbDialect::rawIdentifier('fuzzy_index_terms') . " SET term_length = {$length} WHERE term_length = 0");
    }

    public function down(): void
    {
        // hasColumn (not just hasTable): a test that recreates fuzzy_index_terms from the
        // pre-term_length migration leaves the table present but without this column/index,
        // and hasColumn() is false for a missing table too, so one check covers both cases. Under
        // `migrate:rollback --pretend`, which runs no select, the column is there, as up() left it.
        if (!DB::connection()->pretending() && !Schema::hasColumn('fuzzy_index_terms', 'term_length')) {
            return; // the create-table migration's down() already removed it (or a test did)
        }

        // Rolled back on its own (migrate:rollback --path) under 2026_10_01_000001, which replaced
        // this migration's index with its own on (term_length, doc_count, id): dropping the column
        // under that index fails on SQLite and SQL Server, cuts the index short on MySQL and
        // MariaDB, and drops it on PostgreSQL, with 2026_10_01_000001 still recorded. Refuse first.
        if (!DB::connection()->pretending() && array_intersect(self::LATER, $this->indexes()) !== []) {
            throw new \RuntimeException('Roll back 2026_10_01_000001_rework_fuzzy_index_terms_and_postings_indexes first: its index on term_length is still there.');
        }

        // Two statements on purpose: SQLite refuses to drop a column an index still references.
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex(self::INDEX));

        if (DB::connection()->getDriverName() !== DbDialect::SQLITE) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropColumn('term_length'));

            return;
        }

        // SQLite 3.35+ drops the column itself. Laravel 10's dropColumn() with doctrine/dbal
        // installed (Filament 3 requires it) rebuilt the table through Doctrine instead, and its
        // DROP TABLE fuzzy_index_terms, with foreign keys on, deleted every posting (ON DELETE
        // CASCADE). Below 3.35 dropColumn() rebuilds the table, so foreign keys are off for both.
        Schema::withoutForeignKeyConstraints(fn () => version_compare((string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), '3.35.0', '>=')
            ? DB::statement('ALTER TABLE ' . DB::getQueryGrammar()->wrapTable('fuzzy_index_terms') . ' DROP COLUMN term_length')
            : Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropColumn('term_length')));
    }

    /**
     * The names of the indexes on fuzzy_index_terms, read from the catalog (Laravel 10 has no
     * Schema::getIndexes()), each row cast to an object whatever the app's fetch mode.
     *
     * @return list<string>
     */
    private function indexes(): array
    {
        $connection = DB::connection();
        $table      = $connection->getTablePrefix() . 'fuzzy_index_terms';

        $rows = match ($connection->getDriverName()) {
            DbDialect::SQLITE => $connection->select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$table]),
            DbDialect::PGSQL  => $connection->select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$table]),
            DbDialect::SQLSRV => $connection->select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$table]),
            default           => $connection->select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$table]),
        };

        return array_map(fn ($row) => strtolower((string) ((object) $row)->name), $rows);
    }
};
