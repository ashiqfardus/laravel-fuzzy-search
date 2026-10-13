<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../TestModels.php';

class BackfillNote extends Model
{
    use Searchable;

    protected $table   = 'backfill_notes';
    protected $guarded = [];
    protected $hidden  = ['secret'];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10, 'body' => 5, 'secret' => 1]];
}

/** A single-table-inheritance parent: its query returns children, indexed under their own type. */
class BackfillItem extends Model
{
    use Searchable;

    protected $table   = 'backfill_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['label' => 1]];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = ($attributes['kind'] ?? '') === 'child' ? BackfillItemChild::class : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class BackfillItemChild extends BackfillItem
{
    protected static function booted(): void
    {
        static::addGlobalScope('child', fn ($query) => $query->where('kind', 'child'));
    }
}

class BackfillAuthor extends Model
{
    protected $table   = 'backfill_authors';
    protected $guarded = [];
    public $timestamps = false;
}

/** Its index holds a relation's column, through searchableText(). */
class BackfillPost extends Model
{
    use Searchable;

    protected $table   = 'backfill_posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 2, 'author' => 1]];

    public function author(): BelongsTo
    {
        return $this->belongsTo(BackfillAuthor::class, 'author_id');
    }

    public function searchableText(): array
    {
        return ['title' => $this->title, 'author' => $this->author?->name];
    }
}

/**
 * ER-197. 2026_10_13_000002 fills fuzzy_index_model_terms from an index written before it: per
 * model type and word, count(distinct model_id) of its postings, with the word and its length, a
 * range of words a transaction, its progress in fuzzy_index_model_terms_backfill (2026_10_13_000001
 * creates it, the backfill drops it once done). Rows the index writes add meanwhile do not stop it
 * (V1-1); a run that stopped part way resumes, warning that writes made meanwhile were missed; a
 * write while it runs is named, and one to a word of a range waits for it (V1-2); a second run does
 * nothing. CompactPostingsMigrationTest runs it in the upgrade from 2.0.1 and in the rollback and
 * migrate.
 */
class ModelTermsBackfillMigrationTest extends TestCase
{
    private const PATH      = __DIR__ . '/../../database/migrations';
    private const CREATE    = self::PATH . '/2026_10_13_000001_create_fuzzy_index_model_terms_table.php';
    private const MIGRATION = self::PATH . '/2026_10_13_000002_backfill_fuzzy_index_model_terms_table.php';
    private const STATE     = 'fuzzy_index_model_terms_backfill';

