<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuzzy_index_terms', function (Blueprint $table) {
            $table->id();
            $table->string('term', 191)->unique();
            $table->unsignedBigInteger('doc_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuzzy_index_terms');
    }
};
