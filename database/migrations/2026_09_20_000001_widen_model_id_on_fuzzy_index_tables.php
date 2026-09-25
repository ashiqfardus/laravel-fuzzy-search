<?php

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * model_id grows from 36 to 191 characters on fuzzy_index_postings and fuzzy_index_documents,
 * so string primary keys longer than a UUID can be indexed. 191 keeps every key that covers the
 * column inside the index limits: on MySQL/MariaDB (utf8mb4, 4 bytes a character) the widest,
 * postings_unique_idx (term_id, model_type, model_id, column_name), is 8 + 764 + 764 + 256 =
 * 1,792 bytes of InnoDB's 3,072, and no column passes the 767-byte prefix limit of the old
 * COMPACT row format; on SQL Server (nvarchar, 2 bytes) it is 8 + 382 + 382 + 128 = 900 bytes
 * of a nonclustered index's 1,700, and the documents primary key 764 of a clustered one's 900.
 *
 * On MySQL/MariaDB model_id also takes utf8mb4_bin, as term has since 2026_09_17_000002: under
 * the connection's case- and accent-insensitive collation, keys that differ only by case or
 * accents (sqids, hashids, base62: aBc and AbC) were one key, so the second overwrote the first's
 * document. PostgreSQL and SQLite compare it byte-wise already; SQL Server keeps its default
 * collation, a documented limit.
 *
 * Raw ALTERs, not ->change(): Laravel 10 needs doctrine/dbal for that. SQLite does not enforce a
 * varchar length, so there is nothing to change there. SQL Server refuses to alter a column a
 * primary key covers, so the documents primary key is dropped and recreated around the ALTER; it
 * widens an nvarchar under an ordinary or unique index in place, so the postings indexes (the
 * costly rebuild on a large index) are dropped and recreated only when down() narrows it.
 */
return new class extends Migration
{
    private const TABLES = ['fuzzy_index_postings', 'fuzzy_index_documents'];

    public function up(): void
    {
        $this->resize(191, self::TABLES);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === DbDialect::SQLITE) {
            return;
        }

        // A table already gone (a test that dropped it) has nothing to narrow.
        $tables = array_values(array_filter(self::TABLES, fn (string $table) => Schema::hasTable($table)));

        // Keys longer than 36 characters do not fit the old column, and on MySQL/MariaDB keys that
        // differ only by case or accents (any two keys the table's collation compares equal) are
        // one key again under the old collation, which would violate the documents primary key.
        // Those models leave the index the way a deleted model does, giving back their doc_count
        // and meta totals; rebuild them after rolling back if they must stay searchable. Postings
        // with no document row left (none in a consistent index) are then dropped as they are.
        $tooLong = DbDialect::lengthFunction($driver) . '(model_id) > 36';
        if (in_array('fuzzy_index_documents', $tables, true)) {
            $documents = DB::table('fuzzy_index_documents')->whereRaw($tooLong)->get(['model_type', 'model_id'])
                ->concat(DbDialect::isMySqlFamily($driver) ? $this->collidingKeys() : []);
            foreach ($documents as $document) {
                app(IndexManager::class)->removeFromIndex($document->model_type, $document->model_id);
            }
        }
        foreach ($tables as $table) {
            DB::table($table)->whereRaw($tooLong)->delete();
        }

        $this->resize(36, $tables);
    }

    /**
     * MySQL/MariaDB: the documents whose key equals another key of the same model type under the
     * collation down() gives model_id back, the table's default, which model_type still has.
     *
     * @return list<object{model_type: string, model_id: string}>
     */
    private function collidingKeys(): array
    {
        $column = DB::selectOne(
            'select character_set_name as charset, collation_name as collation from information_schema.columns'
            . ' where table_schema = database() and table_name = ? and column_name = ?',
            [DB::connection()->getTablePrefix() . 'fuzzy_index_documents', 'model_type']
        );
        if ($column === null || !preg_match('/^\w+$/', (string) $column->charset) || !preg_match('/^\w+$/', (string) $column->collation)) {
            return [];
        }

        $table  = DbDialect::rawIdentifier('fuzzy_index_documents');
        $folded = fn (string $key) => "CAST({$key} AS CHAR CHARACTER SET {$column->charset}) COLLATE {$column->collation}";

        return DB::select(
            "select d.model_type, d.model_id from {$table} d join (select model_type, {$folded('model_id')} as folded from {$table}"
            . " group by model_type, folded having count(*) > 1) g on g.model_type = d.model_type and g.folded = {$folded('d.model_id')}"
        );
    }

    /** @param list<string> $tables */
    private function resize(int $length, array $tables): void
    {
        $driver    = DB::connection()->getDriverName();
        $documents = in_array('fuzzy_index_documents', $tables, true);
        // SQL Server widens an nvarchar under an ordinary or unique index in place; only narrowing
        // (down()) needs the postings indexes out of the way. A primary key blocks both.
        $postings  = in_array('fuzzy_index_postings', $tables, true) && $length < 191;

        if ($driver === DbDialect::SQLSRV && $postings) {
            Schema::table('fuzzy_index_postings', function (Blueprint $table) {
                $table->dropUnique('postings_unique_idx');
                $table->dropIndex('postings_model_idx');
            });
        }
        if ($driver === DbDialect::SQLSRV && $documents) {
            Schema::table('fuzzy_index_documents', fn (Blueprint $table) => $table->dropPrimary(['model_type', 'model_id']));
        }

        // MySQL/MariaDB: up() compares model_id byte-wise; down() leaves out the character set and
        // collation, so the column takes the table's default back, as v2.0.1 created it.
        $binary = $length > 36 ? ' CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : '';

        foreach ($tables as $table) {
            $table = DbDialect::rawIdentifier($table);

            match (true) {
                DbDialect::isMySqlFamily($driver) => DB::statement("ALTER TABLE {$table} MODIFY model_id VARCHAR({$length}){$binary} NOT NULL"),
                $driver === DbDialect::PGSQL      => DB::statement("ALTER TABLE {$table} ALTER COLUMN model_id TYPE VARCHAR({$length})"),
                $driver === DbDialect::SQLSRV     => DB::statement("ALTER TABLE {$table} ALTER COLUMN model_id NVARCHAR({$length}) NOT NULL"),
                default                           => null, // SQLite: varchar lengths are not enforced
            };
        }

        if ($driver === DbDialect::SQLSRV && $postings) {
            // The key 2026_09_18_000001 left: without column_name when its column is gone (a test
            // that recreated the table), as that migration's own down() allows.
            $unique = Schema::hasColumn('fuzzy_index_postings', 'column_name')
                ? ['term_id', 'model_type', 'model_id', 'column_name']
                : ['term_id', 'model_type', 'model_id'];
            Schema::table('fuzzy_index_postings', function (Blueprint $table) use ($unique) {
                $table->unique($unique, 'postings_unique_idx');
                $table->index(['model_type', 'model_id'], 'postings_model_idx');
            });
        }
        if ($driver === DbDialect::SQLSRV && $documents) {
            Schema::table('fuzzy_index_documents', fn (Blueprint $table) => $table->primary(['model_type', 'model_id']));
        }
    }
};
