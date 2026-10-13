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
use Illuminate\Support\Facades\Artisan;
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

        // ER-197: 2026_10_13_000002 counts each model's documents of each word from the copied postings.
        $this->assertSame([
            [MigratedCode::class, 'apple', 5, 1], [MigratedCode::class, 'pear', 4, 1], [MigratedCode::class, 'plum', 4, 1],
            [MigratedNote::class, 'apple', 5, 2], [MigratedNote::class, 'pear', 4, 1],
        ], DB::table('fuzzy_index_model_terms as mt')->join('fuzzy_index_model_types as ty', 'ty.id', '=', 'mt.model_type_id')
            ->orderBy('ty.model_type')->orderBy('mt.term')->get(['ty.model_type', 'mt.term', 'mt.term_length', 'mt.doc_count'])
            ->map(fn ($row) => [(string) ((array) $row)['model_type'], (string) ((array) $row)['term'], (int) ((array) $row)['term_length'], (int) ((array) $row)['doc_count']])->all());

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
        // ER-197: what the index writes keep, and what 2026_10_13_000002 counts after a rollback past it (by name: down() renumbers the model types).
        $out['model terms'] = DB::table('fuzzy_index_model_terms as mt')->join('fuzzy_index_model_types as ty', 'ty.id', '=', 'mt.model_type_id')
            ->orderBy('ty.model_type')->orderBy('mt.term')->get(['ty.model_type', 'mt.term', 'mt.term_length', 'mt.doc_count'])
            ->map(fn ($row) => "{$row->model_type}|{$row->term}|" . (int) $row->term_length . '|' . (int) $row->doc_count)->all();

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

        $this->fetching($arrays, fn () => $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]));
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
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
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

        foreach (['down' => fn () => $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]), 'up' => fn () => $this->artisanOk('migrate')] as $direction => $run) {
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
        $this->artisanOk('migrate'); // the later ones: the per-model term statistics (ER-197)
    }

    /**
     * ER-179. The column names are recorded a range at a time, from the range's own postings: a
     * name that first appears in a later range, of a second model type, is copied with the rest.
     */
    public function test_a_column_name_first_seen_in_a_later_range_is_copied(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);

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
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
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
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
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
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);

        $migration = require self::PATH . '/' . self::MIGRATION . '.php';
        Schema::create('fuzzy_index_model_types', fn (Blueprint $table) => [$table->smallIncrements('id'), $table->string('model_type', 191)->unique()]);
        DB::table('fuzzy_index_model_types')->insert(['model_type' => MigratedNote::class]); // as up() fills it
        $type      = (int) DB::table('fuzzy_index_model_types')->value('id');
        (fn () => $this->createCompact())->call($migration);
        $statement = (fn () => $this->copyStatement(
            'fuzzy_index_postings', 'fuzzy_index_postings_compact',
            ['term_id', 'model_type_id', 'model_id', 'column_id', 'frequency', 'doc_length'],
            ['p.term_id', $this->mapped('p.model_type', [MigratedNote::class => $type]), 'p.model_id', $this->mapped('p.column_name', ['title' => 1]), 'p.frequency', 'd.doc_length'],
            'p.id > ?', [0]
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

    /** The migration run directly, ids copied $chunk at a time, $afterRange called after each committed range (an old-code write in between). */
    private function migrateWithWrites(int $chunk, \Closure $afterRange): void
    {
        $migration             = require self::PATH . '/' . self::MIGRATION . '.php';
        $migration->chunk      = $chunk;
        $migration->afterRange = $afterRange;
        $migration->up();
        DB::table('migrations')->insert(['migration' => self::MIGRATION, 'batch' => (int) DB::table('migrations')->max('batch') + 1]);
        $this->artisanOk('migrate'); // the later ones: the per-model term statistics (ER-197)
    }

    /** @return list<array{string, string, int, int}> $model's postings in the compact table: term, column, frequency, doc_length */
    private function compactPostingsOf(string $type, string $id): array
    {
        return $this->namedPostings('p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', $type)->where('p.model_id', $id)->orderBy('t.term')->orderBy('p.column_name')
            ->get(['t.term', 'p.column_name', 'p.frequency', 'p.doc_length'])
            ->map(fn ($row) => [(string) $row->term, (string) $row->column_name, (int) $row->frequency, (int) $row->doc_length])->all();
    }

    /**
     * An old-code index write while the copy runs (ER-183): it deletes the row's postings and
     * inserts them again past the ids the copy read. Note 1, whose postings the first range copied,
     * gains a word; note 4, whose postings no range had copied yet, is written as it was. The copy
     * then re-reads the documents written past its last id and copies them whole: both end exact,
     * and the migration warns, naming the model and `rebuild --fresh`.
     */
    public function test_a_write_while_the_copy_runs_ends_exact_and_warns(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $old       = fn (string $id) => DB::table('fuzzy_index_postings')->where('model_type', MigratedNote::class)->where('model_id', $id);
        $expected4 = (clone $old('4'))->orderBy('term_id')->get(['term_id', 'column_name', 'frequency'])->map(fn ($row) => (array) $row)->all();
        $length4   = (int) DB::table('fuzzy_index_documents')->where('model_type', MigratedNote::class)->where('model_id', '4')->value('doc_length');

        Log::spy();
        $written = false;
        $this->migrateWithWrites(3, function (int $high) use (&$written, $old, $expected4) {
            if ($written) {
                return;
            }
            $written = true;
            $this->assertLessThanOrEqual($high, (int) (clone $old('1'))->min('id'), 'note 1 is in the first range');
            $this->assertGreaterThan($high, (int) (clone $old('4'))->min('id'), 'note 4 is past it');

            // Note 1: its postings again, and one more word (the old write path's delete and insert).
            $rows = (clone $old('1'))->get(['term_id', 'column_name', 'frequency'])->map(fn ($row) => (array) $row)->all();
            (clone $old('1'))->delete();
            $word = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'zzmidcopy', 'doc_count' => 1, 'term_length' => 9]);
            foreach ([...$rows, ['term_id' => $word, 'column_name' => 'body', 'frequency' => 1]] as $row) {
                DB::table('fuzzy_index_postings')->insert(['model_type' => MigratedNote::class, 'model_id' => '1'] + $row);
            }
            DB::table('fuzzy_index_documents')->where('model_type', MigratedNote::class)->where('model_id', '1')->increment('doc_length');
            DB::table('fuzzy_index_meta')->where('model_type', MigratedNote::class)->increment('total_tokens');

            // Note 4: written again as it was.
            (clone $old('4'))->delete();
            foreach ($expected4 as $row) {
                DB::table('fuzzy_index_postings')->insert(['model_type' => MigratedNote::class, 'model_id' => '4'] + $row);
            }
        });

        $length1 = (int) DB::table('fuzzy_index_documents')->where('model_type', MigratedNote::class)->where('model_id', '1')->value('doc_length');
        $note1   = $this->compactPostingsOf(MigratedNote::class, '1');
        $this->assertContains(['zzmidcopy', 'body', 1, $length1], $note1);
        $this->assertSame([$length1], array_values(array_unique(array_column($note1, 3))), 'every posting of note 1 carries its new length');
        $this->assertCount(count($expected4), $this->compactPostingsOf(MigratedNote::class, '4'));
        $this->assertSame([$length4], array_values(array_unique(array_column($this->compactPostingsOf(MigratedNote::class, '4'), 3))));
        $this->assertSame([1], MigratedNote::search('zzmidcopy')->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->all());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, MigratedNote::class) && str_contains($message, '--fresh'));
    }

    /** ER-183. A row an old-code write removed from the index while the copy ran leaves no id behind: the meta totals changed, and the migration warns. */
    public function test_a_removal_while_the_copy_runs_warns(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);

        Log::spy();
        $done = false;
        $this->migrateWithWrites(3, function () use (&$done) {
            if ($done) {
                return;
            }
            $done   = true;
            $length = (int) DB::table('fuzzy_index_documents')->where('model_type', MigratedNote::class)->where('model_id', '1')->value('doc_length');
            DB::table('fuzzy_index_postings')->where('model_type', MigratedNote::class)->where('model_id', '1')->delete();
            DB::table('fuzzy_index_documents')->where('model_type', MigratedNote::class)->where('model_id', '1')->delete();
            DB::table('fuzzy_index_meta')->where('model_type', MigratedNote::class)->update(['total_docs' => DB::raw('total_docs - 1'), 'total_tokens' => DB::raw("total_tokens - {$length}")]);
        });

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, MigratedNote::class) && str_contains($message, 'rebuild') && str_contains($message, '--fresh'));
    }

    /** ER-183. With no write while it runs, the copy warns of nothing, and builds no index for a tail (VA-6). */
    public function test_a_copy_with_no_write_while_it_runs_does_not_warn(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);

        Log::spy();
        $tailIndex = [];
        DB::listen(function (QueryExecuted $query) use (&$tailIndex) {
            if (str_contains($query->sql, 'postings_compact_model_idx')) {
                $tailIndex[] = $query->sql;
            }
        });
        try {
            $this->migrateWithWrites(3, function () {
            });
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        Log::shouldNotHaveReceived('warning');
        $this->assertSame([], $tailIndex);
    }

    /** 2.0.1's re-index of a row whose index stays the same size, on $db: its postings deleted and inserted again (new ids), its first word replaced by $word. */
    private function oldRewrite(string $type, string $id, string $word = 'zzresumed', ?\Illuminate\Database\Connection $db = null): void
    {
        $db ??= DB::connection();
        $old  = fn () => $db->table('fuzzy_index_postings')->where('model_type', $type)->where('model_id', $id);
        $rows = $old()->orderBy('id')->get(['term_id', 'column_name', 'frequency'])->map(fn ($row) => (array) $row)->all();
        $old()->delete();
        $term = $db->table('fuzzy_index_terms')->insertGetId(['term' => $word, 'doc_count' => 1, 'term_length' => mb_strlen($word)]);
        foreach ([...array_slice($rows, 1), ['term_id' => $term, 'column_name' => (string) $rows[0]['column_name'], 'frequency' => 1]] as $row) {
            $db->table('fuzzy_index_postings')->insert(['model_type' => $type, 'model_id' => $id] + $row);
        }
    }

    /**
     * VA-5. Ids are taken at insert and committed out of order: an old-code write whose transaction
     * took its ids before another write committed is not seen when the tail reads its boundary,
     * and commits below the boundary the next round reads past, so its row keeps the postings the
     * ranges copied. Code k-2's edit keeps its size, so the meta tells nothing either: the
     * migration names every model with a posting past the ids its ranges read. (Under MySQL's
     * REPEATABLE READ the in-flight write's deletes and duplicate checks lock gaps of the earlier
     * layout's indexes, and the committed write must insert into none of them, or it would wait
     * on it, here in one process: code k-2 keeps its last word, `towel`, followed by `wire`, while
     * a write of k-1 would lock the gap past `wire`, the last word, where note 4's new word goes.)
     */
    public function test_a_write_in_flight_when_the_tail_reads_its_boundary_is_named(): void
    {
        if (in_array($this->dbDriver, ['sqlite', 'sqlsrv'], true)) {
            $this->markTestSkipped('SQLite has one writer, and SQL Server\'s locking READ COMMITTED makes the tail\'s read wait for the write instead of reading past it (the test would wait on itself); the CI MySQL, MariaDB and PostgreSQL jobs run this.');
        }

        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $default = config('database.default');
        config(['database.connections.second_writer' => config("database.connections.{$default}")]);
        $writer = DB::connection('second_writer');
        $last   = (int) DB::table('fuzzy_index_postings')->max('id');

        $armed = false;
        DB::listen(function (QueryExecuted $query) use (&$armed, $writer) {
            if ($armed && $query->connectionName !== 'second_writer' && preg_match('/^\s*delete\s+from\s+\S*fuzzy_index_postings_compact/i', $query->sql)) {
                $armed = false;
                $writer->commit(); // the write in flight commits while the tail copies the other one
            }
        });

        Log::spy();
        try {
            $this->migrateWithWrites(3, function (int $high) use ($last, $writer, &$armed) {
                if ($high < $last) {
                    return;
                }
                $writer->beginTransaction();
                $this->oldRewrite(MigratedCode::class, 'k-2', 'zzinflight', $writer); // its ids taken, not committed
                $this->oldRewrite(MigratedNote::class, '4', 'zzcommitted');             // committed, with higher ids
                $armed = true;
            });
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            DB::purge('second_writer');
        }

        $this->assertFalse($armed, 'the write in flight committed during the tail');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'php artisan fuzzy-search:rebuild "' . MigratedCode::class . '" --fresh'));
    }

    /** 2.0.1's removal of a row from the index: its postings, its document row and its share of the meta totals. */
    private function oldRemove(string $type, string $id): void
    {
        $length = (int) DB::table('fuzzy_index_documents')->where('model_type', $type)->where('model_id', $id)->value('doc_length');
        DB::table('fuzzy_index_postings')->where('model_type', $type)->where('model_id', $id)->delete();
        DB::table('fuzzy_index_documents')->where('model_type', $type)->where('model_id', $id)->delete();
        DB::table('fuzzy_index_meta')->where('model_type', $type)->update(['total_docs' => DB::raw('total_docs - 1'), 'total_tokens' => DB::raw("total_tokens - {$length}")]);
    }

    public static function resumedRuns(): array
    {
        return [
            'a write before the first run was killed'    => ['before'],
            'writes between the kill and the rerun'      => ['between'],
            'a kill in the re-copy of the rows written'  => ['tail'],
            'a posting dropped in a range that committed' => ['dropped'],
        ];
    }

    /**
     * VA-1, VA-4. The meta snapshot, the ids the copy read and the count of the postings it dropped
     * belong to one run: a run that goes on after an interrupted one copies a row the old code wrote
     * meanwhile as ordinary ranges, which keep the postings the compact table holds for it (a word
     * removed stays, a frequency keeps its old value), sees no change, and counts nothing the first
     * run dropped. So a resumed run warns once, naming every indexed model, whichever writes it
     * can or cannot see. The row written here keeps its size, so the meta totals tell nothing.
     */
    #[DataProvider('resumedRuns')]
    public function test_a_run_resumed_after_an_interrupted_one_names_every_indexed_model(string $when): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $first = (object) DB::table('fuzzy_index_postings')->orderBy('id')->first(); // in the first range
        if ($when === 'dropped') {
            DB::table('fuzzy_index_postings')->where('id', $first->id)->update(['model_id' => '999']); // no document row
        }

        $armed = false;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && preg_match('/^\s*delete\s+from\s+\S*fuzzy_index_postings_compact/i', $query->sql)) {
                $armed = false;
                throw new \RuntimeException('killed');
            }
        });
        $ranges = 0;
        try {
            $this->migrateWithWrites(3, function () use ($when, $first, &$armed, &$ranges) {
                if (++$ranges > 1) {
                    return;
                }
                if ($when === 'before' || $when === 'tail') {
                    $this->oldRewrite($first->model_type, (string) $first->model_id);
                }
                if ($when === 'tail') {
                    $armed = true; // killed in the re-copy of the row just written

                    return;
                }
                throw new \RuntimeException('killed');
            });
            $this->fail('the first run was not killed');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }
        $this->assertGreaterThan(0, (int) DB::table('fuzzy_index_postings_copied')->value('copied_id'), 'a range committed');

        if ($when === 'between') {
            $this->oldRewrite($first->model_type, (string) $first->model_id);
            $other = (object) DB::table('fuzzy_index_documents')->where(fn ($query) => $query->where('model_type', '!=', $first->model_type)->orWhere('model_id', '!=', $first->model_id))->first();
            $this->oldRemove($other->model_type, (string) $other->model_id);
        }

        Log::spy();
        $this->artisanOk('migrate');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'resumed after an interrupted run')
            && str_contains($message, 'dropped postings')
            && str_contains($message, 'php artisan fuzzy-search:rebuild "' . MigratedNote::class . '" --fresh')
            && str_contains($message, 'php artisan fuzzy-search:rebuild "' . MigratedCode::class . '" --fresh'));
    }

    /** 2.0.1's flush of $type after the copy's last range (every posting copied): its postings, documents and meta, then every word left without a posting in the earlier table. */
    private function flushAfterTheLastRange(string $type): \Closure
    {
        $last = (int) DB::table('fuzzy_index_postings')->max('id');
        $done = false;

        return function (int $high) use ($type, $last, &$done) {
            if ($done || $high < $last) {
                return;
            }
            $done = true;
            DB::table('fuzzy_index_postings')->where('model_type', $type)->delete();
            DB::table('fuzzy_index_documents')->where('model_type', $type)->delete();
            DB::table('fuzzy_index_meta')->where('model_type', $type)->delete();
            DB::table('fuzzy_index_terms')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('fuzzy_index_postings')->whereColumn('fuzzy_index_postings.term_id', 'fuzzy_index_terms.id'))->delete();
        };
    }

    private function orphanPostings(): int
    {
        return DB::table('fuzzy_index_postings as p')->leftJoin('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')->whereNull('t.id')->count();
    }

    /**
     * VA-2. The compact table has no term foreign key while the copy runs. An old-code flush
     * meanwhile deletes the words its postings leave unused in the earlier table, and the compact
     * table kept their copied postings: PostgreSQL and SQL Server then refused the foreign key after
     * the earlier table was dropped, and MySQL and MariaDB kept the postings of words that are gone.
     * The migration finishes without them, and names the flushed model (its meta row went).
     */
    public function test_an_old_code_flush_while_the_copy_runs_leaves_no_posting_of_a_deleted_word(): void
    {
        $this->index();
        $codes = fn () => array_filter($this->searches(), fn ($key) => str_starts_with($key, 'codes '), ARRAY_FILTER_USE_KEY);
        $before = $codes();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);

        // The notes, indexed first: the words only they use leave gaps among the dictionary's ids.
        Log::spy();
        $this->migrateWithWrites(3, $this->flushAfterTheLastRange(MigratedNote::class));

        $this->assertSame(0, $this->orphanPostings());
        $this->assertSame([], MigratedNote::search('porcelain')->useInvertedIndex()->typoTolerance(0)->get()->all());
        $this->assertSame($before, $codes());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'php artisan fuzzy-search:rebuild "' . MigratedNote::class . '" --fresh'));
    }

    /**
     * VA-2. A run that stopped once the earlier table was gone, before the foreign keys, left the
     * postings of the words a flush deleted: the next run deletes them, then adds the keys (on
     * PostgreSQL and SQL Server every rerun failed on the term foreign key).
     */
    public function test_a_rerun_after_a_flush_and_a_stop_before_the_foreign_keys_finishes(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);

        DB::listen(function (QueryExecuted $query) {
            if (preg_match('/^(?=.*rename)(?=.*fuzzy_index_postings_compact)/i', $query->sql)) {
                throw new \RuntimeException('stopped after the swap');
            }
        });
        try {
            $this->migrateWithWrites(3, $this->flushAfterTheLastRange(MigratedCode::class));
            $this->fail('the run did not stop');
        } catch (\RuntimeException $e) {
            $this->assertSame('stopped after the swap', $e->getMessage());
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_type_id'), 'the compact table took the earlier one\'s name');

        $this->artisanOk('migrate');

        $this->assertSame(0, $this->orphanPostings());
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertSame([1], MigratedNote::search('kettle')->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->all());
    }

    /** The migration run directly, a quarter of the postings' ids a range, killed in its third range (a stopped deploy). */
    private function stopInTheThirdRange(): void
    {
        $migration        = require self::PATH . '/' . self::MIGRATION . '.php';
        $migration->chunk = (int) ceil(DB::table('fuzzy_index_postings')->count() / 4);
        $copies           = 0;
        DB::listen(function (QueryExecuted $query) use (&$copies) {
            if (preg_match('/^\s*insert\s+into\s+\S*fuzzy_index_postings_compact/i', $query->sql) && ++$copies === 3) {
                throw new \RuntimeException('killed');
            }
        });
        try {
            $migration->up();
            $this->fail('the run was not killed');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }
        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings_compact')->count(), 'two ranges committed');
    }

    /** Exactly one warning came: the resumed run's, naming both models. */
    private function assertWarnedResumed(): void
    {
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'resumed after an interrupted run')
            && str_contains($message, 'php artisan fuzzy-search:rebuild "' . MigratedNote::class . '" --fresh')
            && str_contains($message, 'php artisan fuzzy-search:rebuild "' . MigratedCode::class . '" --fresh'));
    }

    /**
     * VA-2, UPGRADE's own large-index route after a stopped upgrade: 2.1's `fuzzy-search:clear
     * --all` empties the earlier tables, the dictionary included, but not the compact table, which
     * keeps what the stopped run copied. The next `migrate` finds nothing to copy and the meta empty
     * before and after: PostgreSQL and SQL Server refused the term foreign key, on every run, and
     * MySQL and MariaDB kept postings of words that are gone. The stopped run's progress mark makes
     * it a resumed run: it deletes them, warns, and the rebuilds the route prescribes follow.
     */
    public function test_a_stopped_upgrade_then_clear_all_then_migrate_finishes(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $this->stopInTheThirdRange();

        $this->assertSame(0, Artisan::call('fuzzy-search:clear', ['--all' => true]));
        $this->assertSame(0, DB::table('fuzzy_index_terms')->count());
        Log::spy();
        $this->artisanOk('migrate');

        $this->assertSame(0, DB::table('fuzzy_index_postings')->count(), 'the cleared index keeps none of the copied postings');
        $this->assertWarnedResumed();
        foreach ([MigratedNote::class, MigratedCode::class] as $model) {
            $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => $model, '--fresh' => true]));
        }
        $this->assertSame([1], MigratedNote::search('kettle')->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->all());
    }

    public static function deployRollbacks(): array
    {
        return ['one step' => [false], 'back to the 2.0.1 schema' => [true]];
    }

    /**
     * VA-1 (d). A deploy whose `migrate` stopped in the copy, then rolled back: the compact migration
     * was never recorded, so no down() removes what it made (the compact table, its progress mark,
     * the dictionaries). The old release indexes a row again, and the next `migrate` goes on after
     * the stale mark, keeping the row's copied postings: a resumed run, which warns naming every
     * model, and a rebuild --fresh of each makes the index exact.
     */
    #[DataProvider('deployRollbacks')]
    public function test_a_stopped_upgrade_rolled_back_by_the_deploy_then_migrated_again_warns(bool $to201): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $this->stopInTheThirdRange();
        $this->artisanOk('migrate:rollback', ['--step' => $to201 ? $this->stepsFrom('2026_09') - $this->stepsFrom(self::MIGRATION) : 1]); // the recorded ones
        $this->assertTrue(Schema::hasTable('fuzzy_index_postings_copied'), 'the stopped run\'s tables stay');

        // The old release indexes note 1 again, as one word.
        DB::table('migrated_notes')->where('id', 1)->update(['title' => 'zzafter', 'body' => null]);
        DB::table('fuzzy_index_postings')->where('model_type', MigratedNote::class)->where('model_id', '1')->delete();
        $word = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'zzafter', 'doc_count' => 1] + (Schema::hasColumn('fuzzy_index_terms', 'term_length') ? ['term_length' => 7] : []));
        DB::table('fuzzy_index_postings')->insert(['term_id' => $word, 'model_type' => MigratedNote::class, 'model_id' => '1', 'frequency' => 1]
            + (Schema::hasColumn('fuzzy_index_postings', 'column_name') ? ['column_name' => 'title'] : []));
        DB::table('fuzzy_index_documents')->where('model_type', MigratedNote::class)->where('model_id', '1')->update(['doc_length' => 1]);

        Log::spy();
        $this->artisanOk('migrate');

        $this->assertWarnedResumed();
        $this->assertSame(0, $this->orphanPostings());
        foreach ([MigratedNote::class, MigratedCode::class] as $model) {
            $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => $model, '--fresh' => true]));
        }
        $this->assertSame([], MigratedNote::search('kettle')->useInvertedIndex()->typoTolerance(0)->get()->all());
        $this->assertSame([1], MigratedNote::search('zzafter')->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->all());
    }

    /**
     * VA-6. The tail's re-copy deletes each written document's compact postings by (model_type_id,
     * model_id), and the compact table had no index on them until after the swap: every 500
     * documents the old code wrote cost a scan of everything copied (about 30 s at 96M postings,
     * while the new code's searches fail). The tail indexes them first, under a name of its own,
     * which becomes postings_model_idx after the swap; a copy with no write builds that after the
     * swap, as before.
     */
    public function test_the_tail_deletes_by_document_through_an_index(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $last = (int) DB::table('fuzzy_index_postings')->max('id');

        // The plan is read where EXPLAIN reads one simply (MySQL, MariaDB, PostgreSQL); SQLite and
        // SQL Server build and rename the same index.
        $explained  = in_array($this->dbDriver, ['mysql', 'mariadb', 'pgsql'], true);
        $plans      = [];
        $explaining = false;
        DB::listen(function (QueryExecuted $query) use (&$plans, &$explaining, $explained) {
            if (!$explained || $explaining || !preg_match('/^\s*delete\s+from\s+\S*fuzzy_index_postings_compact/i', $query->sql)) {
                return;
            }
            $explaining = true;
            try {
                if ($this->dbDriver === 'pgsql') {
                    DB::statement('set local enable_seqscan = off'); // a few rows: ask which index the delete can read
                    $plans[] = implode(' ', array_map(fn ($row) => (string) ((array) $row)['QUERY PLAN'], DB::select('explain ' . $query->sql, $query->bindings)));
                    DB::statement('set local enable_seqscan = on');
                } else {
                    $explain = $this->dbDriver === 'mysql' ? 'explain format=traditional ' : 'explain '; // MySQL 9 may default to the tree format
                    $plans[] = implode(' ', array_map(fn ($row) => (string) ((array) $row)['key'], DB::select($explain . $query->sql, $query->bindings)));
                }
            } finally {
                $explaining = false;
            }
        });
        try {
            $this->migrateWithWrites(3, function (int $high) use ($last) {
                if ($high >= $last) {
                    $this->oldRewrite(MigratedNote::class, '1', 'zztail'); // after the last range: the tail copies it again
                }
            });
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        $this->assertSame($explained, $plans !== [], 'the tail ran');
        foreach ($plans as $plan) {
            $this->assertStringContainsString('postings_compact_model_idx', $plan);
        }
        $table   = DB::connection()->getTablePrefix() . 'fuzzy_index_postings';
        $indexes = array_map(fn ($row) => strtolower((string) ((array) $row)['name']), match ($this->dbDriver) {
            'sqlite' => DB::select("select name from sqlite_master where type = 'index' and tbl_name = ?", [$table]),
            'pgsql'  => DB::select('select c.relname as name from pg_index i join pg_class c on c.oid = i.indexrelid where i.indrelid = to_regclass(quote_ident(?))', [$table]),
            'sqlsrv' => DB::select('select name from sys.indexes where object_id = object_id(?) and name is not null', [$table]),
            default  => DB::select('select distinct index_name as name from information_schema.statistics where table_schema = database() and table_name = ?', [$table]),
        });
        $this->assertContains('postings_model_idx', $indexes);
        $this->assertSame([1], MigratedNote::search('zztail')->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->all(), 'the tail copied the row again');
        $this->assertNotContains('postings_compact_model_idx', $indexes, 'renamed after the swap');
    }

    /**
     * VA-7. SQL Server takes 2,100 parameters a statement, and the copy bound each column name of a
     * range in its CASE: an index whose searchableText() is keyed per attribute, locale or custom
     * field (VC-6's shape) stopped the upgrade in its first range, and every rerun. There the names
     * are quoted literals, in the copy back too. One document, one word, 2,500 columns.
     */
    public function test_a_range_with_more_column_names_than_sql_server_takes_parameters_migrates_both_ways(): void
    {
        $names = 2500;
        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $term = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'zzmany', 'doc_count' => 1, 'term_length' => 6]);
        DB::table('fuzzy_index_documents')->insert(['model_type' => MigratedNote::class, 'model_id' => '1', 'doc_length' => $names]);
        foreach (array_chunk(range(1, $names), 400) as $chunk) { // 2,000 bindings a statement
            DB::table('fuzzy_index_postings')->insert(array_map(fn (int $i) => ['term_id' => $term, 'model_type' => MigratedNote::class, 'model_id' => '1', 'frequency' => 1, 'column_name' => "attr_{$i}"], $chunk));
        }

        $this->artisanOk('migrate');
        $this->assertSame($names, $this->namedPostings()->where('column_name', 'like', 'attr_%')->count());
        $this->assertSame(1, $this->namedPostings()->where('column_name', 'attr_2500')->where('model_type', MigratedNote::class)->count());

        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        $this->assertSame($names, DB::table('fuzzy_index_postings')->where('column_name', 'like', 'attr_%')->count());
        $this->assertSame(1, DB::table('fuzzy_index_postings')->where('column_name', 'attr_2500')->where('model_type', MigratedNote::class)->count());

        $this->artisanOk('migrate');
        $this->assertSame($names, $this->namedPostings()->where('column_name', 'like', 'attr_%')->count());
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

    public static function requirePrimaryKeyRuns(): array
    {
        return ['a fresh install' => [true], 'the upgrade of an index with data' => [false]];
    }

    /**
     * VA-3. MySQL 8.0.13+ with sql_require_primary_key on (DigitalOcean's managed MySQL default)
     * refuses a table without a primary key: the progress table had none, so a fresh install and
     * the upgrade both stopped at it, and so did every rerun.
     */
    #[DataProvider('requirePrimaryKeyRuns')]
    public function test_a_server_that_requires_a_primary_key_migrates(bool $fresh): void
    {
        if ($this->dbDriver !== 'mysql') {
            $this->markTestSkipped('MySQL 8.0.13+ only: MariaDB has no session sql_require_primary_key (its innodb_force_primary_key is global, so a test cannot set it for itself); the CI MySQL job runs this.');
        }

        $before = null;
        if (!$fresh) {
            $this->index();
            $before = $this->searches();
        }
        $this->artisanOk('migrate:rollback', ['--step' => $fresh ? $this->stepsFrom('') : $this->stepsFrom(self::MIGRATION)]);

        try {
            DB::statement('SET SESSION sql_require_primary_key = 1');
        } catch (\Illuminate\Database\QueryException) {
            $this->artisanOk('migrate');
            $this->markTestSkipped('MySQL before 8.0.13 has no sql_require_primary_key.');
        }
        try {
            $this->artisanOk('migrate');
        } finally {
            DB::statement('SET SESSION sql_require_primary_key = 0');
        }

        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_type_id'));
        if (!$fresh) {
            $this->assertSame($before, $this->searches());
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

        $this->artisanOk('migrate:rollback', ['--step' => $this->stepsFrom(self::MIGRATION)]);
        // The first word's count past a chunk: the copy takes it alone, then the rest.
        DB::table('fuzzy_index_terms')->where('id', DB::table('fuzzy_index_terms')->min('id'))->update(['doc_count' => 1_000_000]);
        $before['terms'][DB::table('fuzzy_index_terms')->where('id', DB::table('fuzzy_index_terms')->min('id'))->value('term')] = 1_000_000;

        $this->migrateFailingAfter($after);
        Log::spy();
        $this->artisanOk('migrate');

        // Nothing of the copy committed (its one range rolled back), or all of it: not a resumed copy.
        Log::shouldNotHaveReceived('warning');
        $this->assertTrue(DB::table('migrations')->where('migration', self::MIGRATION)->exists());
        $this->assertFalse(Schema::hasTable('fuzzy_index_postings_compact'));
        $this->assertSame($before, $this->searches());
    }
}
