<?php

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQL Server: term compares byte-wise (Latin1_General_100_BIN2), as 2026_09_17_000002 made it on
 * MySQL/MariaDB with utf8mb4_bin. Under the database's collation (SQL_Latin1_General_CP1_CI_AS by
 * default) the unique key on term folded words the tokenizer keeps apart: straße and strasse, cœur
 * and coeur, m² and m2, full- and half-width forms, hiragana and katakana, and under an
 * accent-insensitive (_AI) collation café and cafe too. A rebuild holding both spellings died on
 * the unique key; a single write raised the first spelling's doc_count and posted nothing for the
 * second, which no search then found. All terms are lowercased by the tokenizer, so searches are
 * unaffected. The column keeps the type, length and nullability the create migration gave it.
 *
 * SQL Server refuses to change the collation of a column an index covers, so every index on term
 * (the unique key, under whatever name the table prefix gave it, and any other an app added) is
 * dropped and recreated as the catalog describes it; a stricter collation cannot violate the
 * unique key. A binary collation already there is left alone, so a second run does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== DbDialect::SQLSRV) {
            return;
        }

        $table     = $connection->getTablePrefix() . 'fuzzy_index_terms';
        // Each row cast to an object, whatever the app's fetch mode (an array one applies to `migrate` too).
        $column    = $connection->selectOne("select collation_name as c from sys.columns where object_id = object_id(?) and name = 'term'", [$table]);
        $collation = $column === null ? null : ((object) $column)->c;
        if ($collation === null || str_contains(strtoupper($collation), '_BIN')) {
            return; // no dictionary here, or it compares byte-wise already
        }

        $indexes = $this->indexesOnTerm($table);
        $wrapped = DbDialect::quoteIdentifier($table, DbDialect::SQLSRV);

        foreach ($indexes as $index) {
            $connection->statement($index['constraint']
                ? "ALTER TABLE {$wrapped} DROP CONSTRAINT {$index['name']}"
                : "DROP INDEX {$index['name']} ON {$wrapped}");
        }

        $connection->statement("ALTER TABLE {$wrapped} ALTER COLUMN term NVARCHAR(255) COLLATE Latin1_General_100_BIN2 NOT NULL");

        foreach ($indexes as $index) {
            $connection->statement($index['create']);
        }
    }

    public function down(): void
    {
        // Deliberate no-op, as 2026_09_17_000002's: once the dictionary holds words the database's
        // collation compares equal, restoring it would violate the unique key on term. Rolling
        // back means rebuilding the index (php artisan fuzzy-search:rebuild --fresh).
    }

    /**
     * Each index that covers term, as the statements that drop and recreate it: a unique or primary
     * key constraint, or an index with its key order, INCLUDE columns and filter.
     *
     * @return list<array{name: string, constraint: bool, create: string}>
     */
    private function indexesOnTerm(string $table): array
    {
        $rows = DB::select(
            'select i.index_id, i.name, i.type_desc, i.is_unique, i.is_primary_key, i.is_unique_constraint, i.filter_definition,'
            . ' c.name as col, ic.is_descending_key, ic.is_included_column'
            . ' from sys.indexes i'
            . ' join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id'
            . ' join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id'
            . ' where i.object_id = object_id(?) and i.index_id in (select ic2.index_id from sys.index_columns ic2'
            . " join sys.columns c2 on c2.object_id = ic2.object_id and c2.column_id = ic2.column_id where ic2.object_id = i.object_id and c2.name = 'term')"
            . ' order by i.index_id, ic.key_ordinal, ic.index_column_id',
            [$table]
        );

        $quote   = fn (string $name) => DbDialect::quoteIdentifier($name, DbDialect::SQLSRV);
        $wrapped = $quote($table);
        $byIndex = [];
        foreach ($rows as $row) {
            $row = (object) $row; // whatever the fetch mode
            $byIndex[$row->index_id]['row'] = $row;
            $byIndex[$row->index_id][$row->is_included_column ? 'include' : 'keys'][] = $quote($row->col) . ($row->is_descending_key ? ' DESC' : '');
        }

        $indexes = [];
        foreach ($byIndex as $index) {
            $row        = $index['row'];
            $name       = $quote($row->name);
            $keys       = implode(', ', $index['keys']);
            $constraint = (bool) ($row->is_primary_key || $row->is_unique_constraint);

            $indexes[] = ['name' => $name, 'constraint' => $constraint, 'create' => $constraint
                ? "ALTER TABLE {$wrapped} ADD CONSTRAINT {$name} " . ($row->is_primary_key ? 'PRIMARY KEY' : 'UNIQUE') . " {$row->type_desc} ({$keys})"
                : 'CREATE ' . ($row->is_unique ? 'UNIQUE ' : '') . "{$row->type_desc} INDEX {$name} ON {$wrapped} ({$keys})"
                    . (isset($index['include']) ? ' INCLUDE (' . implode(', ', $index['include']) . ')' : '')
                    . ($row->filter_definition !== null ? " WHERE {$row->filter_definition}" : '')];
        }

        return $indexes;
    }
};
