<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuzzy_index_documents', function (Blueprint $table) {
            $table->string('model_type', 191);
            $table->string('model_id', 36); // supports both integer PKs and UUID/ULID
            $table->unsignedInteger('doc_length')->default(0);

            $table->primary(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuzzy_index_documents');
    }
};
