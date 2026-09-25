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
        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->unsignedSmallInteger('term_length')->default(0)->index('fuzzy_index_terms_term_length_index');
        });

        // Backfill dictionaries built before this column existed, so TermExpander can
        // filter by length immediately. lengthFunction() is the character (not byte) length
        // on every driver: CHAR_LENGTH (MySQL/MariaDB), LEN (SQL Server), LENGTH (others).
        $length = DbDialect::lengthFunction(DB::connection()->getDriverName());
        DB::statement('UPDATE ' . DbDialect::rawIdentifier('fuzzy_index_terms') . " SET term_length = {$length}(term)");
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
