<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

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
 * range of words a transaction. A run that stopped part way resumes, warning that writes made
 * meanwhile were missed; a write while it runs is named; a second run does nothing.
 * CompactPostingsMigrationTest runs it in the upgrade from 2.0.1 and in the rollback and migrate.
 */
class ModelTermsBackfillMigrationTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/2026_10_13_000002_backfill_fuzzy_index_model_terms_table.php';

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

    /** The backfill of an index written before it counts what the index writes keep. */
    #[DataProvider('chunks')]
    public function test_the_backfill_counts_each_models_documents_of_each_word(int $chunk): void
    {
        $this->index();
        DB::table('fuzzy_index_model_terms')->delete();

        Log::spy();
        $this->backfill($chunk);

        $this->assertSame($this->expected(), $this->modelTerms());
        $this->assertSumsMatchTheDictionary();
        $this->assertFalse(Schema::hasTable('fuzzy_index_model_terms_filling'));
        Log::shouldNotHaveReceived('warning');

        // The relation's and the hidden column's words are counted, a word in two columns of a note once.
        $note = IndexManager::modelTypeId(BackfillNote::class);
        $rows = collect($this->modelTerms())->filter(fn ($row) => $row[0] === $note)->mapWithKeys(fn ($row) => [$row[2] => $row[4]])->all();
        $this->assertSame(['copper' => 3, 'vault' => 2], array_intersect_key($rows, ['copper' => 0, 'vault' => 0]));
        $post = IndexManager::modelTypeId(BackfillPost::class);
        $this->assertContains([$post, 'ada', 3, 2], collect($this->modelTerms())->map(fn ($row) => [$row[0], $row[2], $row[3], $row[4]])->all());
    }

    /**
     * A run stopped after its first range resumes from the highest word the table holds. Old code
     * wrote the index in between, in a range the stopped run had counted: the rerun cannot see it,
     * and warns, naming every indexed model.
     */
    public function test_a_stopped_run_resumes_and_names_every_model(): void
    {
        $this->index();
        DB::table('fuzzy_index_model_terms')->delete();

        try {
            $this->backfill(3, function () {
                throw new \RuntimeException('killed');
            });
            $this->fail('the run was not killed');
        } catch (\RuntimeException $e) {
            $this->assertSame('killed', $e->getMessage());
        }
        $done = (int) DB::table('fuzzy_index_model_terms')->max('term_id');
        $this->assertGreaterThan(0, $done, 'the first range committed');
        $this->assertLessThan((int) DB::table('fuzzy_index_postings')->max('term_id'), $done, 'and not the rest');
        $this->assertTrue(Schema::hasTable('fuzzy_index_model_terms_filling'));

        // Old code: post 3 gains a word of the first range (its posting and the dictionary's count).
        $post  = IndexManager::modelTypeId(BackfillPost::class);
        $first = (int) DB::table('fuzzy_index_postings')->whereNotIn('term_id', DB::table('fuzzy_index_postings')->where('model_type_id', $post)->where('model_id', '3')->select('term_id'))->min('term_id');
        $this->assertLessThanOrEqual($done, $first);
        $this->insertNamedPostings([['term_id' => $first, 'model_type' => BackfillPost::class, 'model_id' => '3', 'column_name' => 'title']]);
        DB::table('fuzzy_index_terms')->where('id', $first)->increment('doc_count');

        Log::spy();
        $this->backfill(3);

        Log::shouldHaveReceived('warning')->once()->withArgs($this->warnedNaming('resumed after an interrupted run', self::MODELS));
        $this->assertFalse(Schema::hasTable('fuzzy_index_model_terms_filling'));
        $past = fn (array $rows) => array_values(array_filter($rows, fn ($row) => $row[1] >= $done));
        $this->assertSame($past($this->expected()), $past($this->modelTerms()), 'the words from the resume point on');
    }

    /** A write between two ranges changes the meta totals: the run names that model, and only it. */
    public function test_a_write_while_it_runs_names_the_model(): void
    {
        $this->index();
        DB::table('fuzzy_index_model_terms')->delete();

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

    /** A second run after a complete one finds the table filled and does nothing; so does a run on an index with no posting. */
    public function test_a_second_run_and_a_run_on_an_empty_index_do_nothing(): void
    {
        Log::spy();
        $this->assertSame([], $this->writes(fn () => $this->backfill()), 'an empty index');

        $this->index();
        DB::table('fuzzy_index_model_terms')->delete();
        $this->backfill();
        $rows = $this->modelTerms();

        $this->assertSame([], $this->writes(fn () => $this->backfill()), 'a second run');
        $this->assertSame($rows, $this->modelTerms());
        $this->assertFalse(Schema::hasTable('fuzzy_index_model_terms_filling'));
        Log::shouldNotHaveReceived('warning');
    }
}
