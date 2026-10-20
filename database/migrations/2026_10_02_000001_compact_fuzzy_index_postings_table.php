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
 * rows on MySQL, 0.5 s on this layout, and the table takes a third of the space on MySQL, two
 * thirds on PostgreSQL.
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
 * The postings are copied into fuzzy_index_postings_compact one range of the earlier table's ids at
 * a time (copyUp()), each range one INSERT … SELECT committed with a progress mark, so no
 * transaction covers the whole table: about 11 minutes for 96M postings (6M rows) on MySQL, plus the
 * secondary index. A posting whose document row is missing (an index left inconsistent) has no
 * length, and is dropped: the count, taken a range at a time, is logged. Then the old table is dropped, the new one takes its name, and the
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
    private const PROGRESS = 'fuzzy_index_postings_copied';
    private const PRIMARY = 'postings_primary';
    private const BY_MODEL = 'postings_model_idx';
    private const BY_DOCUMENT = 'postings_compact_model_idx'; // BY_MODEL's columns on the compact table, for the tail (VA-6)
    private const UNIQUE  = 'postings_unique_idx';

    private const LENGTH_COUNT_ID = 'fuzzy_index_terms_term_length_doc_count_id_index';
    private const LENGTH_COUNT    = 'fuzzy_index_terms_term_length_doc_count_index'; // 2026_10_01_000001's before round 12

    /**
     * The span of one copy statement: up() copies the earlier table's ids ($chunk of them), down()
     * the compact table's words, by doc_count ($chunk documents).
     */
    public int $chunk = 1000000;

    /** Called with the last id of each range up() commits (tests write the earlier layout in between, as old code would). */
    public ?\Closure $afterRange = null;

    /** How many times up() follows the postings old code wrote past the ids it read (ER-183). */
    private const TAIL_ROUNDS = 5;

    public function up(): void
    {
        $pretending = DB::connection()->pretending();
        $old        = $pretending || Schema::hasTable('fuzzy_index_postings');
        $orphans    = !$pretending; // VA-2: the earlier table is gone, so an earlier run copied it (and may have warned)

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
                    // Not a small id: column names are shared by every model and never deleted, and a
                    // searchableText() keyed per attribute, locale or custom field can pass 32,767 (VC-6).
                    $table->increments('id');
                    $this->collate($table->string('name', 64), $collations['column_name'] ?? null)->unique();
                });
            }

            // Every model type with a document row, read through the documents' key (a posting
            // without one is not copied), but those an earlier run recorded. The column names are
            // recorded a range at a time below, from the range's own postings.
            DB::table('fuzzy_index_model_types')->insertUsing(['model_type'], DB::table('fuzzy_index_documents as d')->distinct()->select('d.model_type')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('fuzzy_index_model_types as t')->whereColumn('t.model_type', 'd.model_type')));

            if ($pretending || !Schema::hasTable(self::COMPACT)) {
                $this->createCompact();
            }

            if ($pretending || !Schema::hasTable(self::PROGRESS)) {
                // A primary key, as every table needs under MySQL's sql_require_primary_key (VA-3); its one row is updated in place.
                Schema::create(self::PROGRESS, fn (Blueprint $table) => $table->unsignedBigInteger('copied_id')->primary());
                DB::table(self::PROGRESS)->insert(['copied_id' => 0]);
            }

            // VA-1, VA-4: an earlier run committed part of the copy (a range, or rows the tail
            // copied again). Its meta snapshot, the ids it read and its count of dropped postings
            // went with it, so writes made during it or since cannot be told apart here.
            $resumed = !$pretending && ((int) DB::table(self::PROGRESS)->value('copied_id') > 0 || DB::table(self::COMPACT)->exists());

            $meta = $pretending ? [] : $this->meta();
            [$dropped, $rewritten, $unfinished] = $pretending ? [0, [], false] : $this->copyUp();

            // A resumed run's count misses what the earlier run dropped and holds the keys it
            // copied already: its warning below says so instead.
            if ($dropped > 0 && !$resumed) {
                Log::warning("fuzzy-search: the compact postings migration dropped {$dropped} posting(s) whose document row was missing (an inconsistent index), or written again while it ran. Rebuild a model whose search should find them: php artisan fuzzy-search:rebuild \"App\\Models\\YourModel\" --fresh");
            }

            // ER-183: the index written while the copy ran (old code: a queue worker, a request).
            // A write is copied whole when its postings landed past the ids the copy read
            // (copyUp()), but a removal leaves no id, and doc_count follows whichever code wrote
            // it: the meta totals tell. Rebuilding the models named makes them exact. A resumed
            // run names every indexed model: those in the meta and every type the dictionary
            // holds (each with a document row, and any an earlier run recorded).
            if (!$pretending) {
                $after   = $this->meta();
                $changed = array_keys(array_diff_assoc($meta, $after) + array_diff_assoc($after, $meta) + $rewritten
                    + ($resumed ? $after + $this->dictionary('fuzzy_index_model_types', 'model_type') : []));
                if ($changed !== [] || $unfinished) {
                    $this->warn('fuzzy-search: ' . ($resumed
                        ? 'the compact postings migration was resumed after an interrupted run, which may have missed writes made meanwhile and dropped postings'
                        : 'the index was written while the compact postings migration ran' . ($unfinished ? ', and was still being written when it stopped following the writes' : ''))
                        . '. Rebuild each model it names: ' . implode('; ', array_map(fn (string $type) => "php artisan fuzzy-search:rebuild \"{$type}\" --fresh", $changed)));
                }
                $orphans = $changed !== [] || $resumed;
            }

            Schema::drop('fuzzy_index_postings');
            $old = false;
        }

        if (!$old) {
            Schema::rename(self::COMPACT, 'fuzzy_index_postings');
        }
        Schema::dropIfExists(self::PROGRESS);

        // The secondary index, then the foreign keys (MySQL's on model_type_id needs that index).
        // The tail built it already, under a name of its own while the earlier table's held this
        // one (an index name is unique per schema on PostgreSQL, per database on SQLite): renamed
        // now, but on SQLite, which renames no index, dropped and built again.
        // Each name led by the table prefix under prefix_indexes; an install made before index names
        // carried it has them as written (ER-216).
        $indexes    = $pretending ? [] : $this->indexes('fuzzy_index_postings');
        $byDocument = DbDialect::presentIndexName(self::BY_DOCUMENT, $indexes);
        if ($byDocument !== null) {
            $rename = DbDialect::presentIndexName(self::BY_MODEL, $indexes) === null && DB::connection()->getDriverName() !== DbDialect::SQLITE;
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $rename
                ? $table->renameIndex($byDocument, DbDialect::indexName(self::BY_MODEL))
                : $table->dropIndex($byDocument));
            $indexes[] = $rename ? strtolower(DbDialect::indexName(self::BY_MODEL)) : '';
        }
        if ($pretending || DbDialect::presentIndexName(self::BY_MODEL, $indexes) === null) {
            Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->index(['model_type_id', 'model_id'], DbDialect::indexName(self::BY_MODEL)));
        }
        if ($orphans) {
            $this->deleteOrphans();
        }
        $this->addForeignKeys($pretending, [
            'term_id'       => ['fuzzy_index_terms', true],
            'model_type_id' => ['fuzzy_index_model_types', false],
        ]);

        if (!$pretending) {
            $this->converge();
        }

        // PostgreSQL: statistics (a never-analyzed table is planned as if it held a few rows, ruling
        // ER-106), and the visibility map that lets the ranking's index-only read skip the heap.
        // Unprepared: VACUUM runs only as a statement of its own.
        // Best-effort, as the package's own ANALYZE: autovacuum gets there too.
        if (DB::connection()->getDriverName() === DbDialect::PGSQL) {
            try {
                DB::unprepared('VACUUM (ANALYZE) ' . DB::getQueryGrammar()->wrapTable('fuzzy_index_postings'));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        IndexManager::resetPipelineCache(); // the dictionaries' ids
    }

    public function down(): void
    {
        $pretending = DB::connection()->pretending();

        // Rolled back on its own (migrate:rollback --path) under the per-model term statistics,
        // whose foreign key references the model types this drops: refuse first, naming it.
        if (!$pretending && Schema::hasTable('fuzzy_index_model_terms')) {
            throw new \RuntimeException('Roll back 2026_10_13_000001_create_fuzzy_index_model_terms_table first: its table references the model types this migration drops.');
        }

        if ($pretending || Schema::hasColumn('fuzzy_index_postings', 'model_type_id')) {
            Schema::rename('fuzzy_index_postings', self::COMPACT);
        }

        if ($pretending || Schema::hasTable(self::COMPACT)) {
            if ($pretending || !Schema::hasTable('fuzzy_index_postings')) {
                $this->createEarlier();
            }

            $types   = $pretending ? [] : array_flip($this->dictionary('fuzzy_index_model_types', 'model_type'));
            $columns = $pretending ? [] : array_flip($this->dictionary('fuzzy_index_columns', 'name'));

            $this->copy($pretending, 'fuzzy_index_postings', function (int $from, ?int $to) use ($types, $columns) {
                $ids = DB::table(self::COMPACT)->where('term_id', '>', $from)->when($to !== null, fn ($query) => $query->where('term_id', '<=', $to))->distinct()->pluck('column_id')->all();

                return $ids === [] ? null : $this->copyStatement(
                    self::COMPACT, 'fuzzy_index_postings',
                    ['term_id', 'model_type', 'model_id', 'column_name', 'frequency'],
                    ['p.term_id', $this->mapped('p.model_type_id', $types), 'p.model_id', $this->mapped('p.column_id', array_intersect_key($columns, array_flip($ids))), 'p.frequency'],
                    'p.term_id > ?' . ($to === null ? '' : ' and p.term_id <= ?'), [$from, ...($to === null ? [] : [$to])]
                );
            });

            Schema::drop(self::COMPACT);
        }

        // A table a test removed has nothing to restore. The earlier layout's indexes, as
        // 2026_10_01_000001 left them, then its foreign key.
        if ($pretending || Schema::hasTable('fuzzy_index_postings')) {
            $indexes = $pretending ? [] : $this->indexes('fuzzy_index_postings');
            if (DbDialect::presentIndexName(self::UNIQUE, $indexes) === null) {
                Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->unique(['term_id', 'model_type', 'model_id', 'column_name'], DbDialect::indexName(self::UNIQUE)));
            }
            if (DbDialect::presentIndexName(self::BY_MODEL, $indexes) === null) {
                Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->index(['model_type', 'model_id'], DbDialect::indexName(self::BY_MODEL)));
            }
            $this->addForeignKeys($pretending, ['term_id' => ['fuzzy_index_terms', true]]);
        }

        Schema::dropIfExists('fuzzy_index_model_types');
        Schema::dropIfExists('fuzzy_index_columns');
        Schema::dropIfExists(self::PROGRESS); // an up() that stopped part way

        IndexManager::resetPipelineCache();
    }

    /**
     * R12-L6: bring an install of an earlier 2.1 build to the 2.1.0 schema. Its recorded migrations
     * never run again, and two of them changed since: 2026_09_20_000001 now gives model_id a NO PAD
     * binary collation on MySQL/MariaDB, where an earlier build gave utf8mb4_bin (which pads: 'SKU1'
     * and 'SKU1 ' were one key), and 2026_10_01_000001 now indexes the dictionary on (term_length,
     * doc_count, id), where an earlier build left out the id. Every install runs this migration, so
     * it converges here: the documents' model_id (the postings were created with the collation, and
     * their copy compared the old columns, so this comes after it; a stricter collation joins no two
     * keys), then the dictionary's index. Under --pretend nothing: the upgrade from 2.0.1 runs the
     * migrations as they are now.
     */
    private function converge(): void
    {
        $connection = DB::connection();

        if (DbDialect::isMySqlFamily($connection->getDriverName())) {
            $collation = self::noPadBinary();
            $column    = $connection->selectOne(
                "select collation_name as c from information_schema.columns where table_schema = database() and table_name = ? and column_name = 'model_id'",
                [$connection->getTablePrefix() . 'fuzzy_index_documents']
            );
            if ($column !== null && strtolower((string) ((object) $column)->c) !== $collation) {
                DB::statement('ALTER TABLE ' . DbDialect::rawIdentifier('fuzzy_index_documents') . " MODIFY model_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE {$collation} NOT NULL");
            }
        }

        $terms = $this->indexes('fuzzy_index_terms');
        if (($lengthCount = DbDialect::presentIndexName(self::LENGTH_COUNT, $terms)) !== null) {
            if (DbDialect::presentIndexName(self::LENGTH_COUNT_ID, $terms) === null) {
                Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->index(['term_length', 'doc_count', 'id'], DbDialect::indexName(self::LENGTH_COUNT_ID)));
            }
            Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex($lengthCount));
        }
    }

    /**
     * down(): copy into $to one range of term ids at a time (the compact table is clustered by
     * term, so a range is one sequential read), each an INSERT … SELECT ($statement) that commits
     * on its own. A run that stopped part way left whole ranges only, so the copy goes on after the
     * highest term id $to holds: the terms between it and the end of its range had no posting.
     * With $source, the count of a range's source rows, it returns how many rows the copy left out.
     * Under --pretend, which runs no select, nothing: each statement maps the names its range
     * holds, which only the data can tell.
     *
     * @param \Closure(int, ?int): (array{string, list<mixed>}|null) $statement the copy of the term ids in ($from, $to] and its bindings; null for a range with no posting
     * @param (\Closure(int, ?int): int)|null                        $source    the count of the source's rows in ($from, $to]
     */
    private function copy(bool $pretending, string $to, \Closure $statement, ?\Closure $source = null): int
    {
        $start  = $pretending ? 0 : (int) DB::table($to)->max('term_id');
        $missed = 0;

        foreach ($pretending ? [] : $this->ranges($pretending, $start) as [$low, $high]) {
            $insert = $statement($low, $high);
            if ($insert === null) {
                continue;
            }

            $copied = DB::affectingStatement(...$insert);
            if ($source !== null) {
                $missed += $source($low, $high) - $copied;
            }
        }

        return $missed;
    }

    /**
     * up(): copy the earlier postings into the compact table, $chunk ids at a time (ER-179a): a
     * range of its primary key, read in order. A range of words was read through
     * postings_unique_idx, which does not hold frequency, so every posting was a lookup of its row,
     * and one word's postings span the whole table: 7 to 9 hours at 96M postings on MySQL, where id
     * ranges copy them in about 11 minutes. Each range is one INSERT … SELECT, joined to the
     * documents for the length, with the model type and the column mapped through CASE (mapped()),
     * no join to the dictionaries. Its names are recorded first, from one grouped read of the range
     * that also counts its postings.
     *
     * Each range commits with the mark of the ids it copied (PROGRESS), in one transaction, so a run
     * that stopped part way goes on after the mark. A write the old code made while the copy ran
     * can re-insert, past the mark, a posting the copy already has: the insert skips a key it holds
     * (insertIgnoringKey()), and only that.
     *
     * Old code that writes while the copy runs (ER-183) deletes a row's postings and inserts them
     * again past the ids the copy read. Once the ranges are done, the documents with postings past
     * them are copied again whole, from their current postings, read by document
     * (postings_model_idx), after their copied postings are deleted: both a row the copy had
     * copied and one it had not end exact. The copy follows such writes TAIL_ROUNDS times. Ids are
     * taken at insert and committed out of order (VA-5): a write in flight when a round reads its
     * boundary commits below it, and its row keeps what the ranges copied. So every model type
     * with a posting past the ids the ranges read is named, whether or not a round copied it.
     *
     * @return array{int, array<string, true>, bool} the postings the copy left out (without a
     *                                               document row, or written twice), the model
     *                                               types with postings written past the ids the
     *                                               ranges read, and whether writes went on past
     *                                               the last round
     */
    private function copyUp(): array
    {
        $postings = DB::table('fuzzy_index_postings');
        $done     = (int) DB::table(self::PROGRESS)->value('copied_id');
        $first    = (clone $postings)->where('id', '>', $done)->min('id'); // past a mark an earlier run committed
        $max      = (int) (clone $postings)->max('id');
        $types    = $this->dictionary('fuzzy_index_model_types', 'model_type');
        $columns  = $this->dictionary('fuzzy_index_columns', 'name');
        $missed   = 0;

        for ($low = $first === null ? $max : (int) $first - 1; $low < $max; $low += $this->chunk) {
            $high   = min($low + $this->chunk, $max);
            $counts = [];
            foreach ((clone $postings)->where('id', '>', $low)->where('id', '<=', $high)->groupBy('column_name')->selectRaw('column_name, count(*) as n')->get() as $row) {
                $row                                 = (object) $row; // whatever the app's fetch mode
                $counts[(string) $row->column_name] = (int) $row->n;
                $columns[(string) $row->column_name] ??= $this->record('fuzzy_index_columns', 'name', (string) $row->column_name);
            }

            // No type with a document row: the range's postings have none to join (an empty CASE is no SQL).
            [$sql, $bindings] = $counts === [] || $types === [] ? [null, []] : $this->copyStatement(
                'fuzzy_index_postings', self::COMPACT,
                ['term_id', 'model_type_id', 'model_id', 'column_id', 'frequency', 'doc_length'],
                ['p.term_id', $this->mapped('p.model_type', $types), 'p.model_id', $this->mapped('p.column_name', array_intersect_key($columns, $counts)), 'p.frequency', 'd.doc_length'],
                'p.id > ? and p.id <= ?', [$low, $high]
            );

            $copied = DB::transaction(function () use ($sql, $bindings, $high) {
                $copied = $sql === null ? 0 : DB::affectingStatement($sql, $bindings);
                DB::table(self::PROGRESS)->update(['copied_id' => $high]);

                return $copied;
            });
            $missed += array_sum($counts) - $copied;

            if ($this->afterRange !== null) {
                ($this->afterRange)($high);
            }
        }

        $read      = $max; // the ids the ranges read
        $rewritten = [];
        for ($round = 0; $round < self::TAIL_ROUNDS && ($last = (int) (clone $postings)->max('id')) > $max; $round++) {
            // VA-6: each re-copy deletes by document, which scanned the whole compact table. BY_MODEL's
            // index is built here instead, before the first round, and kept by the swap (renamed);
            // a copy no write followed builds it after the swap, as before.
            if ($round === 0 && DbDialect::presentIndexName(self::BY_DOCUMENT, $this->indexes(self::COMPACT)) === null) {
                Schema::table(self::COMPACT, fn (Blueprint $table) => $table->index(['model_type_id', 'model_id'], DbDialect::indexName(self::BY_DOCUMENT)));
            }
            $written = []; // model type => model ids
            foreach ((clone $postings)->where('id', '>', $max)->where('id', '<=', $last)->distinct()->get(['model_type', 'model_id']) as $row) {
                $row                                                  = (object) $row;
                $written[(string) $row->model_type][(string) $row->model_id] = (string) $row->model_id;
            }
            foreach ($written as $type => $ids) {
                $types[$type] ??= $this->record('fuzzy_index_model_types', 'model_type', $type); // a type first indexed meanwhile
                $rewritten[$type] = true;
                foreach (array_chunk(array_values($ids), 500) as $chunk) {
                    $this->copyDocuments($type, $chunk, $types[$type], $columns);
                }
            }
            $max = $last;
        }

        $unfinished = (int) (clone $postings)->max('id') > $max; // past the last round: named, not copied
        foreach ((clone $postings)->where('id', '>', $read)->distinct()->pluck('model_type') as $type) { // one range of the key
            $rewritten[(string) $type] = true;
        }

        return [$missed, $rewritten, $unfinished];
    }

    /**
     * Copy model type $type's documents $ids again, whole, from their current postings, in place of
     * what the compact table holds for them (ER-183), in one transaction.
     *
     * @param list<string>       $ids
     * @param array<string, int> $columns name => id, recorded as needed
     */
    private function copyDocuments(string $type, array $ids, int $typeId, array &$columns): void
    {
        $names = array_map('strval', DB::table('fuzzy_index_postings')->where('model_type', $type)->whereIn('model_id', $ids)->distinct()->pluck('column_name')->all());
        foreach ($names as $name) {
            $columns[$name] ??= $this->record('fuzzy_index_columns', 'name', $name);
        }

        DB::transaction(function () use ($type, $ids, $typeId, $columns, $names) {
            DB::table(self::COMPACT)->where('model_type_id', $typeId)->whereIn('model_id', $ids)->delete();
            if ($names === []) {
                return; // their postings are gone: removed from the index
            }

            DB::affectingStatement(...$this->copyStatement(
                'fuzzy_index_postings', self::COMPACT,
                ['term_id', 'model_type_id', 'model_id', 'column_id', 'frequency', 'doc_length'],
                ['p.term_id', $this->mapped('p.model_type', [$type => $typeId]), 'p.model_id', $this->mapped('p.column_name', array_intersect_key($columns, array_flip($names))), 'p.frequency', 'd.doc_length'],
                'p.model_type = ? and p.model_id in (' . implode(', ', array_fill(0, count($ids), '?')) . ')', [$type, ...$ids]
            ));
        });
    }

    /**
     * VA-2: delete the postings whose word the dictionary no longer has. The compact table has no
     * term foreign key while the copy runs, and an old-code flush meanwhile (2.0.1's `flush`,
     * `clear`, `rebuild --fresh`) deletes the words it leaves without a posting in the earlier
     * table, so the compact table kept the postings it had copied of them: PostgreSQL and SQL
     * Server then refused the foreign key, once the earlier table was dropped, on every run, and
     * MySQL and MariaDB kept them. Only the dictionary is read whole, its ids in order: each gap
     * in them is one range of the postings' leading term_id, so no posting outside a gap is read,
     * and a word inserted into a gap meanwhile keeps its postings (NOT EXISTS).
     */
    private function deleteOrphans(): void
    {
        $delete = fn (array $gaps) => DB::table('fuzzy_index_postings')
            ->where(function ($query) use ($gaps) {
                foreach ($gaps as [$low, $high]) {
                    $high === null ? $query->orWhere('term_id', '>=', $low) : $query->orWhereBetween('term_id', [$low, $high]);
                }
            })
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('fuzzy_index_terms as t')->whereColumn('t.id', 'fuzzy_index_postings.term_id'))
            ->delete();

        $last = 0;
        do {
            $ids  = DB::table('fuzzy_index_terms')->where('id', '>', $last)->orderBy('id')->limit(10000)->pluck('id')->all();
            $gaps = [];
            foreach ($ids as $id) {
                if ((int) $id > $last + 1) {
                    $gaps[] = [$last + 1, (int) $id - 1];
                }
                $last = (int) $id;
            }
            foreach (array_chunk($gaps, 250) as $chunk) { // 500 bindings: under every database's limit
                $delete($chunk);
            }
        } while ($ids !== []);

        $delete([[$last + 1, null]]); // past the last word
    }

    /** @return array<string, string> model type => its meta totals, to compare before and after the copy */
    private function meta(): array
    {
        $out = [];
        foreach (DB::table('fuzzy_index_meta')->get(['model_type', 'total_docs', 'total_tokens']) as $row) {
            $row                             = (object) $row;
            $out[(string) $row->model_type] = (int) $row->total_docs . '|' . (int) $row->total_tokens;
        }

        return $out;
    }

    /** Log $message, and print it under `php artisan migrate` (not in a test run). */
    private function warn(string $message): void
    {
        Log::warning($message);
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            fwrite(STDERR, "  WARN  {$message}" . PHP_EOL);
        }
    }

    /**
     * INSERT INTO $to ($columns) SELECT $select FROM $from p [JOIN the documents d] WHERE $where, and
     * its bindings: the source drives the read, one range of its primary key (up(): the earlier
     * table's id; down(): the compact table's leading term_id), or a few documents by key (ER-183). No join to the
     * dictionaries, whose one row of a type MySQL read first, then walked postings_model_idx for
     * every posting of the type (ER-179): the names map through CASE expressions (mapped()). The
     * documents give the length, on the copy into the compact table only, which skips a key it
     * holds already (insertIgnoringKey()).
     *
     * @param  list<string|array{string, list<mixed>}> $select a column, or [SQL, bindings]
     * @return array{string, list<mixed>}
     */
    private function copyStatement(string $from, string $to, array $columns, array $select, string $where, array $whereBindings): array
    {
        $grammar  = DB::getQueryGrammar();
        $bindings = [];
        $list     = [];
        foreach ($select as $i => $expression) {
            if (is_array($expression)) {
                [$expression, $bound] = $expression;
                array_push($bindings, ...$bound);
            }
            $list[] = "{$expression} as {$columns[$i]}"; // named: SQL Server's derived table needs every column's
        }

        $join  = $to === self::COMPACT ? ' inner join ' . $grammar->wrapTable('fuzzy_index_documents') . ' d on d.model_type = p.model_type and d.model_id = p.model_id' : '';
        $read  = 'select ' . implode(', ', $list) . ' from ' . $grammar->wrapTable($from) . " p{$join} where {$where}";
        $bound = [...$bindings, ...$whereBindings];

        return $to === self::COMPACT
            ? [$this->insertIgnoringKey($grammar->wrapTable($to), $columns, $read), $bound]
            : ['insert into ' . $grammar->wrapTable($to) . ' (' . implode(', ', $columns) . ') ' . $read, $bound];
    }

    /**
     * INSERT INTO the compact table ($columns) $read, skipping a row whose primary key the table
     * holds, and nothing else: a NOT NULL violation (a name no CASE maps) still fails it. MySQL and
     * MariaDB update the key to itself on a duplicate (INSERT IGNORE would store 0 for a NULL);
     * PostgreSQL and SQLite do nothing on the conflict (SQLite's INSERT OR IGNORE would drop the
     * NULL row too; its WHERE keeps the upsert's ON from reading as a join's); SQL Server, with no
     * such clause, reads past a key the table holds.
     *
     * @param list<string> $columns
     */
    private function insertIgnoringKey(string $table, array $columns, string $read): string
    {
        $list = implode(', ', $columns);

        return match (DB::connection()->getDriverName()) {
            DbDialect::SQLSRV => "insert into {$table} ({$list}) select s.* from ({$read}) s where not exists (select 1 from {$table} c"
                . ' where c.term_id = s.term_id and c.model_type_id = s.model_type_id and c.model_id = s.model_id and c.column_id = s.column_id)',
            DbDialect::PGSQL, DbDialect::SQLITE => "insert into {$table} ({$list}) {$read} on conflict do nothing",
            default => "insert into {$table} ({$list}) {$read} on duplicate key update {$table}.term_id = {$table}.term_id",
        };
    }

    /**
     * CASE $column WHEN … THEN … END over $map: a name to its id (an id column's ids to their names
     * when $column is one), the names bound (quoted on SQL Server, below), the ids inlined as
     * integers (they come from the database; bound, PostgreSQL types the CASE as text). A name is
     * compared in $column's own collation (on MySQL/MariaDB and SQL Server a case-insensitive one
     * matches every spelling the dictionary keeps for it). A value $map lacks gives NULL, which the
     * NOT NULL column refuses: a range's names are recorded before its copy.
     *
     * SQL Server takes 2,100 parameters a statement, and a range can hold more names (VA-7: a
     * searchableText() keyed per attribute, locale or custom field): there the names are nvarchar
     * literals, quoted by the driver, as a bound name is sent.
     *
     * @param  array<int|string, int|string> $map
     * @return array{string, list<mixed>}
     */
    private function mapped(string $column, array $map): array
    {
        $ids      = str_ends_with($column, '_id');
        $pdo      = DB::connection()->getDriverName() === DbDialect::SQLSRV ? DB::connection()->getPdo() : null;
        $sql      = 'case ' . $column;
        $bindings = [];
        foreach ($map as $value => $mapped) {
            $name = (string) ($ids ? $mapped : $value);
            if ($pdo === null) {
                $bindings[] = $name;
                $name       = '?';
            } else {
                $name = $pdo->quote($name);
                $name = str_starts_with($name, 'N') ? $name : 'N' . $name; // the driver's own N'…' or not, by its encoding
            }
            $sql .= $ids ? ' when ' . (int) $value . " then {$name}" : " when {$name} then " . (int) $mapped;
        }

        return [$sql . ' end', $bindings];
    }

    /** @return array<string, int>|array<int, string> value => id of $table's $column, every row (a dictionary of a few names) */
    private function dictionary(string $table, string $column): array
    {
        $map = [];
        foreach (DB::table($table)->get(['id', $column]) as $row) {
            $row = (object) $row; // whatever the app's fetch mode
            $map[(string) $row->{$column}] = (int) $row->id;
        }

        return $map;
    }

    /** The id of $name in dictionary $table, recorded if it has no row its column's collation finds equal to it. */
    private function record(string $table, string $column, string $name): int
    {
        $id = DB::table($table)->where($column, $name)->value('id');

        return (int) ($id ?? DB::table($table)->insertGetId([$column => $name]));
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
                if ($sum >= $this->chunk) {
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

        // Prefixed as Laravel prefixes its own index names (prefix_indexes): SQL Server names a
        // primary key once per schema, so two prefixes in one database collided on it (VJ-5).
        $primary = DbDialect::indexName(self::PRIMARY);

        if ($driver === DbDialect::PGSQL) {
            DB::statement("create table {$table} (term_id bigint not null, model_type_id smallint not null, model_id varchar(191) not null,"
                . ' column_id integer not null, frequency integer not null, doc_length integer not null,'
                . " constraint {$primary} primary key (term_id, model_type_id, model_id, column_id) include (frequency, doc_length))");

            return;
        }

        Schema::create(self::COMPACT, function (Blueprint $table) use ($driver, $primary) {
            $table->unsignedBigInteger('term_id');
            $table->unsignedSmallInteger('model_type_id');
            $id = $table->string('model_id', 191);
            if (DbDialect::isMySqlFamily($driver)) {
                $id->charset('utf8mb4')->collation(self::noPadBinary());
            }
            $table->unsignedInteger('column_id');
            $table->unsignedInteger('frequency');
            $table->unsignedInteger('doc_length');
            $table->primary(['term_id', 'model_type_id', 'model_id', 'column_id'], $primary);
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
     * MySQL/MariaDB: the character set and collation of the documents' model_type and the earlier
     * postings' column_name, which the dictionaries take, so their fills compare them without a
     * collation conflict whatever the connection's collation is now. Under --pretend, the connection's. Empty
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
                . " where table_schema = database() and ((table_name = ? and column_name = 'column_name') or (table_name = ? and column_name = 'model_type'))",
                [$connection->getTablePrefix() . 'fuzzy_index_postings', $connection->getTablePrefix() . 'fuzzy_index_documents']
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
