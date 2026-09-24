<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL/MariaDB default to a utf8mb4_*_ci collation, under which café = cafe and
        // Café = café. The unique key on `term` then collapses variants the tokenizer keeps
        // apart, and indexing a document containing both crashed with "Undefined array key"
        // (B25). Every other driver already compares the column byte-wise; utf8mb4_bin makes
        // MySQL/MariaDB agree. All terms are lowercased by the tokenizer, so searches are
        // unaffected. The key is a term(191) prefix — a stricter collation cannot violate it.
        if (!DbDialect::isMySqlFamily(DB::connection()->getDriverName())) {
            return;
        }

        DB::statement('ALTER TABLE ' . DbDialect::rawIdentifier('fuzzy_index_terms') . ' MODIFY term VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        // Deliberate no-op: once the dictionary holds rows that differ only by accent or case,
        // restoring a case-insensitive collation would violate the unique key on `term`.
        // Rolling back means rebuilding the index (php artisan fuzzy-search:rebuild --fresh).
    }
};
