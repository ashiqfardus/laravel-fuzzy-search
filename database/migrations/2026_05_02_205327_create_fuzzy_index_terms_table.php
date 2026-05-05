<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuzzy_index_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 255);
            $table->unsignedBigInteger('doc_count')->default(0);
        });

        // MySQL: use a 191-char prefix index so the key fits within the 767-byte
        // limit on servers where innodb_large_prefix is not enabled (MySQL ≤ 5.7).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE fuzzy_index_terms ' .
                'ADD UNIQUE KEY fuzzy_index_terms_term_unique (term(191))'
            );
        } else {
            Schema::table('fuzzy_index_terms', fn (Blueprint $t) => $t->unique('term'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fuzzy_index_terms');
    }
};
