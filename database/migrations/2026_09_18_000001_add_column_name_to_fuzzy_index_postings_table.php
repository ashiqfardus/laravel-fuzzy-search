<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuzzy_index_postings', function (Blueprint $table) {
            // Which searchable column (or searchableText() key) the term came from. '' marks
            // rows written before column weighting existed; they score at weight 1.
            // NOT NULL on purpose: SQL Server treats NULLs as equal in unique indexes while the
            // other drivers treat them as distinct, and an upsert cannot match a NULL key.
            $table->string('column_name', 64)->default('')->after('model_id');
        });

        Schema::table('fuzzy_index_postings', function (Blueprint $table) {
            $table->dropUnique('postings_unique_idx');
            $table->unique(['term_id', 'model_type', 'model_id', 'column_name'], 'postings_unique_idx');
            $table->index(['term_id', 'model_type', 'column_name'], 'postings_term_model_column_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('fuzzy_index_postings', 'column_name')) {
            return;
        }

        // Per-column rows would violate the legacy three-column unique key; drop them and let
        // the user rebuild (documented in the upgrade guide).
        DB::table('fuzzy_index_postings')->where('column_name', '!=', '')->delete();

        Schema::table('fuzzy_index_postings', function (Blueprint $table) {
            $table->dropIndex('postings_term_model_column_idx');
            $table->dropUnique('postings_unique_idx');
            $table->unique(['term_id', 'model_type', 'model_id'], 'postings_unique_idx');
        });
        Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->dropColumn('column_name'));
    }
};
