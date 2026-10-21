<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds fuzzy_index_meta.flushing_since: when a batched flush or clear (MySQL/MariaDB, which commit
 * it in batches) of the model began. The meta row is deleted by the transaction that ends the
 * flush, so the column is null on every index that is not part way through one; a flush that
 * died leaves it set, and a plain `fuzzy-search:rebuild` of the model then refuses until the flush
 * is run again (the documents the flush had not reached have lost their postings, and indexing
 * them counts their words a second time).
 *
 * One row a model, so it is instant on every database. Guarded, so a run that stopped after the
 * ALTER finishes when migrate is run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fuzzy_index_meta', 'flushing_since')) {
            Schema::table('fuzzy_index_meta', fn (Blueprint $table) => $table->timestamp('flushing_since')->nullable());
        }
    }

    public function down(): void
    {
        // `migrate:rollback --pretend` runs no select: the column as up() left it.
        if (DB::connection()->pretending() || Schema::hasColumn('fuzzy_index_meta', 'flushing_since')) {
            Schema::table('fuzzy_index_meta', fn (Blueprint $table) => $table->dropColumn('flushing_since'));
        }
    }
};
