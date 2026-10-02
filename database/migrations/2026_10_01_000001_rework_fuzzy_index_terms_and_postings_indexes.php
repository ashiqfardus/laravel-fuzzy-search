<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two index changes for large indexes.
 *
 * fuzzy_index_terms reads by (term_length, doc_count, id), in place of the term_length index of
 * 2026_09_17_000001. The typo expansion and didYouMean() (TermExpander::candidates()) take the most
 * common words of each length within reach of a search term, equal counts by id. Through term_length
 * alone the database read every word of that length window and sorted them all, on every search
 * term; the window grows with the dictionary (1.3 s a term at 6M rows on MySQL). Through this index
 * it reads each length's most common words in index order. The id is in the key for PostgreSQL:
 * InnoDB, SQL Server and SQLite carry the row id in every index already, but PostgreSQL would sort
 * the whole tie group at the cut, usually most words of a length. An earlier 2.1 build of this
 * migration made the index without the id (fuzzy_index_terms_term_length_doc_count_index); it is
 * replaced. Nothing else filters on term_length.
 *
 * postings_term_model_idx (term_id, model_type) goes: postings_unique_idx (term_id, model_type,
 * model_id, column_name) starts with the same columns, so it serves every read the smaller one did,
 * and covers them, where the planner took the smaller one and then read each row for model_id. It
 * also serves MySQL's foreign key on term_id. At 6M rows it was 5.2 GB of the 27.9 GB postings
 * table, and every posting insert kept it up.
 *
 * Each step looks for its index first, so a run that stopped part way (MySQL commits each ALTER on
 * its own) and a second run do only what is left. down() puts both indexes back.
 *
 * Outside a transaction, so each step commits on its own on PostgreSQL and SQL Server too. In one
 * transaction the lock the build takes on fuzzy_index_terms, which blocks writes, lasted until the
 * drops had run too, and on PostgreSQL an index write that had read the dictionary meanwhile
 * deadlocked with the drop: `migrate` failed.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const LENGTH_COUNT_ID = 'fuzzy_index_terms_term_length_doc_count_id_index';
    private const LENGTH_COUNT    = 'fuzzy_index_terms_term_length_doc_count_index'; // an earlier 2.1 build's
    private const LENGTH          = 'fuzzy_index_terms_term_length_index';
    private const TERM_MODEL      = 'postings_term_model_idx';

    public function up(): void
    {
        // The new index first, so the dictionary is never without a length index.
        $terms = $this->indexes('fuzzy_index_terms');
        if (!in_array(self::LENGTH_COUNT_ID, $terms, true)) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->index(['term_length', 'doc_count', 'id'], self::LENGTH_COUNT_ID));
        }
        foreach ([self::LENGTH, self::LENGTH_COUNT] as $index) {
            if (in_array($index, $terms, true)) {
                Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }

        if (in_array(self::TERM_MODEL, $this->indexes('fuzzy_index_postings'), true)) {
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->dropIndex(self::TERM_MODEL));
        }
    }

    public function down(): void
    {
        // A table or a column a test removed (as 2026_09_17_000001's down() allows) has no index to restore.
        if (Schema::hasTable('fuzzy_index_postings') && !in_array(self::TERM_MODEL, $this->indexes('fuzzy_index_postings'), true)) {
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->index(['term_id', 'model_type'], self::TERM_MODEL));
        }

        $terms = $this->indexes('fuzzy_index_terms');
        if (!in_array(self::LENGTH, $terms, true) && Schema::hasColumn('fuzzy_index_terms', 'term_length')) {
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->index('term_length', self::LENGTH));
        }
        foreach ([self::LENGTH_COUNT_ID, self::LENGTH_COUNT] as $index) {
            if (in_array($index, $terms, true)) {
                Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex($index));
            }
        }
    }

    /**
     * The names of the indexes on $table, read from the catalog (Laravel 10 has no
     * Schema::getIndexes()); [] for a table that does not exist.
     *
     * @return list<string>
     */
    private function indexes(string $table): array
    {
        $connection = DB::connection();
        $table      = $connection->getTablePrefix() . $table;

        $rows = match ($connection->getDriverName()) {
            DbDialect::SQLITE => $connection->select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$table]),
            // Resolved through search_path, as the DDL that creates or drops it is.
            DbDialect::PGSQL  => $connection->select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$table]),
            DbDialect::SQLSRV => $connection->select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$table]),
            default           => $connection->select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$table]),
        };

        return array_map(fn ($row) => strtolower((string) $row->name), $rows);
    }
};
