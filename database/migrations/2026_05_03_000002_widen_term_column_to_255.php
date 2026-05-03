<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->string('term', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fuzzy_index_terms', function (Blueprint $table) {
            $table->string('term', 191)->change();
        });
    }
};
