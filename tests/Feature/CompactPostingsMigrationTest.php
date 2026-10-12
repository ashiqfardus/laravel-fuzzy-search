<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\StatementPrepared;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class MigratedNote extends Model
{
    use Searchable;

    protected $table   = 'migrated_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10, 'body' => 5]];
}

class MigratedCode extends Model
{
    use Searchable;

    protected $table      = 'migrated_codes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['label' => 1]];
}

/**
 * The compact postings migration (2026_10_02_000001, ruling ER-168) rewrites the postings table: a
 * chunked copy by term_id range, each chunk committed on its own, into a table clustered by term,
 * with the model type and the column as ids and each posting's document length beside it. It must
 * leave every search as it was: from a 2.0.1 index with data (legacy '' columns, integer and string
 * keys, two models sharing words), on a 2.1 index (keys that differ only by case), under a table
 * prefix (CompactPostingsPrefixedMigrationTest), under an app-wide array fetch mode, when it is run
 * twice, after a run that stopped part way, and on SQL Server whether or not its dictionary
 * collation migration ran. down() copies the postings back into the earlier layout.
 */
class CompactPostingsMigrationTest extends TestCase
{
    private const PATH      = __DIR__ . '/../../database/migrations';
    private const MIGRATION = '2026_10_02_000001_compact_fuzzy_index_postings_table';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('migrated_notes');
        Schema::dropIfExists('migrated_codes');
        Schema::create('migrated_notes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('body')->nullable();
        });
        // The key column compares byte-wise, so it holds keys that differ only by case.
        $binary = match ($this->dbDriver) {
            'pgsql'            => 'C',
            'mysql', 'mariadb' => 'utf8mb4_bin',
            'sqlsrv'           => 'Latin1_General_100_BIN2',
            default            => 'BINARY',
        };
        Schema::create('migrated_codes', function (Blueprint $table) use ($binary) {
            $table->string('code', 40)->collation($binary)->primary();
            $table->string('label');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('migrated_notes');
        Schema::dropIfExists('migrated_codes');

        // A migrate in the test records a second batch, and the teardown's rollback undoes only the
        // last one: the first batch's tables would stay for the next class, whose prefixed tables'
        // index names collide with them on PostgreSQL (one namespace per schema).
        $this->dropLeftoverFuzzyIndexTables();

        parent::tearDown();
    }

    private function artisanOk(string $command, array $options = []): void
    {
        $this->assertSame(0, $this->artisan($command, ['--path' => realpath(self::PATH), '--realpath' => true, ...$options])->run(), $command);
    }

    /** The package's migrations from $first on, as `migrate:rollback --step` counts them. */
    private function stepsFrom(string $first): int
    {
        return count(array_filter(glob(self::PATH . '/*.php'), fn (string $file) => basename($file, '.php') >= $first));
    }

    /** Run $steps with every statement fetching arrays, as an app-wide StatementPrepared listener makes it (TD-2). */
    private function fetching(bool $arrays, \Closure $steps): void
    {
        if (!$arrays) {
            $steps();

            return;
        }

        Event::listen(StatementPrepared::class, fn (StatementPrepared $event) => $event->statement->setFetchMode(\PDO::FETCH_ASSOC));
        try {
            $steps();
        } finally {
            Event::forget(StatementPrepared::class);
        }
    }

    public static function fetchModes(): array
    {
        return ['objects' => [false], 'arrays' => [true]];
    }

    /** SQL Server: the dictionary's term column back in the database's collation, as before 2026_09_30_000001. */
    private function withoutBinaryTermsOnSqlServer(): void
    {
        $table = DB::connection()->getTablePrefix() . 'fuzzy_index_terms';
        $index = DB::selectOne("select i.name from sys.indexes i join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id where i.object_id = object_id(?) and c.name = 'term'", [$table])->name;
        DB::statement("drop index [{$index}] on [{$table}]");
        DB::statement("alter table [{$table}] alter column term nvarchar(255) collate database_default not null");
        DB::statement("create unique index [{$index}] on [{$table}] (term)");
    }

    /**
     * From a 2.0.1 index with data: postings without a column (''), the 2.0.1 key, two models sharing
     * a word, integer and string keys, and a posting whose document row is missing (an inconsistent
     * index), which the copy drops, counts and logs.
     */
    #[DataProvider('fetchModes')]
    public function test_an_index_upgraded_from_2_0_1_keeps_its_postings_and_their_lengths(bool $arrays): void
    {
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom('2026_09')]);
        $this->assertFalse(Schema::hasColumn('fuzzy_index_postings', 'column_name'), 'the 2.0.1 schema');

        $terms = [];
        foreach (['apple' => 3, 'pear' => 2, 'plum' => 1] as $term => $count) {
            $terms[$term] = DB::table('fuzzy_index_terms')->insertGetId(['term' => $term, 'doc_count' => $count]);
        }
        $documents = [[MigratedNote::class, '1', 3], [MigratedNote::class, '2', 5], [MigratedCode::class, 'k-1', 2], [MigratedCode::class, 'k-2', 1]];
        foreach ($documents as [$type, $id, $length]) {
            DB::table('fuzzy_index_documents')->insert(['model_type' => $type, 'model_id' => $id, 'doc_length' => $length]);
        }
        $postings = [
            [$terms['apple'], MigratedNote::class, '1', 3], [$terms['apple'], MigratedNote::class, '2', 1], [$terms['pear'], MigratedNote::class, '2', 4],
            [$terms['apple'], MigratedCode::class, 'k-1', 1], [$terms['pear'], MigratedCode::class, 'k-1', 1], [$terms['plum'], MigratedCode::class, 'k-2', 1],
        ];
        foreach ($postings as [$term, $type, $id, $frequency]) {
            DB::table('fuzzy_index_postings')->insert(['term_id' => $term, 'model_type' => $type, 'model_id' => $id, 'frequency' => $frequency]);
        }
        DB::table('fuzzy_index_postings')->insert(['term_id' => $terms['plum'], 'model_type' => MigratedNote::class, 'model_id' => '999', 'frequency' => 1]); // no document row
        // One row a statement: SQL Server types a batch's column from its first row (4 then 1.5).
        DB::table('fuzzy_index_meta')->insert(['model_type' => MigratedNote::class, 'total_docs' => 2, 'total_tokens' => 8, 'avg_doc_length' => 4]);
        DB::table('fuzzy_index_meta')->insert(['model_type' => MigratedCode::class, 'total_docs' => 2, 'total_tokens' => 3, 'avg_doc_length' => 1.5]);
        if ($this->dbDriver === 'sqlsrv' && $arrays) {
            // The 'arrays' case also runs as an install whose dictionary collation migration did not run.
            DB::table('migrations')->insert(['migration' => '2026_09_30_000001_binary_collation_on_fuzzy_index_terms_term_on_sqlsrv', 'batch' => 1]);
            $this->withoutBinaryTermsOnSqlServer();
        }

        Log::spy();
        $this->fetching($arrays, fn () => $this->artisanOk('migrate'));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '1 posting'));
        $this->assertFalse(Schema::hasColumn('fuzzy_index_postings', 'model_type'));
        $this->assertSame([MigratedCode::class, MigratedNote::class], DB::table('fuzzy_index_model_types')->orderBy('model_type')->pluck('model_type')->all());
        $this->assertSame([''], DB::table('fuzzy_index_columns')->pluck('name')->all(), 'the legacy column is a column of its own');

        $copied = $this->namedPostings('p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->orderBy('p.model_type')->orderBy('p.model_id')->orderBy('t.term')
            ->get(['p.model_type', 'p.model_id', 't.term', 'p.column_name', 'p.frequency', 'p.doc_length'])
            ->map(fn ($row) => [$row->model_type, (string) $row->model_id, $row->term, $row->column_name, (int) $row->frequency, (int) $row->doc_length])->all();
        $this->assertSame([
            [MigratedCode::class, 'k-1', 'apple', '', 1, 2], [MigratedCode::class, 'k-1', 'pear', '', 1, 2], [MigratedCode::class, 'k-2', 'plum', '', 1, 1],
            [MigratedNote::class, '1', 'apple', '', 3, 3], [MigratedNote::class, '2', 'apple', '', 1, 5], [MigratedNote::class, '2', 'pear', '', 4, 5],
        ], $copied);

        // Note 1 holds apple three times in three words, note 2 once in five.
        $this->assertSame(['1', '2'], array_map('strval', array_keys(app(Bm25Scorer::class)->rank(['apple'], MigratedNote::class))));
        $this->assertSame(['k-1'], array_map('strval', array_keys(app(Bm25Scorer::class)->rank(['pear'], MigratedCode::class))));
    }

    /** Notes and codes that share words, some keys differing only by case where the database can tell them apart, indexed. */
    private function index(): void
    {
        DB::table('migrated_notes')->insert([
            ['title' => 'Copper kettle', 'body' => 'a copper kettle for tea'],
            ['title' => 'Copper pan', 'body' => 'fries eggs'],
            ['title' => 'Iron pan', 'body' => 'copper handle'],
            ['title' => 'Tea pot', 'body' => 'porcelain'],
        ]);
        $codes = [['code' => 'k-1', 'label' => 'copper wire'], ['code' => 'k-2', 'label' => 'tea towel']];
        if ($this->dbDriver !== 'sqlsrv') { // model_id keeps SQL Server's case-insensitive collation, a documented limit
            $codes = [...$codes, ['code' => 'aBc', 'label' => 'copper copper pipe'], ['code' => 'AbC', 'label' => 'kettle descaler']];
        }
        DB::table('migrated_codes')->insert($codes);

        app(IndexManager::class)->indexBatch(MigratedNote::all());
        app(IndexManager::class)->indexBatch(MigratedCode::all());

        // Note 3's postings from before column weighting: no column, weight 1.
        DB::table('fuzzy_index_postings')
            ->where('model_type_id', IndexManager::modelTypeId(MigratedNote::class))
            ->where('model_id', '3')
            ->update(['column_id' => IndexManager::columnIds([''], insert: true)['']]);
    }

    /** @return array<string, mixed> what the searches and fuzzy-search:status's totals see */
    private function searches(): array
    {
        $out = [];
        foreach (['copper', 'kettle', 'copper kettle', 'tea', 'coper'] as $term) {
            $out["notes {$term}"]    = MigratedNote::search($term)->useInvertedIndex()->get()->map(fn ($m) => [$m->id, $m->_score])->all();
            $out["notes in {$term}"] = MigratedNote::search($term)->useInvertedIndex()->searchIn(['body' => 3])->get()->map(fn ($m) => [$m->id, $m->_score])->all();
            $out["codes {$term}"]    = MigratedCode::search($term)->useInvertedIndex()->get()->map(fn ($m) => [$m->code, $m->_score])->all();
            $out["count {$term}"]    = MigratedNote::search($term)->useInvertedIndex()->count();
        }
        $out['ordered'] = MigratedNote::search('copper')->useInvertedIndex()->orderBy('title')->paginate(2)->pluck('id')->all();
        $out['meta']    = DB::table('fuzzy_index_meta')->orderBy('model_type')->get()->map(fn ($r) => (array) $r)->all();
        $out['terms']   = DB::table('fuzzy_index_terms')->orderBy('term')->pluck('doc_count', 'term')->map(fn ($c) => (int) $c)->all();
        $out['legacy']  = $this->namedPostings()->where('column_name', '')->groupBy('model_type')->selectRaw('model_type, COUNT(*) as cnt')->pluck('cnt', 'model_type')->map(fn ($c) => (int) $c)->all();
        $out['count']   = DB::table('fuzzy_index_postings')->count();

        return $out;
    }

    /** A 2.1 index rolled back to the earlier layout and migrated again serves every search as it did. */
    #[DataProvider('fetchModes')]
    public function test_a_rollback_and_migrate_again_keeps_every_search(bool $arrays): void
    {
        $this->index();
        $before = $this->searches();
        $this->assertNotEmpty($before['legacy']);
        $postings = $before['count'];

        $this->fetching($arrays, fn () => $this->artisanOk('migrate:rollback', ['--step' => 1]));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_type'), 'the earlier layout');
        $this->assertFalse(Schema::hasColumn('fuzzy_index_postings', 'doc_length'));
        $this->assertFalse(Schema::hasTable('fuzzy_index_model_types'));
        $this->assertSame($postings, DB::table('fuzzy_index_postings')->count(), 'every posting copied back');

        $this->fetching($arrays, fn () => $this->artisanOk('migrate'));
        $this->assertSame($before, $this->searches());

        // A second run (a migration whose record was lost, a copy of it in the app) changes nothing.
        $this->fetching($arrays, fn () => (require self::PATH . '/' . self::MIGRATION . '.php')->up());
        $this->assertSame($before, $this->searches());
    }

    /** @return array<string, mixed> the collations of model_id and the dictionary's indexes, as the catalog reads them */
    private function convergedSchema(): array
    {
        $prefix  = DB::connection()->getTablePrefix();
        $indexes = match ($this->dbDriver) {
            'sqlite' => DB::select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$prefix . 'fuzzy_index_terms']),
            'pgsql'  => DB::select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$prefix . 'fuzzy_index_terms']),
            'sqlsrv' => DB::select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$prefix . 'fuzzy_index_terms']),
            default  => DB::select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$prefix . 'fuzzy_index_terms']),
        };
        $names = array_map(fn ($row) => strtolower((string) $row->name), $indexes);
        sort($names);

        $collations = in_array($this->dbDriver, ['mysql', 'mariadb'], true)
            ? array_map(fn ($row) => (string) $row->c, DB::select(
                "select collation_name as c from information_schema.columns where table_schema = database() and table_name in (?, ?) and column_name = 'model_id' order by table_name",
                [$prefix . 'fuzzy_index_documents', $prefix . 'fuzzy_index_postings']
            ))
            : [];

        return ['terms indexes' => $names, 'model_id collations' => $collations];
    }

    /**
     * R12-L6. A 2.1 build before round 12 recorded 2026_09_20_000001 with model_id in utf8mb4_bin
     * (which pads: 'SKU1' and 'SKU1 ' were one key) and 2026_10_01_000001 with the dictionary index
     * on (term_length, doc_count) alone; recorded migrations never run again. The compact postings
     * migration, which every install runs, brings both to the 2.1.0 schema, with no index row lost:
     * a key longer than 36 characters, and keys that differ only by case.
     */
    public function test_an_install_of_an_earlier_2_1_build_reaches_the_2_1_0_schema(): void
    {
        $fresh = $this->convergedSchema();
        $this->index();
        DB::table('migrated_codes')->insert(['code' => str_repeat('x', 40), 'label' => 'copper ingot']);
        app(IndexManager::class)->indexBatch(MigratedCode::all());
        $before = $this->searches();

        // 83c12c7's schema, in the earlier postings layout.
        $this->artisanOk('migrate:rollback', ['--step' => 1]);
        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            foreach (['fuzzy_index_postings', 'fuzzy_index_documents'] as $table) {
                DB::statement('ALTER TABLE ' . DB::getQueryGrammar()->wrapTable($table) . ' MODIFY model_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
            }
        }
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->index(['term_length', 'doc_count'], 'fuzzy_index_terms_term_length_doc_count_index'));
        Schema::table('fuzzy_index_terms', fn (Blueprint $table) => $table->dropIndex('fuzzy_index_terms_term_length_doc_count_id_index'));
        $this->assertNotSame($fresh, $this->convergedSchema());

        $this->artisanOk('migrate');

        $this->assertSame($fresh, $this->convergedSchema());
        $this->assertSame($before, $this->searches());
    }

    /**
     * VJ-5. A second install in the same database under another table prefix, with prefix_indexes
     * on as Laravel's config ships it, migrates: the primary key's name carries the prefix, as
     * Laravel's own index and constraint names do. SQL Server names a primary key once per schema.
     */
    public function test_a_second_table_prefix_in_the_same_database_migrates(): void
    {
        if (in_array($this->dbDriver, ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped('PostgreSQL and SQLite name an index once per schema or database file, whatever the prefix (a documented limit); the CI MySQL, MariaDB and SQL Server jobs run this.');
        }

        $default = config('database.default');
        config(['database.connections.second_prefix' => ['prefix' => 'sp_', 'prefix_indexes' => true] + config("database.connections.{$default}")]);
        $options = ['--database' => 'second_prefix', '--path' => realpath(self::PATH), '--realpath' => true];

        try {
            $this->assertSame(0, $this->artisan('migrate', $options)->run());
            $this->assertTrue(Schema::connection('second_prefix')->hasColumn('fuzzy_index_postings', 'model_type_id'));
        } finally {
            $this->artisan('migrate:rollback', $options)->run();
            DB::purge('second_prefix');
        }
        $this->assertFalse(Schema::connection('second_prefix')->hasTable('fuzzy_index_postings'));
    }

    /** @return list<string> the statements $run sends that insert into $table */
    private function insertsInto(string $table, \Closure $run): array
    {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements, $table) {
            if (preg_match('/^\s*insert\s+into\s+\S*' . preg_quote($table, '/') . '[`"\]]*\s/i', $query->sql)) {
                $statements[] = $query->sql;
            }
        });
        try {
            $run();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $statements;
    }

    /**
     * ER-179, ER-179a. The copy read a range of words through postings_unique_idx, joined to the
     * dictionaries: MySQL read a type's one row first, then every posting of the type, for each
     * range, and a range of words is a lookup of every posting's row, spread over the whole table
     * (7 to 9 hours at 96M postings). Each copy into the compact table now reads a range of the
     * earlier table's id, joined to the documents only, the type and the column mapped by CASE;
     * the copy back reads a range of the compact table's words, with no join at all.
     */
    public function test_each_copy_statement_reads_a_range_of_its_source_key_without_the_dictionaries(): void
    {
        $this->index();

        foreach (['down' => fn () => $this->artisanOk('migrate:rollback', ['--step' => 1]), 'up' => fn () => $this->artisanOk('migrate')] as $direction => $run) {
            $copies = $this->insertsInto($direction === 'up' ? 'fuzzy_index_postings_compact' : 'fuzzy_index_postings', $run);

            $this->assertNotEmpty($copies, $direction);
            foreach ($copies as $sql) {
                $this->assertDoesNotMatchRegularExpression('/fuzzy_index_(model_types|columns)/i', $sql, $direction);
                $this->assertMatchesRegularExpression($direction === 'up' ? '/\bwhere p\.id > \? and p\.id <= \?/i' : '/\bwhere p\.term_id > \?/i', $sql, $direction);
                $this->assertSame($direction === 'up', str_contains($sql, 'fuzzy_index_documents'), $direction);
            }
        }
    }

    /** The migration, run directly with ids copied $chunk at a time, recorded as `migrate` records it (the teardown rolls it back). */
    private function migrateDirectly(int $chunk, ?\Closure $listen = null): void
    {
        $migration        = require self::PATH . '/' . self::MIGRATION . '.php';
        $migration->chunk = $chunk;
        if ($listen !== null) {
            DB::listen($listen);
        }
        try {
            $migration->up();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }
        DB::table('migrations')->insert(['migration' => self::MIGRATION, 'batch' => (int) DB::table('migrations')->max('batch') + 1]);
    }

    /**
     * ER-179. The column names are recorded a range at a time, from the range's own postings: a
     * name that first appears in a later range, of a second model type, is copied with the rest.
     */
    public function test_a_column_name_first_seen_in_a_later_range_is_copied(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => 1]);

        // The last posting, under a column no earlier posting has.
        $term = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'zymurgy', 'doc_count' => 1, 'term_length' => 7]);
        DB::table('fuzzy_index_postings')->insert(['term_id' => $term, 'model_type' => MigratedCode::class, 'model_id' => 'k-1', 'column_name' => 'notes', 'frequency' => 2]);
        $old = DB::table('fuzzy_index_postings')->count();

        $this->migrateDirectly(1); // a range per posting

        $this->assertSame($old, DB::table('fuzzy_index_postings')->count());
        $this->assertSame([[MigratedCode::class, 'k-1', 'notes', 2]], $this->namedPostings()->where('term_id', $term)->get()
            ->map(fn ($row) => [$row->model_type, (string) $row->model_id, $row->column_name, (int) $row->frequency])->all());
        $this->assertEqualsCanonicalizing([MigratedCode::class, MigratedNote::class], DB::table('fuzzy_index_model_types')->pluck('model_type')->all());
        $this->assertSame(['k-1'], MigratedCode::search('zymurgy')->useInvertedIndex()->typoTolerance(0)->get()->pluck('code')->all());
    }

    /**
     * ER-179a. Each range commits with the mark of the ids it copied, in one transaction: a run that
     * stops in a range leaves the earlier ranges and their mark, and none of that range; the next
     * run goes on after the mark.
     */
    public function test_a_run_that_stopped_in_a_range_goes_on_after_the_last_committed_one(): void
    {
        $this->index();
        $before = $this->searches();
        $this->artisanOk('migrate:rollback', ['--step' => 1]);
        $ids   = DB::table('fuzzy_index_postings')->orderBy('id')->pluck('id')->all();
        $chunk = intdiv(count($ids), 3) ?: 1;

        $copies = 0;
        try {
            $this->migrateDirectly($chunk, function (QueryExecuted $query) use (&$copies) {
                if (preg_match('/^\s*insert\s+into\s+\S*fuzzy_index_postings_compact/i', $query->sql) && ++$copies === 2) {
                    throw new \RuntimeException('stopped in the second range');
                }
            });
            $this->fail('the run did not stop');
        } catch (\RuntimeException $e) {
            $this->assertSame('stopped in the second range', $e->getMessage());
        }

        $mark = (int) DB::table('fuzzy_index_postings_copied')->value('copied_id');
        $this->assertSame($ids[0] - 1 + $chunk, $mark, 'the first range\'s mark, not the second\'s');
        $this->assertSame(count(array_filter($ids, fn ($id) => $id <= $mark)), DB::table('fuzzy_index_postings_compact')->count(), 'the first range\'s rows, none of the second\'s');

        $this->migrateDirectly($chunk);

        $this->assertFalse(Schema::hasTable('fuzzy_index_postings_copied'));
        $this->assertSame($before, $this->searches());
    }

    /**
     * ER-179a. A write the old code makes while the copy runs can put, in a range not copied yet, a
     * posting whose key the compact table holds already: the copy skips that key, and fails on
     * nothing else.
     */
    public function test_a_key_the_compact_table_holds_already_is_skipped(): void
    {
        $this->index();
        $before = $this->searches();
        $this->artisanOk('migrate:rollback', ['--step' => 1]);
        $last = (object) DB::table('fuzzy_index_postings')->orderByDesc('id')->first();
        $ids  = DB::table('fuzzy_index_postings')->count();

        $this->migrateDirectly(intdiv($ids, 2) ?: 1, function (QueryExecuted $query) use ($last) {
            static $done = false;
            if (!$done && preg_match('/^\s*update\s+\S*fuzzy_index_postings_copied/i', $query->sql)) {
                $done = true; // after the first range: the last posting arrives in the compact table first
                DB::table('fuzzy_index_postings_compact')->insert([
                    'term_id'       => $last->term_id,
                    'model_type_id' => DB::table('fuzzy_index_model_types')->where('model_type', $last->model_type)->value('id'),
                    'model_id'      => $last->model_id,
                    'column_id'     => DB::table('fuzzy_index_columns')->where('name', $last->column_name)->value('id')
                        ?? DB::table('fuzzy_index_columns')->insertGetId(['name' => $last->column_name]),
                    'frequency'     => $last->frequency,
                    'doc_length'    => DB::table('fuzzy_index_documents')->where('model_type', $last->model_type)->where('model_id', $last->model_id)->value('doc_length'),
                ]);
            }
        });

        $this->assertSame($ids, DB::table('fuzzy_index_postings')->count());
        $this->assertSame($before, $this->searches());
    }

    /**
     * ER-179a. Skipping a held key skips nothing else: a name no CASE maps gives NULL, which the
     * copy refuses (MySQL's INSERT IGNORE stored 0, SQLite's INSERT OR IGNORE dropped the row).
     * Every range records its names before its copy, so this cannot happen; the statement is run
     * here with one left out.
     */
    public function test_a_copy_with_an_unmapped_column_name_fails_instead_of_storing_a_wrong_id(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => 1]);

        $migration = require self::PATH . '/' . self::MIGRATION . '.php';
        Schema::create('fuzzy_index_model_types', fn (Blueprint $table) => [$table->smallIncrements('id'), $table->string('model_type', 191)->unique()]);
        DB::table('fuzzy_index_model_types')->insert(['model_type' => MigratedNote::class]); // as up() fills it
        $type      = (int) DB::table('fuzzy_index_model_types')->value('id');
        (fn () => $this->createCompact())->call($migration);
        $statement = (fn () => $this->copyStatement(
            'fuzzy_index_postings', 'fuzzy_index_postings_compact',
            ['term_id', 'model_type_id', 'model_id', 'column_id', 'frequency', 'doc_length'],
            ['p.term_id', $this->mapped('p.model_type', [MigratedNote::class => $type]), 'p.model_id', $this->mapped('p.column_name', ['title' => 1]), 'p.frequency', 'd.doc_length'],
            'id', 0, null
        ))->call($migration);

        try {
            DB::affectingStatement(...$statement); // the body's postings map to no column id
            $this->fail('the copy stored a posting under no column');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertSame(0, DB::table('fuzzy_index_postings_compact')->count());
        } finally {
            Schema::dropIfExists('fuzzy_index_postings_compact');
            Schema::dropIfExists('fuzzy_index_model_types');
            $this->artisanOk('migrate');
        }
    }

    /** `migrate` with the first statement matching $after throwing once it has run, as a killed deploy leaves it. */
    private function migrateFailingAfter(string $after): void
    {
        $armed = false;
        DB::listen(function (QueryExecuted $query) use (&$armed, $after) {
            if (!$armed && preg_match($after, $query->sql)) {
                $armed = true;
                throw new \RuntimeException('simulated failure after: ' . $query->sql);
            }
        });

        try {
            $this->artisan('migrate', ['--path' => realpath(self::PATH), '--realpath' => true])->run();
            $this->fail('the simulated failure did not happen');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('simulated failure after: ', $e->getMessage());
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }
    }

    public static function interruptions(): array
    {
        return [
            'after the first chunk'      => ['/^insert into .*fuzzy_index_postings_compact/i'],
            'after the old table drops'  => ['/\bdrop table\b[^,]*fuzzy_index_postings[`"\]]*\s*$/i'],
            'after the table is renamed' => ['/^(?=.*rename)(?=.*fuzzy_index_postings_compact)/i'],
        ];
    }

    /** Each chunk commits on its own: a run that stopped part way leaves them, and the next run copies only the rest. */
    #[DataProvider('interruptions')]
    public function test_migrate_finishes_a_run_that_stopped_part_way(string $after): void
    {
        $this->index();
        $before = $this->searches();

        $this->artisanOk('migrate:rollback', ['--step' => 1]);
        // The first word's count past a chunk: the copy takes it alone, then the rest.
        DB::table('fuzzy_index_terms')->where('id', DB::table('fuzzy_index_terms')->min('id'))->update(['doc_count' => 1_000_000]);
        $before['terms'][DB::table('fuzzy_index_terms')->where('id', DB::table('fuzzy_index_terms')->min('id'))->value('term')] = 1_000_000;

        $this->migrateFailingAfter($after);
        $this->artisanOk('migrate');

        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertFalse(Schema::hasTable('fuzzy_index_postings_compact'));
        $this->assertSame($before, $this->searches());
    }
}
