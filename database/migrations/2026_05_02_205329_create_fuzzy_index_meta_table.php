<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuzzy_index_meta', function (Blueprint $table) {
            $table->id();
            $table->string('model_type', 191)->unique();
            $table->unsignedBigInteger('total_docs')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->decimal('avg_doc_length', 12, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuzzy_index_meta');
    }
};
