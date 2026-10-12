<?php

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The compact postings layout (S2, S8; ruling ER-168). fuzzy_index_postings is rewritten as a table
 * clustered by term: its primary key is (term_id, model_type_id, model_id, column_id), so a word's
 * postings are one range of it, and each posting carries its document's length (doc_length), so the
 * ranking reads no other table. A common word was read through postings_unique_idx, one random
 * lookup of the row per posting, then joined to fuzzy_index_documents: 22 s for a word in 7% of 6M
 * rows on MySQL, 0.5 s on this layout, and the table is about half the size.
 *
 * The model type and the column name become small ids, from two dictionaries: fuzzy_index_model_types
 * and fuzzy_index_columns (one for every model: column names repeat across models; the legacy '' of
 * postings from before column weighting is a column of its own). Their rows are never deleted, so an
 * id never changes. The primary key is the clustered index on MySQL, MariaDB and SQL Server; SQLite
 * keeps the table WITHOUT ROWID; PostgreSQL, which has no clustered table, INCLUDEs frequency and
 * doc_length in the key's index (PostgreSQL 11+), so the ranking and its document frequencies are
 * read from the index alone. One secondary index, postings_model_idx (model_type_id, model_id), serves
 * the writes, a flush and a clear. model_id stays a string, compared as before: byte-wise, trailing
 * spaces included, on MySQL/MariaDB (2026_09_20_000001's NO PAD binary collation), and in the
 * database's own collation elsewhere, so an ORDER BY model_id, the rank cut's tiebreak, reads as before.
 *
 * The postings are copied into fuzzy_index_postings_compact one range of term ids at a time, each
 * range one INSERT … SELECT committed on its own, so no transaction covers the whole table: about a
 * minute per million indexed rows on MySQL. A range covers about CHUNK documents by the words'
 * doc_count. A posting whose document row is missing (an index left inconsistent) has no length, and
 * is dropped: the count, taken a range at a time, is logged. Then the old table is dropped, the new one takes its name, and the
 * secondary index and the foreign keys are added with their final names: term_id cascades from
 * fuzzy_index_terms as before, and model_type_id references fuzzy_index_model_types. column_id has no
 * foreign key: MySQL would add an index on it, a quarter of the table's size, for a parent no write
 * deletes.
 *
 * Outside a transaction, so every step commits on its own on PostgreSQL and SQL Server too. Each step
 * looks for what an earlier run did, so a run that stopped part way (a killed deploy, a lock timeout)
 * goes on from its last committed range, and a second run does nothing. `migrate --pretend` runs no
 * select: it prints what the upgrade from the earlier layout runs. down() copies the postings back
 * into the earlier layout the same way.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const COMPACT = 'fuzzy_index_postings_compact';
    private const PRIMARY = 'postings_primary';
    private const BY_MODEL = 'postings_model_idx';
    private const UNIQUE  = 'postings_unique_idx';

    /** The documents one copy statement covers, by the words' doc_count (a word in several of a document's columns has a posting for each). */
    private const CHUNK = 100000;

    public function up(): void
    {
        $pretending = DB::connection()->pretending();
        $old        = $pretending || Schema::hasTable('fuzzy_index_postings');

        if ($old && ($pretending || !Schema::hasColumn('fuzzy_index_postings', 'model_type_id'))) {
            $collations = $this->collations($pretending);
            if ($pretending || !Schema::hasTable('fuzzy_index_model_types')) {
                Schema::create('fuzzy_index_model_types', function (Blueprint $table) use ($collations) {
                    $table->smallIncrements('id');
                    $this->collate($table->string('model_type', 191), $collations['model_type'] ?? null)->unique();
                });
            }
            if ($pretending || !Schema::hasTable('fuzzy_index_columns')) {
                Schema::create('fuzzy_index_columns', function (Blueprint $table) use ($collations) {
                    $table->smallIncrements('id');
                    $this->collate($table->string('name', 64), $collations['column_name'] ?? null)->unique();
                });
            }

            // Every model type and column the postings name, but those an earlier run recorded.
            foreach (['fuzzy_index_model_types' => ['model_type', 'model_type'], 'fuzzy_index_columns' => ['name', 'column_name']] as $table => [$column, $source]) {
                DB::table($table)->insertUsing([$column], DB::table('fuzzy_index_postings as p')->distinct()->select("p.{$source}")
                    ->whereNotExists(fn ($query) => $query->selectRaw('1')->from("{$table} as d")->whereColumn("d.{$column}", "p.{$source}")));
            }

            if ($pretending || !Schema::hasTable(self::COMPACT)) {
                $this->createCompact();
            }

            $dropped = $this->copy($pretending, self::COMPACT, fn (int $from, ?int $to) => DB::table('fuzzy_index_postings as p')
                ->join('fuzzy_index_documents as d', fn ($join) => $join->on('d.model_type', '=', 'p.model_type')->on('d.model_id', '=', 'p.model_id'))
                ->join('fuzzy_index_model_types as mt', 'mt.model_type', '=', 'p.model_type')
                ->join('fuzzy_index_columns as c', 'c.name', '=', 'p.column_name')
                ->where('p.term_id', '>', $from)
                ->when($to !== null, fn ($query) => $query->where('p.term_id', '<=', $to))
                ->select('p.term_id', 'mt.id as model_type_id', 'p.model_id', 'c.id as column_id', 'p.frequency', 'd.doc_length'),
                ['term_id', 'model_type_id', 'model_id', 'column_id', 'frequency', 'doc_length'],
                fn (int $from, ?int $to) => DB::table('fuzzy_index_postings')->where('term_id', '>', $from)->when($to !== null, fn ($query) => $query->where('term_id', '<=', $to))->count()
            );

            if ($dropped > 0) {
                Log::warning("fuzzy-search: the compact postings migration dropped {$dropped} posting(s) whose document row was missing (an inconsistent index). Rebuild a model whose search should find them: php artisan fuzzy-search:rebuild \"App\\Models\\YourModel\" --fresh");
            }

            Schema::drop('fuzzy_index_postings');
            $old = false;
        }

        if (!$old) {
            Schema::rename(self::COMPACT, 'fuzzy_index_postings');
        }

        // The secondary index, then the foreign keys (MySQL's on model_type_id needs that index).
        if ($pretending || !in_array(self::BY_MODEL, $this->indexes('fuzzy_index_postings'), true)) {
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->index(['model_type_id', 'model_id'], self::BY_MODEL));
        }
        $this->addForeignKeys($pretending, [
            'term_id'       => ['fuzzy_index_terms', true],
            'model_type_id' => ['fuzzy_index_model_types', false],
        ]);

        // PostgreSQL: statistics (a never-analyzed table is planned as if it held a few rows, ruling
        // ER-106), and the visibility map that lets the ranking's index-only read skip the heap.
        // Unprepared: VACUUM runs only as a statement of its own.
        if (DB::connection()->getDriverName() === DbDialect::PGSQL) {
            DB::unprepared('VACUUM (ANALYZE) ' . DB::getQueryGrammar()->wrapTable('fuzzy_index_postings'));
        }

        IndexManager::resetPipelineCache(); // the dictionaries' ids
    }

    public function down(): void
    {
        $pretending = DB::connection()->pretending();

        if ($pretending || Schema::hasColumn('fuzzy_index_postings', 'model_type_id')) {
            Schema::rename('fuzzy_index_postings', self::COMPACT);
        }

        if ($pretending || Schema::hasTable(self::COMPACT)) {
            if ($pretending || !Schema::hasTable('fuzzy_index_postings')) {
                $this->createEarlier();
            }

            $this->copy($pretending, 'fuzzy_index_postings', fn (int $from, ?int $to) => DB::table(self::COMPACT . ' as p')
                ->join('fuzzy_index_model_types as mt', 'mt.id', '=', 'p.model_type_id')
                ->join('fuzzy_index_columns as c', 'c.id', '=', 'p.column_id')
                ->where('p.term_id', '>', $from)
                ->when($to !== null, fn ($query) => $query->where('p.term_id', '<=', $to))
                ->select('p.term_id', 'mt.model_type', 'p.model_id', 'c.name as column_name', 'p.frequency'),
                ['term_id', 'model_type', 'model_id', 'column_name', 'frequency']
            );

            Schema::drop(self::COMPACT);
        }

        // A table a test removed has nothing to restore. The earlier layout's indexes, as
        // 2026_10_01_000001 left them, then its foreign key.
        if ($pretending || Schema::hasTable('fuzzy_index_postings')) {
            $indexes = $pretending ? [] : $this->indexes('fuzzy_index_postings');
            if (!in_array(self::UNIQUE, $indexes, true)) {
                Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->unique(['term_id', 'model_type', 'model_id', 'column_name'], self::UNIQUE));
            }
            if (!in_array(self::BY_MODEL, $indexes, true)) {
                Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->index(['model_type', 'model_id'], self::BY_MODEL));
            }
            $this->addForeignKeys($pretending, ['term_id' => ['fuzzy_index_terms', true]]);
        }

        Schema::dropIfExists('fuzzy_index_model_types');
        Schema::dropIfExists('fuzzy_index_columns');

        IndexManager::resetPipelineCache();
    }

    /**
     * Copy $select's rows into $to, one range of term ids at a time, each an INSERT … SELECT that
     * commits on its own. A run that stopped part way left whole ranges only, so the copy goes on
     * after the highest term id $to holds: the terms between it and the end of its range had no
     * posting. With $source, the count of a range's source rows, it returns how many rows the copy
     * left out, counted a range at a time while the range is in memory (two counts of the whole
     * tables took minutes at 383M postings on PostgreSQL); a range an earlier run copied is not
     * counted again.
     *
     * @param \Closure(int, ?int): \Illuminate\Database\Query\Builder $select the rows of term ids in ($from, $to]
     * @param list<string>                                             $columns
     * @param (\Closure(int, ?int): int)|null                          $source the count of the source's rows in ($from, $to]
     */
    private function copy(bool $pretending, string $to, \Closure $select, array $columns, ?\Closure $source = null): int
    {
        $start  = $pretending ? 0 : (int) DB::table($to)->max('term_id');
        $missed = 0;

        foreach ($this->ranges($pretending, $start) as [$low, $high]) {
            $copied = DB::table($to)->insertUsing($columns, $select($low, $high));
            if ($source !== null && !$pretending) {
                $missed += $source($low, $high) - $copied;
            }
        }

        return $missed;
    }

    /**
     * The ranges of term ids past $start, ($low, $high], each of about CHUNK documents by the words'
     * doc_count, read from the dictionary in id order; the last one is open. Under --pretend, one
     * open range: what an empty index runs.
     *
     * @return \Generator<array{int, ?int}>
     */
    private function ranges(bool $pretending, int $start): \Generator
    {
        $low  = $start;
        $last = $start;
        $sum  = 0;

        while (!$pretending) {
            $terms = DB::table('fuzzy_index_terms')->where('id', '>', $last)->orderBy('id')->limit(10000)->get(['id', 'doc_count']);
            if ($terms->isEmpty()) {
                break;
            }

            foreach ($terms as $term) {
                $term = (object) $term; // whatever the app's fetch mode
                $last = (int) $term->id;
                $sum += max(1, (int) $term->doc_count);
                if ($sum >= self::CHUNK) {
                    yield [$low, $last];
                    $low = $last;
                    $sum = 0;
                }
            }
        }

        yield [$low, null];
    }

    /** fuzzy_index_postings_compact, with its primary key and nothing else (but SQLite's foreign keys, which only CREATE TABLE can add). */
    private function createCompact(): void
    {
        $driver  = DB::connection()->getDriverName();
        $grammar = DB::getQueryGrammar();
        $table   = $grammar->wrapTable(self::COMPACT);

        if ($driver === DbDialect::SQLITE) {
            DB::statement("create table {$table} (term_id integer not null, model_type_id integer not null, model_id varchar not null,"
                . ' column_id integer not null, frequency integer not null, doc_length integer not null,'
                . ' primary key (term_id, model_type_id, model_id, column_id),'
                . ' foreign key (term_id) references ' . $grammar->wrapTable('fuzzy_index_terms') . ' (id) on delete cascade,'
                . ' foreign key (model_type_id) references ' . $grammar->wrapTable('fuzzy_index_model_types') . ' (id)) without rowid');

            return;
        }

        if ($driver === DbDialect::PGSQL) {
            DB::statement("create table {$table} (term_id bigint not null, model_type_id smallint not null, model_id varchar(191) not null,"
                . ' column_id smallint not null, frequency integer not null, doc_length integer not null,'
                . ' constraint ' . self::PRIMARY . ' primary key (term_id, model_type_id, model_id, column_id) include (frequency, doc_length))');

            return;
        }

        Schema::create(self::COMPACT, function (Blueprint $table) use ($driver) {
            $table->unsignedBigInteger('term_id');
            $table->unsignedSmallInteger('model_type_id');
            $id = $table->string('model_id', 191);
            if (DbDialect::isMySqlFamily($driver)) {
                $id->charset('utf8mb4')->collation(self::noPadBinary());
            }
            $table->unsignedSmallInteger('column_id');
            $table->unsignedInteger('frequency');
            $table->unsignedInteger('doc_length');
            $table->primary(['term_id', 'model_type_id', 'model_id', 'column_id'], self::PRIMARY);
        });
    }

    /** fuzzy_index_postings as the earlier migrations left it, without its indexes (down() adds them after the copy). */
    private function createEarlier(): void
    {
        $driver = DB::connection()->getDriverName();

        Schema::create('fuzzy_index_postings', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->unsignedBigInteger('term_id');
            $table->string('model_type', 191);
            $id = $table->string('model_id', 191);
            if (DbDialect::isMySqlFamily($driver)) {
                $id->charset('utf8mb4')->collation(self::noPadBinary());
            }
            $table->unsignedInteger('frequency')->default(1);
            $table->string('column_name', 64)->default('');
            if ($driver === DbDialect::SQLITE) {
                $table->foreign('term_id')->references('id')->on('fuzzy_index_terms')->onDelete('cascade');
            }
        });
    }

    /**
     * Add each foreign key the postings lack, column => [the table it references, whether a delete
     * there cascades]. SQLite's are made with the table. MySQL/MariaDB add them without checking
     * the rows, which came from the table that had them (with checks on, InnoDB copies the table).
     *
     * @param array<string, array{string, bool}> $keys
     */
    private function addForeignKeys(bool $pretending, array $keys): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === DbDialect::SQLITE) {
            return;
        }

        $keys = array_diff_key($keys, array_flip($pretending ? [] : $this->foreignKeys('fuzzy_index_postings')));
        if ($keys === []) {
            return;
        }

        $add = fn () => Schema::table('fuzzy_index_postings', function (Blueprint $table) use ($keys) {
            foreach ($keys as $column => [$on, $cascade]) {
                $key = $table->foreign($column)->references('id')->on($on);
                if ($cascade) {
                    $key->onDelete('cascade');
                }
            }
        });

        DbDialect::isMySqlFamily($driver) ? Schema::withoutForeignKeyConstraints($add) : $add();
    }

    /**
     * MySQL/MariaDB: the character set and collation of the earlier table's model_type and
     * column_name, which the dictionaries take, so the copy compares them without a collation
     * conflict whatever the connection's collation is now. Under --pretend, the connection's. Empty
     * elsewhere: the database's default, as the earlier table has.
     *
     * @return array<string, array{string, string}>
     */
    private function collations(bool $pretending): array
    {
        $connection = DB::connection();
        if (!DbDialect::isMySqlFamily($connection->getDriverName())) {
            return [];
        }

        $rows = $pretending
            ? [['name' => 'model_type', 'charset' => $connection->getConfig('charset'), 'collation' => $connection->getConfig('collation')],
               ['name' => 'column_name', 'charset' => $connection->getConfig('charset'), 'collation' => $connection->getConfig('collation')]]
            : $connection->select(
                'select column_name as name, character_set_name as charset, collation_name as collation from information_schema.columns'
                . " where table_schema = database() and table_name = ? and column_name in ('model_type', 'column_name')",
                [$connection->getTablePrefix() . 'fuzzy_index_postings']
            );

        $out = [];
        foreach ($rows as $row) {
            $row = (object) $row; // whatever the app's fetch mode
            if (preg_match('/^\w+$/', (string) $row->charset) && preg_match('/^\w+$/', (string) $row->collation)) {
                $out[strtolower((string) $row->name)] = [(string) $row->charset, (string) $row->collation];
            }
        }

        return $out;
    }

    /** @param array{string, string}|null $collation */
    private function collate(\Illuminate\Database\Schema\ColumnDefinition $column, ?array $collation): \Illuminate\Database\Schema\ColumnDefinition
    {
        return $collation === null ? $column : $column->charset($collation[0])->collation($collation[1]);
    }

    /** MySQL/MariaDB: model_id's NO PAD binary collation, as 2026_09_20_000001 gives it (utf8mb4_bin before MySQL 8.0.17). */
    private static function noPadBinary(): string
    {
        $version = (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);

        return match (true) {
            stripos($version, 'mariadb') !== false    => 'utf8mb4_nopad_bin',
            version_compare($version, '8.0.17', '>=') => 'utf8mb4_0900_bin',
            default                                   => 'utf8mb4_bin',
        };
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
            DbDialect::PGSQL  => $connection->select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$table]),
            DbDialect::SQLSRV => $connection->select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$table]),
            default           => $connection->select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$table]),
        };

        return array_map(fn ($row) => strtolower((string) ((object) $row)->name), $rows); // whatever the fetch mode
    }

    /**
     * The columns of $table that have a foreign key, read from the catalog (Laravel 10 has no
     * Schema::getForeignKeys()).
     *
     * @return list<string>
     */
    private function foreignKeys(string $table): array
    {
        $connection = DB::connection();
        $table      = $connection->getTablePrefix() . $table;

        $rows = match ($connection->getDriverName()) {
            DbDialect::PGSQL  => $connection->select(
                'select a.attname as name from pg_constraint k join pg_attribute a on a.attrelid = k.conrelid and a.attnum = any(k.conkey)'
                . " where k.conrelid = to_regclass(quote_ident(?)) and k.contype = 'f'",
                [$table]
            ),
            DbDialect::SQLSRV => $connection->select(
                'select c.name from sys.foreign_key_columns k join sys.columns c on c.object_id = k.parent_object_id and c.column_id = k.parent_column_id where k.parent_object_id = object_id(?)',
                [$table]
            ),
            default           => $connection->select(
                'select column_name as name from information_schema.key_column_usage where table_schema = database() and table_name = ? and referenced_table_name is not null',
                [$table]
            ),
        };

        return array_map(fn ($row) => strtolower((string) ((object) $row)->name), $rows); // whatever the fetch mode
    }
};
