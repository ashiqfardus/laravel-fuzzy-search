<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // A run that failed after this step left the column: on MySQL, MariaDB and SQLite the
        // migrator runs a migration outside a transaction, so each statement stays, and it records
        // the migration only once up() returns. The column is not added twice.
        if (!Schema::hasColumn('fuzzy_index_terms', 'term_length')) {
            if (DbDialect::isMySqlFamily($driver)) {
                // One ALTER, which InnoDB applies whole: the column and its index, or neither.
                DB::statement('ALTER TABLE ' . DbDialect::rawIdentifier('fuzzy_index_terms')
                    . ' ADD COLUMN term_length SMALLINT UNSIGNED NOT NULL DEFAULT 0, ADD INDEX fuzzy_index_terms_term_length_index (term_length)');
            } else {
                Schema::table('fuzzy_index_terms', function (Blueprint $table) {
                    $table->unsignedSmallInteger('term_length')->default(0)->index('fuzzy_index_terms_term_length_index');
                });
            }
        }

        // Backfill dictionaries built before this column existed, so TermExpander can filter by
        // length immediately; on every run, for the words a failed run left at 0. lengthFunction()
        // is the character (not byte) length on every driver: CHAR_LENGTH (MySQL/MariaDB), LEN
        // (SQL Server), LENGTH (others).
        $length = DbDialect::lengthFunction($driver);
        DB::statement('UPDATE ' . DbDialect::rawIdentifier('fuzzy_index_terms') . " SET term_length = {$length}(term) WHERE term_length = 0");
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
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex('fuzzy_index_terms_term_length_index'));
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropColumn('term_length'));
    }
};
