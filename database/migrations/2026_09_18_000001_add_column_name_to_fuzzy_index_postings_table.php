<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which searchable column (or searchableText() key) the term came from. '' marks rows
        // written before column weighting existed; they score at weight 1.
        // NOT NULL on purpose: SQL Server treats NULLs as equal in unique indexes while the
        // other drivers treat them as distinct, and an upsert cannot match a NULL key.
        // No ->after(): it is MySQL-only syntax and forces a full table rebuild on 8.0.12–8.0.28.
        //
        // No index on column_name: every read filters on (model_type, term_id), which the unique
        // key's prefix serves; the status command's legacy-posting scan is an admin-only full scan.
        //
        // Only what is left to do: a run that failed part way left its first steps (MySQL, MariaDB
        // and SQLite run a migration outside a transaction and record it only once up() returns),
        // and the next `migrate` stopped at the column it had added. A failed key swap left the
        // table without a unique key; it is added back. `migrate --pretend` runs no select: it prints
        // what an upgrade runs, on the 2.0.1 table (no column, the three-column key).
        $pretending = DB::connection()->pretending();
        $add        = $pretending || !Schema::hasColumn('fuzzy_index_postings', 'column_name');
        $key        = $pretending ? ['term_id', 'model_type', 'model_id'] : $this->uniqueKeyColumns();
        $swap       = !in_array('column_name', $key, true);

        // The column on its own: InnoDB adds it instantly, while one ALTER that also swapped the key
        // rebuilt the whole postings table (about 6× the time).
        if ($add) {
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->string('column_name', 64)->default(''));
        }
        if ($swap && DbDialect::isMySqlFamily(DB::connection()->getDriverName())) {
            // One ALTER, which InnoDB applies whole, in place, so no failure leaves the key half
            // swapped, and the term_id foreign key is never without an index.
            DB::statement('ALTER TABLE ' . DbDialect::rawIdentifier('fuzzy_index_postings') . ' '
                . ($key !== [] ? 'DROP INDEX postings_unique_idx, ' : '') . 'ADD UNIQUE postings_unique_idx (term_id, model_type, model_id, column_name)');
        } elseif ($swap) {
            Schema::table('fuzzy_index_postings', function (Blueprint $table) use ($key) {
                if ($key !== []) {
                    $table->dropUnique('postings_unique_idx');
                }
                $table->unique(['term_id', 'model_type', 'model_id', 'column_name'], 'postings_unique_idx');
            });
        }
    }

    public function down(): void
    {
        // `migrate:rollback --pretend` runs no select: the table as up() left it.
        $pretending = DB::connection()->pretending();
        if (!$pretending && !Schema::hasColumn('fuzzy_index_postings', 'column_name')) {
            return;
        }

        // Per-column rows would violate the legacy three-column unique key; drop them and let
        // the user rebuild (documented in the upgrade guide).
        DB::table('fuzzy_index_postings')->where('column_name', '!=', '')->delete();

        // Only a key that still has column_name: a rollback that stopped after the swap is run again.
        $key = $pretending ? ['term_id', 'model_type', 'model_id', 'column_name'] : $this->uniqueKeyColumns();
        if (in_array('column_name', $key, true)) {
            if (DbDialect::isMySqlFamily(DB::connection()->getDriverName())) {
                // One ALTER. Rolled back on its own (migrate:rollback --path), with
                // 2026_10_01_000001's drop of postings_term_model_idx still in place, the key is the
                // only index serving the term_id foreign key, and MySQL refused to drop it alone
                // (1553) after the delete above had committed.
                DB::statement('ALTER TABLE ' . DbDialect::rawIdentifier('fuzzy_index_postings')
                    . ' DROP INDEX postings_unique_idx, ADD UNIQUE postings_unique_idx (term_id, model_type, model_id)');
            } else {
                Schema::table('fuzzy_index_postings', function (Blueprint $table) {
                    $table->dropUnique('postings_unique_idx');
                    $table->unique(['term_id', 'model_type', 'model_id'], 'postings_unique_idx');
                });
            }
        }

        if (DB::connection()->getDriverName() !== DbDialect::SQLITE) {
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->dropColumn('column_name'));

            return;
        }

        // SQLite 3.35+ drops the column itself. Laravel 10's dropColumn() with doctrine/dbal
        // installed (Filament 3 requires it) rebuilt the table through Doctrine instead, which added
        // an index of its own on the term_id foreign key that a later migrate kept. Foreign keys
        // are off around it, as in the term_length migration's down(): below 3.35 dropColumn()
        // rebuilds the table.
        Schema::withoutForeignKeyConstraints(fn () => version_compare((string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), '3.35.0', '>=')
            ? DB::statement('ALTER TABLE ' . DB::getQueryGrammar()->wrapTable('fuzzy_index_postings') . ' DROP COLUMN column_name')
            : Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->dropColumn('column_name')));
    }

    /**
     * The columns of postings_unique_idx, read from the catalog (Laravel 10 has no
     * Schema::getIndexes()); [] when the table has no such key. Each row is cast to an object, as
     * Laravel's own schema reads do: an app-wide array fetch mode (a StatementPrepared listener
     * that sets PDO::FETCH_ASSOC) applies to `migrate` too.
     *
     * @return list<string>
     */
    private function uniqueKeyColumns(): array
    {
        $connection = DB::connection();
        $table      = $connection->getTablePrefix() . 'fuzzy_index_postings';

        $rows = match ($connection->getDriverName()) {
            DbDialect::SQLITE => $connection->select(
                "select k.name as col from sqlite_master m, pragma_index_info(m.name) k where m.type = 'index' and m.tbl_name = ? and m.name = 'postings_unique_idx'",
                [$table]
            ),
            DbDialect::PGSQL  => $connection->select(
                'select a.attname as col from pg_index i join pg_class c on c.oid = i.indexrelid'
                . ' join pg_attribute a on a.attrelid = i.indrelid and a.attnum = any(i.indkey)'
                . " where i.indrelid = to_regclass(quote_ident(?)) and c.relname = 'postings_unique_idx'",
                [$table]
            ),
            DbDialect::SQLSRV => $connection->select(
                'select c.name as col from sys.indexes i join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id'
                . ' join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id'
                . " where i.object_id = object_id(?) and i.name = 'postings_unique_idx'",
                [$table]
            ),
            default => $connection->select(
                'select column_name as col from information_schema.statistics'
                . " where table_schema = database() and table_name = ? and index_name = 'postings_unique_idx'",
                [$table]
            ),
        };

        return array_map(fn ($row) => strtolower((string) ((object) $row)->col), $rows);
    }
};
