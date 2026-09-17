<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuzzy_search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('term', 255);                 // '' when analytics.hash_terms is on
            $table->string('normalized_term', 255);      // lower-cased, whitespace-collapsed (or its sha256)
            $table->string('model_type', 191)->nullable();
            $table->string('algorithm', 32);
            $table->string('path', 16);                  // like | bm25 | extended | in_memory
            $table->unsignedInteger('result_count');
            $table->decimal('latency_ms', 8, 2);
            $table->date('day');                         // created_at's date, for portable per-day grouping
            $table->timestamp('created_at');

            $table->index('normalized_term', 'search_logs_term_idx');
            $table->index('created_at', 'search_logs_created_idx');
            $table->index('result_count', 'search_logs_results_idx');
            $table->index('day', 'search_logs_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuzzy_search_logs');
    }
};