    private const MODELS = [BackfillNote::class, BackfillItem::class, BackfillItemChild::class, BackfillPost::class];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('backfill_notes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('body')->nullable();
            $table->string('secret')->nullable();
        });
        Schema::create('backfill_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('label');
        });
        Schema::create('backfill_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
        Schema::create('backfill_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('author_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    private function dropTables(): void
    {
        foreach (['backfill_notes', 'backfill_items', 'backfill_authors', 'backfill_posts'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Four model types that share words: notes (a word in two of a note's columns, a word only in
     * the hidden column), an STI parent and its child, and posts with their author's name.
     */
    private function index(): void
    {
        DB::table('backfill_notes')->insert([
            ['title' => 'Copper kettle', 'body' => 'a copper kettle for tea', 'secret' => 'vault'],
            ['title' => 'Copper pan', 'body' => 'fries eggs', 'secret' => null],
            ['title' => 'Iron pan', 'body' => 'copper handle', 'secret' => 'vault code'],
            ['title' => 'Tea pot', 'body' => 'porcelain', 'secret' => null],
        ]);
        DB::table('backfill_items')->insert([
            ['kind' => 'parent', 'label' => 'copper wire'],
            ['kind' => 'child', 'label' => 'copper pipe'],
            ['kind' => 'child', 'label' => 'tea towel'],
            ['kind' => 'parent', 'label' => 'iron nail'],
        ]);
        $ada = DB::table('backfill_authors')->insertGetId(['name' => 'Ada Copper']);
        $bob = DB::table('backfill_authors')->insertGetId(['name' => 'Bob Kettle']);
        DB::table('backfill_posts')->insert([
            ['title' => 'Brewing tea', 'author_id' => $ada],
            ['title' => 'Copper care', 'author_id' => $ada],
            ['title' => 'Iron and tea', 'author_id' => $bob],
        ]);

        app(IndexManager::class)->indexBatch(BackfillNote::all());
        app(IndexManager::class)->indexBatch(BackfillItem::all()); // the children under their own type
        app(IndexManager::class)->indexBatch(BackfillPost::with('author')->get());

        $types = DB::table('fuzzy_index_model_types')->pluck('model_type')->all();
        $this->assertEqualsCanonicalizing(self::MODELS, $types, 'the STI child has a type of its own');
    }

    /** An empty fuzzy_index_model_terms and the backfill's state, as 2026_10_13_000001 leaves them, over the index as it is. */
    private function created(): void
    {
        (require self::CREATE)->up();
    }

    /** The migration, ranges of $chunk documents, $afterRange called after each committed one. */
    private function backfill(int $chunk = 250000, ?\Closure $afterRange = null): void
    {
        $migration             = require self::MIGRATION;
        $migration->chunk      = $chunk;
        $migration->afterRange = $afterRange;
        $migration->up();
    }

    /** @return list<array{int, int, string, int, int}> the table's rows: model type, term id, term, its length, doc_count */
    private function modelTerms(): array
    {
        return DB::table('fuzzy_index_model_terms')->orderBy('model_type_id')->orderBy('term_id')->get()
            ->map(fn ($row) => [(int) $row->model_type_id, (int) $row->term_id, (string) $row->term, (int) $row->term_length, (int) $row->doc_count])->all();
    }

    /** @return list<array{int, int, string, int, int}> the rows the postings and the dictionary make, counted here */
    private function expected(): array
    {
        $documents = [];
        foreach (DB::table('fuzzy_index_postings')->get(['model_type_id', 'term_id', 'model_id']) as $posting) {
            $documents[(int) $posting->model_type_id][(int) $posting->term_id][(string) $posting->model_id] = true;
        }
        $words = DB::table('fuzzy_index_terms')->get(['id', 'term', 'term_length'])->keyBy(fn ($row) => (int) $row->id);

        $rows = [];
        ksort($documents);
        foreach ($documents as $type => $terms) {
            ksort($terms);
            foreach ($terms as $term => $ids) {
                $rows[] = [$type, $term, (string) $words[$term]->term, (int) $words[$term]->term_length, count($ids)];
            }
        }

        return $rows;
    }

    /** For each word, the models' counts add up to the dictionary's. */
    private function assertSumsMatchTheDictionary(): void
    {
        $sums = [];
        foreach ($this->modelTerms() as [, $term, , , $count]) {
            $sums[$term] = ($sums[$term] ?? 0) + $count;
        }
        ksort($sums);
        $dictionary = DB::table('fuzzy_index_terms')->where('doc_count', '>', 0)->orderBy('id')->pluck('doc_count', 'id')->map(fn ($count) => (int) $count)->all();

        $this->assertSame($dictionary, $sums);
    }

    private function warnedNaming(string $text, array $models): \Closure
    {
        return fn (string $message) => str_contains($message, $text)
            && array_filter($models, fn (string $model) => !str_contains($message, 'php artisan fuzzy-search:rebuild "' . $model . '" --fresh')) === [];
    }

    public static function chunks(): array
    {
        return ['one range' => [250000], 'a range a word' => [1]];
    }

    /**
     * An index 2.0 left with a word's count one too high, and a word no posting holds any more, comes
     * out of the backfill with the dictionary equal to the models' counts: each range's words are
     * counted again from the rows just written (R13-L7).
     */
    #[DataProvider('chunks')]
    public function test_the_backfill_counts_the_dictionary_again(int $chunk): void
    {
        $this->index();
        DB::table('fuzzy_index_terms')->where('term', 'copper')->increment('doc_count');
        $orphan = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'split', 'term_length' => 5, 'doc_count' => 1]);
        $this->created();

        $this->backfill($chunk);

        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertSumsMatchTheDictionary();
        $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('id', $orphan)->value('doc_count'));
    }

    /**
     * A word a 2.0 worker added during the deploy window has a term_length of 0 (MySQL/MariaDB). The
     * backfill sets its length before it copies it, so a model's typo expansion, which reads the
     * copy, reaches the word (P6-A1).
     */
    #[DataProvider('chunks')]
    public function test_the_backfill_fills_a_length_a_2_0_worker_left_at_0(int $chunk): void
    {
        $this->index();
        DB::table('fuzzy_index_terms')->where('term', 'kettle')->update(['term_length' => 0]);
        $this->created();

        $this->backfill($chunk);

        $this->assertSame(6, (int) DB::table('fuzzy_index_terms')->where('term', 'kettle')->value('term_length'));
        $this->assertSame([6], DB::table('fuzzy_index_model_terms')->where('term', 'kettle')->pluck('term_length')->map(fn ($length) => (int) $length)->unique()->values()->all());
        $this->assertSame(0, DB::table('fuzzy_index_model_terms')->where('term_length', 0)->count());
        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertContains('kettle', array_column(app(\Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander::class)->candidates('kettl', 1, 500, BackfillNote::class, false), 'term'));
    }

    /** The backfill of an index written before it counts each model's documents of each word, as the index writes keep them, then drops its state. */
    #[DataProvider('chunks')]
    public function test_the_backfill_counts_each_models_documents_of_each_word(int $chunk): void
    {
        $this->index();
        $kept = $this->modelTerms();
        $this->created();

        Log::spy();
        $this->backfill($chunk);

        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertSame($kept, $this->modelTerms(), 'what the index writes keep');
        $this->assertSumsMatchTheDictionary();
        $this->assertFalse(Schema::hasTable(self::STATE));
        Log::shouldNotHaveReceived('warning');

        // The relation's and the hidden column's words are counted, a word in two columns of a note once.
        $note = IndexManager::modelTypeId(BackfillNote::class);
        $rows = collect($this->modelTerms())->filter(fn ($row) => $row[0] === $note)->mapWithKeys(fn ($row) => [$row[2] => $row[4]])->all();
        $this->assertSame(['copper' => 3, 'vault' => 2], array_intersect_key($rows, ['copper' => 0, 'vault' => 0]));
        $post = IndexManager::modelTypeId(BackfillPost::class);
        $this->assertContains([$post, 'ada', 3, 2], collect($this->modelTerms())->map(fn ($row) => [$row[0], $row[2], $row[3], $row[4]])->all());
    }

    /**
     * V1-1. An index write between 2026_10_13_000001 and this one (new code serving before the
     * migrate, a migrate that stopped between the two files) adds a row to the table. The backfill
     * took a table with rows for a finished one and skipped the whole index: it goes by its state.
     */
    public function test_a_row_an_index_write_added_before_it_ran_does_not_stop_it(): void
    {
        $this->index();
        $this->created();
        [$type, $term, $word, $length, $count] = $this->expected()[0];
        DB::table('fuzzy_index_model_terms')->insert(['model_type_id' => $type, 'term_id' => $term, 'term' => $word, 'term_length' => $length, 'doc_count' => $count]);

        Log::spy();
        $this->backfill();

        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertGreaterThan(1, count($this->modelTerms()));
        Log::shouldNotHaveReceived('warning');
    }

    /** The backfill run with ranges of 3 documents, killed after its first range commits. */
    private function stoppedAfterTheFirstRange(): int
    {
        try {
            $this->backfill(3, function () {
                throw new \RuntimeException('killed');
            });
            $this->fail('the run was not killed');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        }

        $done = (int) DB::table(self::STATE)->value('last_term_id');
        $this->assertGreaterThan(0, $done, 'the first range committed its end');
        $this->assertLessThan((int) DB::table('fuzzy_index_postings')->max('term_id'), $done, 'and not the rest');
        $this->assertSame([], array_filter($this->modelTerms(), fn ($row) => $row[1] > $done), 'none of the rest');

        return $done;
    }

    /**
     * A run stopped after its first range resumes after the end that range committed. Old code
     * wrote the index in between, in that range: the rerun cannot see it, and warns, naming every
     * indexed model; rebuilding them as it says makes the table exact.
     */
    public function test_a_stopped_run_resumes_and_names_every_model(): void
    {
        $this->index();
        $this->created();
        $done = $this->stoppedAfterTheFirstRange();

        // Old code: post 3 gains a word of the first range, its posting and the dictionary's count, and no model terms row.
        $post  = IndexManager::modelTypeId(BackfillPost::class);
        $first = (int) DB::table('fuzzy_index_postings')->whereNotIn('term_id', DB::table('fuzzy_index_postings')->where('model_type_id', $post)->where('model_id', '3')->select('term_id'))->min('term_id');
        $this->assertLessThanOrEqual($done, $first);
        DB::table('fuzzy_index_postings')->insert(['term_id' => $first, 'model_type_id' => $post, 'model_id' => '3', 'column_id' => $this->columnId('title'), 'frequency' => 1,
            'doc_length' => (int) DB::table('fuzzy_index_postings')->where('model_type_id', $post)->where('model_id', '3')->value('doc_length')]);
        DB::table('fuzzy_index_terms')->where('id', $first)->increment('doc_count');

        Log::spy();
        $this->backfill(3);

        Log::shouldHaveReceived('warning')->once()->withArgs($this->warnedNaming('resumed after an interrupted run', self::MODELS));
        $this->assertFalse(Schema::hasTable(self::STATE));
        $past = fn (array $rows) => array_values(array_filter($rows, fn ($row) => $row[1] > $done));
        $this->assertSame($past($this->expected()), $past($this->modelTerms()), 'the words past the resume point');
        $this->assertNotSame($this->expected(), $this->modelTerms(), 'the old code\'s write is missed');

        foreach (self::MODELS as $model) {
            $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => $model, '--fresh' => true]), $model);
        }
        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertSumsMatchTheDictionary();
    }

    /**
     * V1-1. A write of new words between a stopped run and the rerun gives them the highest ids,
     * past the stopped run's ranges: the rerun went on from the highest term id the table held, and
     * skipped every word between. It goes on from its state, and ends with every pair, exact.
     */
    public function test_a_stopped_run_then_a_write_of_new_words_ends_exact(): void
    {
        $this->index();
        $this->created();
        $this->stoppedAfterTheFirstRange();

        app(IndexManager::class)->indexBatch(collect([BackfillNote::create(['title' => 'Zygote quokka', 'body' => 'xylem'])]));

        Log::spy();
        $this->backfill(3);

        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertSumsMatchTheDictionary();
        Log::shouldHaveReceived('warning')->once()->withArgs($this->warnedNaming('resumed after an interrupted run', self::MODELS));
    }

    /** A write between two ranges changes the meta totals: the run names that model, and only it, and ends exact. */
    public function test_a_write_while_it_runs_names_the_model(): void
    {
        $this->index();
        $this->created();

        Log::spy();
        $written = false;
        $this->backfill(3, function () use (&$written) {
            if (!$written) {
                $written = true;
                app(IndexManager::class)->indexBatch(collect([BackfillNote::create(['title' => 'Copper zinc', 'body' => 'alloy'])]));
            }
        });

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => $this->warnedNaming('written while the model terms backfill ran', [BackfillNote::class])($message)
            && !str_contains($message, BackfillPost::class) && !str_contains($message, 'resumed'));
        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertSumsMatchTheDictionary();
    }

    /** @return list<string> the statements $run sends that write */
    private function writes(\Closure $run): array
    {
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements) {
            if (!preg_match('/^\s*select\b/i', $query->sql)) {
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
     * A fresh install, and an index with no posting: the backfill drops its state, and writes
     * nothing else. A second run after a complete one finds no state, and does nothing.
     */
    public function test_an_empty_index_and_a_second_run_leave_no_state_and_write_nothing(): void
    {
        $this->assertFalse(Schema::hasTable(self::STATE), 'the migrate of a fresh install');

        $this->created();
        Log::spy();
        $writes = $this->writes(fn () => $this->backfill());
        $this->assertCount(1, $writes, 'an empty index: ' . implode('; ', $writes));
        $this->assertMatchesRegularExpression('/^\s*drop table\b.*fuzzy_index_model_terms_backfill/i', $writes[0]);
        $this->assertFalse(Schema::hasTable(self::STATE));

        $this->index();
        $this->created();
        $this->backfill();
        $rows = $this->modelTerms();

        $this->assertSame([], $this->writes(fn () => $this->backfill()), 'a second run');
        $this->assertSame($rows, $this->modelTerms());
        Log::shouldNotHaveReceived('warning');
    }

    private function artisanOk(string $command, array $options = []): void
    {
        $this->assertSame(0, $this->artisan($command, ['--path' => realpath(self::PATH), '--realpath' => true, ...$options])->run(), $command);
    }

    /**
     * 2026_10_13_000001's down() drops the backfill's state with the table, after a backfill that
     * stopped part way too; the migrate after that rollback creates both again and counts every
     * word afresh: exact, and no resumed run.
     */
    public function test_a_rollback_after_a_stopped_run_then_migrate_ends_exact(): void
    {
        $this->index();
        $this->artisanOk('migrate:rollback', ['--step' => 2]);
        $this->assertFalse(Schema::hasTable('fuzzy_index_model_terms'));
        $this->assertSame(0, $this->artisan('migrate', ['--path' => realpath(self::CREATE), '--realpath' => true])->run());
        $this->stoppedAfterTheFirstRange();

        $this->artisanOk('migrate:rollback', ['--step' => 1]);
        $this->assertFalse(Schema::hasTable(self::STATE));
        $this->assertFalse(Schema::hasTable('fuzzy_index_model_terms'));

        Log::spy();
        $this->artisanOk('migrate');

        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertFalse(Schema::hasTable(self::STATE));
        Log::shouldNotHaveReceived('warning');
    }

    /** Sessions of this database waiting on a lock. */
    private function waitingOnALock(): int
    {
        return (int) match ($this->dbDriver) {
            'pgsql'  => DB::scalar("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"),
            'sqlsrv' => DB::scalar('select count(*) from sys.dm_exec_requests where database_id = db_id() and blocking_session_id <> 0'),
            default  => DB::scalar("select count(*) from information_schema.innodb_trx t join information_schema.processlist p on p.id = t.trx_mysql_thread_id where p.db = database() and t.trx_state = 'LOCK WAIT'"),
        };
    }

    /**
     * V1-2. Each range deletes its rows, then inserts them from the postings, two statements: on
     * READ COMMITTED (PostgreSQL, SQL Server, MySQL set to it) a write that committed a row for a
     * new (model, word) pair in between made the insert hit the key, or lost or doubled its delta.
     * A range now locks its words first, as a write does (on SQL Server it takes the index's write
     * lock): a write to a word of the range, in a process of its own, started between the two
     * statements, waits for the range to commit.
     */
    public function test_a_write_to_a_word_of_a_range_waits_for_the_range(): void
    {
        if ($this->dbDriver === 'sqlite') {
            $this->markTestSkipped('The writer runs in a process of its own, which an in-memory SQLite database cannot share; the CI MySQL, MariaDB, PostgreSQL and SQL Server jobs run this.');
        }
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('The writer needs a process of its own: proc_open().');
        }

        $this->index();
        DB::table('users')->where('id', 1)->update(['name' => 'John Kettle']); // a word of the notes, new to the users
        $this->created();

        $base   = sys_get_temp_dir() . '/fuzzy-backfill-writer-' . getmypid() . '-' . uniqid();
        $writer = null;
        $waited = false;
        DB::listen(function (QueryExecuted $query) use (&$writer, &$waited, $base) {
            if ($writer !== null || !preg_match('/^\s*delete\s+from\s+\S*fuzzy_index_model_terms[`"\]]?\s+where\b/i', $query->sql)) {
                return;
            }
            // Fixtures/index-writer.php: user 1 indexed by the package, from a connection of its own.
            file_put_contents("{$base}.json", json_encode(['connection' => config('database.connections.' . config('database.default')), 'go' => "{$base}.go", 'steps' => [['index', User::class, [1]]], 'names' => []]));
            $proc   = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/index-writer.php', "{$base}.json"], [1 => ['pipe', 'w'], 2 => ['file', "{$base}.err", 'w']], $pipes);
            $writer = [$proc, $pipes[1]];
            fgets($pipes[1]); // "ready"
            touch("{$base}.go");
            for ($i = 0; $i < 400 && !$waited && proc_get_status($proc)['running']; $i++) {
                usleep(50000);
                $waited = $this->waitingOnALock() > 0;
            }
        });

        try {
            $this->backfill();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
            $output = $writer === null ? '' : trim((string) stream_get_contents($writer[1]));
            if ($writer !== null) {
                fclose($writer[1]);
                proc_close($writer[0]);
            }
            $error = (string) @file_get_contents("{$base}.err");
            foreach (glob("{$base}*") ?: [] as $file) {
                @unlink($file);
            }
        }

        $this->assertNotNull($writer, 'the range deleted its rows');
        $lines = explode("\n", $output);
        $this->assertSame(['failed' => [], 'rollbacks' => 0], json_decode((string) end($lines), true), "the write: {$output} {$error}");
        $this->assertTrue($waited, 'the write to a word of the range waited for the range');
        $this->assertSame([1], User::search('kettle')->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertFalse(Schema::hasTable(self::STATE));
        $this->assertSame($this->expected(), $this->modelTerms(), 'the range and the write, each counted once');
        $this->assertSumsMatchTheDictionary();
    }
}
