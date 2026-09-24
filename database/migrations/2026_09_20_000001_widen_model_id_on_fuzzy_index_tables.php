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
 * Raw ALTERs, not ->change(): Laravel 10 needs doctrine/dbal for that. SQLite does not enforce a
 * varchar length, so there is nothing to change there. SQL Server refuses to alter a column an
 * index or primary key covers, so those are dropped and recreated around the ALTER.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resize(191);
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === DbDialect::SQLITE) {
            return;
        }

        // Keys longer than 36 characters do not fit the old column: drop their rows, and rebuild
        // the affected models after rolling back (php artisan fuzzy-search:rebuild --fresh).
        foreach (['fuzzy_index_postings', 'fuzzy_index_documents'] as $table) {
            DB::table($table)->whereRaw(DbDialect::lengthFunction($driver) . '(model_id) > 36')->delete();
        }

        $this->resize(36);
    }

    private function resize(int $length): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === DbDialect::SQLSRV) {
            Schema::table('fuzzy_index_postings', function (Blueprint $table) {
                $table->dropUnique('postings_unique_idx');
                $table->dropIndex('postings_model_idx');
            });
            Schema::table('fuzzy_index_documents', fn (Blueprint $table) => $table->dropPrimary(['model_type', 'model_id']));
        }

        foreach (['fuzzy_index_postings', 'fuzzy_index_documents'] as $table) {
            $table = IndexManager::rawIdentifier($table);

            match (true) {
                DbDialect::isMySqlFamily($driver) => DB::statement("ALTER TABLE {$table} MODIFY model_id VARCHAR({$length}) NOT NULL"),
                $driver === DbDialect::PGSQL      => DB::statement("ALTER TABLE {$table} ALTER COLUMN model_id TYPE VARCHAR({$length})"),
                $driver === DbDialect::SQLSRV     => DB::statement("ALTER TABLE {$table} ALTER COLUMN model_id NVARCHAR({$length}) NOT NULL"),
                default                           => null, // SQLite: varchar lengths are not enforced
            };
        }

        if ($driver === DbDialect::SQLSRV) {
            Schema::table('fuzzy_index_postings', function (Blueprint $table) {
                $table->unique(['term_id', 'model_type', 'model_id', 'column_name'], 'postings_unique_idx');
                $table->index(['model_type', 'model_id'], 'postings_model_idx');
            });
            Schema::table('fuzzy_index_documents', fn (Blueprint $table) => $table->primary(['model_type', 'model_id']));
        }
    }
};
