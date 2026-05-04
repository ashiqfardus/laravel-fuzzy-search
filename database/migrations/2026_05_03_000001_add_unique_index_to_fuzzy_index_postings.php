<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove any duplicate rows that would block the unique constraint.
        // Keeps the row with the lowest id for each (term_id, model_type, model_id) triple.
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'DELETE p1 FROM fuzzy_index_postings p1 ' .
                'INNER JOIN fuzzy_index_postings p2 ' .
                'ON p1.term_id = p2.term_id ' .
                '   AND p1.model_type = p2.model_type ' .
                '   AND p1.model_id = p2.model_id ' .
                '   AND p1.id > p2.id'
            );
        } else {
            // PostgreSQL, SQLite, and SQL Server all allow NOT IN on the same table
            DB::statement(
                'DELETE FROM fuzzy_index_postings ' .
                'WHERE id NOT IN (' .
                '    SELECT MIN(id) FROM fuzzy_index_postings ' .
                '    GROUP BY term_id, model_type, model_id' .
                ')'
            );
        }

        Schema::table('fuzzy_index_postings', fn (Blueprint $t) =>
            $t->unique(['term_id', 'model_type', 'model_id'], 'postings_unique_idx')
        );
    }

    public function down(): void
    {
        Schema::table('fuzzy_index_postings', fn (Blueprint $t) =>
            $t->dropUnique('postings_unique_idx')
        );
    }
};
