<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'fuzzy_index_terms_term_length_index';

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // A run that failed after a step left it: on MySQL, MariaDB and SQLite the migrator runs a
        // migration outside a transaction, so each statement stays, and it records the migration
        // only once up() returns. Neither the column nor its index is added twice. Two statements
        // on purpose: InnoDB adds the column instantly and builds the index in place, while one
        // ALTER doing both rebuilt the whole dictionary.
        if (!Schema::hasColumn('fuzzy_index_terms', 'term_length')) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->unsignedSmallInteger('term_length')->default(0));
        }
        if (!in_array(self::INDEX, $this->indexes(), true)) {
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
        // and hasColumn() is false for a missing table too, so one check covers both cases.
        if (!Schema::hasColumn('fuzzy_index_terms', 'term_length')) {
            return; // the create-table migration's down() already removed it (or a test did)
        }

        // Two statements on purpose: SQLite refuses to drop a column an index still references.
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropColumn('term_length'));
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
