<?php

use Ashiqfardus\LaravelFuzzySearch\Analytics\SearchAnalytics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // analytics.table, the name SearchAnalytics writes and reads.
    public function up(): void
    {
        Schema::create(SearchAnalytics::table(), function (Blueprint $table) {
            $table->id();
            $table->string('term', 255);                 // '' when analytics.hash_terms is on
            // Lower-cased, whitespace-collapsed (or its keyed sha256). 191, as every indexed
            // string: 255 utf8mb4 characters made a 1,020-byte key, over MySQL's 767-byte limit
            // for the COMPACT row format.
            $table->string('normalized_term', 191);
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
        Schema::dropIfExists(SearchAnalytics::table());
    }
};
