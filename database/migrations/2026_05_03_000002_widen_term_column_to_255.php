<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the unique index before changing the column size.
        // On MySQL ≤ 5.7 without innodb_large_prefix, a varchar(255) utf8mb4 column
        // requires 1 020 bytes — exceeding the 767-byte key limit. We recreate the index
        // with a 191-char prefix on MySQL so the migration is safe on all MySQL versions.
        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->dropUnique(['term']);
        });

        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->string('term', 255)->change();
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Prefix index: 191 chars × 4 bytes = 764 bytes < 767-byte limit (C-C6)
            DB::statement(
                'ALTER TABLE fuzzy_index_terms ' .
                'ADD UNIQUE KEY fuzzy_index_terms_term_unique (term(191))'
            );
        } else {
            Schema::table('fuzzy_index_terms', function (Blueprint $table) {
                $table->unique('term');
            });
        }
    }

    public function down(): void
    {
        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->dropUnique(['term']);
        });

        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->string('term', 191)->change();
        });

        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->unique('term');
        });
    }
};
