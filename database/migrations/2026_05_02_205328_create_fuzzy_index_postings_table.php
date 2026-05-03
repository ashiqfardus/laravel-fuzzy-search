<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuzzy_index_postings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('term_id');
            $table->string('model_type', 191);
            $table->string('model_id', 36); // supports both integer PKs and UUID/ULID
            $table->unsignedInteger('frequency')->default(1);

            $table->index(['term_id', 'model_type'], 'postings_term_model_idx');
            $table->index(['model_type', 'model_id'], 'postings_model_idx');
            $table->foreign('term_id')
                  ->references('id')
                  ->on('fuzzy_index_terms')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuzzy_index_postings');
    }
};
