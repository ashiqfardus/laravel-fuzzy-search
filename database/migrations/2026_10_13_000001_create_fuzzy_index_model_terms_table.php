<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-model term statistics (ruling ER-197, T-1): one row per model type and dictionary word it
 * posts, with the number of its documents holding the word (doc_count), the word's text and its
 * length, copied from fuzzy_index_terms. Every index write keeps it in step with the dictionary's
 * doc_count, in the same transaction (IndexManager). The typo expansion and the as-you-type prefix
 * of a model read it, so a read is bounded by the model's own words: through the shared
 * dictionary, another model's words of a length, or under a prefix (1M product SKU codes beside
 * the users), were read whole before the model's own turned up.
 *
 * The keys: the primary key (model_type_id, term_id); MT_LENGTH (model_type_id, term_length,
 * doc_count, term_id), the typo read's per length, most common first; MT_TERM (model_type_id,
 * term), the prefix read's range (on PostgreSQL with varchar_pattern_ops, which serves its
 * LIKE 'abc%' as a range under any collation; on MySQL/MariaDB on term(191), as the dictionary
 * keys it, so the key fits 767 bytes under a COMPACT or REDUNDANT row format: no word is longer,
 * Pipeline::MAX_TERM_LENGTH); MT_TERM_ID (term_id), which the foreign key's
 * ON DELETE CASCADE reads when a flush's orphan sweep deletes words (and MySQL's foreign key needs).
 * The term column is the dictionary's, so its comparisons and ranges are the dictionary's:
 * VARCHAR(255) utf8mb4_bin on MySQL/MariaDB (2026_09_17_000002), NVARCHAR(255)
 * Latin1_General_100_BIN2 on SQL Server (2026_09_30_000001), the database's own elsewhere.
 *
 * The index names carry the table prefix as Laravel prefixes its own (prefix_indexes): PostgreSQL
 * and SQLite name an index once per schema, SQL Server a primary key (VJ-5).
 *
 * This creates the table only; 2026_10_13_000002 fills it from an existing index. Its state is
 * BACKFILL, created here, before any index write can add a row to the table: one row, the last
 * term id the backfill counted (0: none). 2026_10_13_000002 goes on from it, and drops the table
 * once it has counted every word, so a table without it is complete. The rows index writes add
 * meanwhile say nothing about how far the backfill got.
 */
return new class extends Migration
{
    private const PRIMARY    = 'model_terms_primary';
    private const MT_LENGTH  = 'model_terms_length_idx';
    private const MT_TERM    = 'model_terms_term_idx';
    private const MT_TERM_ID = 'model_terms_term_id_idx';
    private const BACKFILL   = 'fuzzy_index_model_terms_backfill';

    public function up(): void
    {
        $connection = DB::connection();
        $driver     = $connection->getDriverName();
        $prefix     = $connection->getConfig('prefix_indexes') ? $connection->getTablePrefix() : '';

        // A run that stopped part way left the table without some of its keys (MySQL runs each
        // statement on its own): it holds no data an index write could not write again, and the
        // backfill after this one counts it afresh.
        Schema::dropIfExists(self::BACKFILL);
        Schema::dropIfExists('fuzzy_index_model_terms');

        // A primary key, as every table needs under MySQL's sql_require_primary_key (VA-3); its one row is updated in place.
        Schema::create(self::BACKFILL, function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_term_id')->default(0);
        });
        DB::table(self::BACKFILL)->insert(['id' => 1, 'last_term_id' => 0]);

        Schema::create('fuzzy_index_model_terms', function (Blueprint $table) use ($driver, $prefix) {
            $table->unsignedSmallInteger('model_type_id');
            $table->unsignedBigInteger('term_id');
            $term = $table->string('term', 255);
            if (DbDialect::isMySqlFamily($driver)) {
                $term->charset('utf8mb4')->collation('utf8mb4_bin');
            } elseif ($driver === DbDialect::SQLSRV) {
                $term->collation('Latin1_General_100_BIN2');
            }
            $table->unsignedSmallInteger('term_length');
            $table->unsignedBigInteger('doc_count')->default(0);

            $table->primary(['model_type_id', 'term_id'], $prefix . self::PRIMARY);
            $table->index(['model_type_id', 'term_length', 'doc_count', 'term_id'], $prefix . self::MT_LENGTH);
            if ($driver !== DbDialect::PGSQL && !DbDialect::isMySqlFamily($driver)) {
                $table->index(['model_type_id', 'term'], $prefix . self::MT_TERM);
            }
            $table->index('term_id', $prefix . self::MT_TERM_ID);

            $table->foreign('model_type_id')->references('id')->on('fuzzy_index_model_types');
            $table->foreign('term_id')->references('id')->on('fuzzy_index_terms')->onDelete('cascade');
        });

        $table = DB::getQueryGrammar()->wrapTable('fuzzy_index_model_terms');
        if ($driver === DbDialect::PGSQL) {
            DB::statement('create index ' . DbDialect::quoteIdentifier($prefix . self::MT_TERM, $driver) . " on {$table} (model_type_id, term varchar_pattern_ops)");
        } elseif (DbDialect::isMySqlFamily($driver)) {
            DB::statement("alter table {$table} add index " . DbDialect::quoteIdentifier($prefix . self::MT_TERM, $driver) . ' (model_type_id, term(191))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::BACKFILL); // still there after a backfill that stopped part way
        Schema::dropIfExists('fuzzy_index_model_terms');
    }
};
