<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
