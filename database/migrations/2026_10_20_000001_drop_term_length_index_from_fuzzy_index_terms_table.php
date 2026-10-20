<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the (term_length, doc_count, id) index 2026_10_01_000001 adds to fuzzy_index_terms. No read
 * uses it: the typo expansion, didYouMean(), suggest() and the as-you-type prefixes read each model's
 * own words in fuzzy_index_model_terms (2026_10_13_000001), through that table's keys, and nothing
 * else filters or orders the shared dictionary by term_length or doc_count. Yet every index write
 * that changed a word's doc_count moved the word's entry in it, and on MySQL/MariaDB took locks in
 * it. A migration of its own, so an install that ran the migrations before it drops it too.
 *
 * Dropping an index reads no rows: quick on every database. down() builds it again, so
 * 2026_10_01_000001's down() finds the dictionary as that migration left it.
 */
return new class extends Migration
{
    private const LENGTH_COUNT_ID = 'fuzzy_index_terms_term_length_doc_count_id_index';

    public function up(): void
    {
        // `migrate --pretend` runs no select: the index as 2026_10_01_000001 leaves it.
        if (DB::connection()->pretending() || in_array(self::LENGTH_COUNT_ID, $this->indexes(), true)) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex(self::LENGTH_COUNT_ID));
        }
    }

    public function down(): void
    {
        // A column a test removed (as 2026_09_17_000001's down() allows) has nothing to index.
        // `migrate:rollback --pretend` runs no select: the dictionary as up() left it.
        if (DB::connection()->pretending()
            || (Schema::hasColumn('fuzzy_index_terms', 'term_length') && !in_array(self::LENGTH_COUNT_ID, $this->indexes(), true))) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->index(['term_length', 'doc_count', 'id'], self::LENGTH_COUNT_ID));
        }
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
            // Resolved through search_path, as the DDL that drops it is.
            DbDialect::PGSQL  => $connection->select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$table]),
            DbDialect::SQLSRV => $connection->select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$table]),
            default           => $connection->select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$table]),
        };

        return array_map(fn ($row) => strtolower((string) ((object) $row)->name), $rows);
    }
};
