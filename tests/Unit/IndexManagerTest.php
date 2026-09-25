<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

// Load shared models (Tests\User has a name/email searchable() weighting).
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IndexManagerTest extends TestCase
{
    public function test_fuzzy_index_terms_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_terms'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'term'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'doc_count'));
    }

    public function test_fuzzy_index_postings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_postings'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'term_id'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_type'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_id'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'frequency'));
    }

    public function test_fuzzy_index_meta_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_meta'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'model_type'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'total_docs'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'avg_doc_length'));
    }

    public function test_whitespace_tokenizer_splits_on_word_boundaries(): void
    {
        $tokenizer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer();
        $tokens = $tokenizer->tokenize('Hello World, PHP!');
        $this->assertEquals(['hello', 'world', 'php'], $tokens);
    }

    public function test_whitespace_tokenizer_ignores_words_under_2_chars(): void
    {
        $tokenizer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer();
        $tokens = $tokenizer->tokenize('a be cat dog');
        $this->assertContains('be', $tokens);
        $this->assertContains('cat', $tokens);
        $this->assertNotContains('a', $tokens);
    }

    public function test_whitespace_tokenizer_lowercases(): void
    {
        $tokenizer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer();
        $tokens = $tokenizer->tokenize('Laravel PHP');
        $this->assertEquals(['laravel', 'php'], $tokens);
    }

    public function test_whitespace_tokenizer_keeps_combining_marks_attached_to_their_base_letters(): void
    {
        $tokenizer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer();

        // Bengali vowel signs (U+09CB, U+09BE, U+09C7 ...) are \p{M}, not \p{L}. Splitting on
        // them shredded "মোবাইল ফোন" (mobile phone) into single consonants, which the
        // min-length filter then dropped — nothing reached the index.
        $this->assertSame(['মোবাইল', 'ফোন'], $tokenizer->tokenize('মোবাইল ফোন'));

        // Devanagari: matras and virama (हिन्दी) must stay inside the word too.
        $this->assertSame(['हिन्दी', 'भाषा'], $tokenizer->tokenize('हिन्दी भाषा'));

        // Thai vowel marks above/below the line.
        $this->assertSame(['ภาษาไทย'], $tokenizer->tokenize('ภาษาไทย'));

        // Latin with a decomposed accent (e + U+0301) keeps the mark and still lowercases.
        $this->assertSame(["cafe\u{0301}"], $tokenizer->tokenize("CAFE\u{0301}"));

        // Punctuation and whitespace remain separators.
        $this->assertSame(['মোবাইল', 'ফোন'], $tokenizer->tokenize('মোবাইল, ফোন!'));
    }

    public function test_null_stemmer_returns_word_unchanged(): void
    {
        $stemmer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer();
        $this->assertEquals('running', $stemmer->stem('running'));
        $this->assertEquals('jumps', $stemmer->stem('jumps'));
    }

    public function test_porter_stemmer_stems_english_words(): void
    {
        $stemmer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer();
        $this->assertEquals('run', $stemmer->stem('running'));
        $this->assertEquals('jump', $stemmer->stem('jumps'));
    }

    private function makeIndexManager(): \Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager
    {
        return new \Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager(
            new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer(),
            new \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer(),
            stopWords: ['the', 'a', 'an']
        );
    }

    /** Helper: create a minimal Eloquent model pointing at the users table */
    private function makeModel(array $attributes): \Illuminate\Database\Eloquent\Model
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table    = 'users';
            protected $fillable = ['name', 'email'];
            public $timestamps  = true;
            public function getSearchableColumns(): array { return ['name']; }
        };

        return $model::create(array_merge([
            'email'      => 'test' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    public function test_index_model_writes_terms_to_terms_table(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'John Doe']);

        $manager->indexModel($model);

        $this->assertDatabaseHas('fuzzy_index_terms', ['term' => 'john']);
        $this->assertDatabaseHas('fuzzy_index_terms', ['term' => 'doe']);
    }

    public function test_index_model_writes_postings_with_frequencies(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'john john doe']);

        $manager->indexModel($model);

        $termId  = $this->app['db']->table('fuzzy_index_terms')->where('term', 'john')->value('id');
        $posting = $this->app['db']->table('fuzzy_index_postings')
            ->where('term_id', $termId)->where('model_id', $model->id)->first();

        $this->assertEquals(2, $posting->frequency);
    }

    public function test_index_model_updates_meta(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'hello world']);

        $manager->indexModel($model);

        $modelType = get_class($model);
        $meta = $this->app['db']->table('fuzzy_index_meta')
            ->where('model_type', $modelType)->first();

        $this->assertEquals(1, $meta->total_docs);
        $this->assertGreaterThan(0, $meta->avg_doc_length);
    }

    public function test_remove_from_index_cleans_postings(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'hello world']);

        $manager->indexModel($model);
        $manager->removeFromIndex(get_class($model), $model->id);

        $count = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', get_class($model))
            ->where('model_id', $model->id)
            ->count();

        $this->assertEquals(0, $count);
    }

    public function test_reindex_does_not_duplicate_on_double_index(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'hello']);

        $manager->indexModel($model);
        $manager->indexModel($model);

        $count = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', get_class($model))
            ->where('model_id', $model->id)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_token_cap_prevents_index_poisoning(): void
    {
        config(['fuzzy-search.indexing.max_tokens_per_doc' => 3]);

        $manager = new \Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager(
            new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer(),
            new \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer()
        );

        $model = $this->makeModel(['name' => 'alpha beta gamma delta epsilon']); // 5 unique tokens
        $manager->indexModel($model);

        // With cap=3, only 3 postings should be created
        $count = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', get_class($model))
            ->where('model_id', $model->id)
            ->count();

        $this->assertLessThanOrEqual(3, $count);
    }

    public function test_index_model_job_handle_indexes_model_in_sync_queue(): void
    {
        // Use sync queue so the job runs immediately
        config(['queue.default' => 'sync']);
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        $model = $this->makeModel(['name' => 'job pipeline test']);
        $modelClass = get_class($model);
        $modelId    = $model->id;

        // Directly call the job's handle method (sync execution)
        $job     = new \Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob($modelClass, $modelId);
        $manager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
        $job->handle($manager);

        // Verify the model was indexed
        $this->assertDatabaseHas('fuzzy_index_terms', ['term' => 'job']);
        $termId = $this->app['db']->table('fuzzy_index_terms')->where('term', 'job')->value('id');
        $this->assertNotNull($termId);
        $count = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelClass)
            ->where('model_id', $modelId)
            ->where('term_id', $termId)
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_index_batch_indexes_multiple_models_with_few_queries(): void
    {
        $manager = $this->makeIndexManager();

        $models = [];
        for ($i = 0; $i < 5; $i++) {
            $models[] = $this->makeModel(['name' => "user {$i} test"]);
        }

        $count = $manager->indexBatch(collect($models));

        $this->assertEquals(5, $count);
        foreach ($models as $model) {
            $this->assertDatabaseHas('fuzzy_index_documents', [
                'model_type' => get_class($model),
                'model_id'   => $model->id,
            ]);
            $this->assertDatabaseHas('fuzzy_index_postings', [
                'model_type' => get_class($model),
                'model_id'   => $model->id,
            ]);
        }
    }

    public function test_index_batch_doc_count_accurate_on_reindex(): void
    {
        $manager = $this->makeIndexManager();

        $model1 = $this->makeModel(['name' => 'hello world']);
        $model2 = $this->makeModel(['name' => 'hello php']);

        // First batch: both models share the term 'hello' → doc_count should be 2
        $manager->indexBatch(collect([$model1, $model2]));

        $helloAfterFirst = $this->app['db']->table('fuzzy_index_terms')
            ->where('term', 'hello')
            ->value('doc_count');
        $this->assertEquals(2, $helloAfterFirst, 'doc_count must be 2 after first indexBatch');

        // Re-index same models (simulates fuzzy-search:rebuild without --fresh)
        $manager->indexBatch(collect([$model1, $model2]));

        $helloAfterReindex = $this->app['db']->table('fuzzy_index_terms')
            ->where('term', 'hello')
            ->value('doc_count');
        $this->assertEquals(
            2,
            $helloAfterReindex,
            'doc_count must not inflate on repeated indexBatch of the same models'
        );
    }

    public function test_index_model_writes_one_posting_per_term_and_column(): void
    {
        $user = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        app(IndexManager::class)->indexModel($user);

        $john = DB::table('fuzzy_index_terms')->where('term', 'john')->first();
        $rows = DB::table('fuzzy_index_postings')
            ->where('model_type', User::class)->where('model_id', (string) $user->getKey())
            ->where('term_id', $john->id)->orderBy('column_name')->get();

        $this->assertSame(['email', 'name'], $rows->pluck('column_name')->all());
        $this->assertSame([1, 1], $rows->pluck('frequency')->map(fn ($f) => (int) $f)->all());
        $this->assertSame(1, (int) $john->doc_count, 'a term in two columns of one document counts once');
    }

    public function test_index_batch_reindex_keeps_doc_count_correct_across_columns(): void
    {
        // Two documents so the decrement is visible: each carries "john" in BOTH name and email,
        // i.e. two posting rows per document. Re-indexing only the first must take 1 off
        // doc_count (COUNT(DISTINCT model_id)), not 2 (COUNT(*)) — with one document the `>= cnt`
        // clamp in the UPDATE would hide the difference.
        $manager = app(IndexManager::class);
        $first   = User::create(['name' => 'John Doe', 'email' => 'john@example.com']);
        $second  = User::create(['name' => 'John Roe', 'email' => 'john.roe@example.com']);
        $manager->indexBatch(collect([$first, $second]));

        $manager->indexBatch(collect([$first]));

        // COUNT(*) would decrement by 2, clamp 2 - 2 to 0 and re-add 1 for $first: doc_count 1.
        $this->assertSame(2, (int) DB::table('fuzzy_index_terms')->where('term', 'john')->value('doc_count'));
        $this->assertSame(2, DB::table('fuzzy_index_postings')
            ->where('model_id', (string) $first->getKey())
            ->where('term_id', DB::table('fuzzy_index_terms')->where('term', 'john')->value('id'))
            ->count());
    }

    public function test_legacy_posting_rows_default_to_an_empty_column_name(): void
    {
        $termId = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'legacy', 'doc_count' => 1, 'term_length' => 6]);
        DB::table('fuzzy_index_postings')->insert(['term_id' => $termId, 'model_type' => User::class, 'model_id' => '999', 'frequency' => 1]);

        $this->assertSame('', DB::table('fuzzy_index_postings')->where('term_id', $termId)->value('column_name'));
    }

    public function test_meta_does_not_inflate_on_reindex(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'meta test']);

        $manager->indexModel($model);
        $manager->indexModel($model); // re-index — should NOT inflate
        $manager->indexModel($model); // re-index again

        $totalDocs = $this->app['db']->table('fuzzy_index_meta')
            ->where('model_type', get_class($model))
            ->value('total_docs');

        $this->assertEquals(1, $totalDocs); // only ONE document, regardless of re-indexes
    }

    public function test_observer_dispatches_index_job_on_model_save(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => true]);

        \Illuminate\Support\Facades\Queue::fake();

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table    = 'users';
            protected $fillable = ['name', 'email'];
            public $timestamps  = true;
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $model::create([
            'name' => 'Index Observer Test',
            'email' => 'observer' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\Queue::assertPushed(\Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob::class);
    }

    public function test_did_you_mean_returns_empty_when_index_tables_missing(): void
    {
        // Drop the term dictionary to simulate "index not yet built". Postings hold a
        // foreign key to terms, so run the real migrations' down() in child-first order —
        // this is FK-safe on MySQL, PostgreSQL and SQL Server (a bare drop of the parent
        // table fails there), and it restores the exact production schema afterwards.
        $migrationsDir     = __DIR__ . '/../../database/migrations/';
        $termsMigration    = require $migrationsDir . '2026_05_02_205327_create_fuzzy_index_terms_table.php';
        $postingsMigration = require $migrationsDir . '2026_05_02_205328_create_fuzzy_index_postings_table.php';
        $postingsMigration->down();
        $termsMigration->down();

        // An Eloquent builder: without a model didYouMean() returns [] before reading the dictionary.
        $builder = User::search('jonh')->searchIn(['name']);

        // Must return empty array, not throw
        $result = $builder->didYouMean(3);
        $this->assertEquals([], $result);

        // Recreate both tables so the migrator's rollback in tearDown finds them.
        $termsMigration->up();
        $postingsMigration->up();
    }

    public function test_porter_stemmer_throws_clear_error_when_wamania_missing(): void
    {
        // We can't actually uninstall wamania for this test — just verify the guard exists
        $reflection = new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer::class, '__construct');
        $body = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('class_exists(StemmerFactory::class)', $body);
        // A bare `composer require wamania/php-stemmer` installs v4, which has no Wamania\Snowball\English.
        $this->assertStringContainsString('composer require "wamania/php-stemmer:^1.2"', $body);
    }

    public function test_term_upsert_increment_is_table_qualified_for_postgres(): void
    {
        // PostgreSQL rejects an unqualified "doc_count + ..." inside ON CONFLICT DO UPDATE
        // (SQLSTATE 42702, ambiguous between the target row and EXCLUDED). The raise is "+ 1" on
        // MySQL/MariaDB and the inserted row's own doc_count elsewhere (ER-74).
        //
        // DB::pretend() can't drive this to completion: indexModel() upserts terms and then
        // immediately reads their generated ids back within the same call, but pretend() makes
        // every read a no-op (Connection::select() returns [] while pretending), so it always
        // crashes before the query log could be inspected. We instead let the call execute for
        // real and capture the compiled SQL via DB::listen(). The qualified/unqualified
        // increment expression is embedded directly in the compiled SQL text (it's a DB::raw()
        // fragment, not a bound parameter), so inspecting the raw SQL is sufficient.
        $id = $this->app['db']->table('users')->insertGetId([
            'name' => 'alpha beta', 'email' => 'q@test.com',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $model   = $this->makeIndexableModel($id);
        $manager = $this->makeIndexManager();

        $queries = [];
        $this->app['db']->listen(function ($query) use (&$queries) {
            $queries[] = ['query' => $query->sql];
        });

        $manager->indexModel($model);

        // The upsert that inserts new terms and, on a conflict, raises doc_count: an INSERT, or
        // on SQL Server a MERGE.
        $termUpserts = array_values(array_filter($queries, fn ($q) =>
            preg_match('/^\s*(insert|merge)\b/i', $q['query']) && str_contains($q['query'], 'fuzzy_index_terms') && str_contains($q['query'], 'doc_count + ')
        ));

        $this->assertNotEmpty($termUpserts, 'expected a terms upsert');
        foreach ($termUpserts as $q) {
            $this->assertStringContainsString('fuzzy_index_terms.doc_count + ', $q['query']);
            $this->assertStringNotContainsString('= doc_count + ', $q['query']);
        }
    }

    public function test_shared_term_doc_count_reaches_two_when_indexed_by_two_models(): void
    {
        $manager = $this->makeIndexManager();

        foreach (['hello world', 'hello there'] as $name) {
            $id = $this->app['db']->table('users')->insertGetId([
                'name' => $name, 'email' => 'u' . uniqid() . '@test.com',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $manager->indexModel($this->makeIndexableModel($id));
        }

        $this->assertSame(2, (int) $this->app['db']->table('fuzzy_index_terms')->where('term', 'hello')->value('doc_count'));
        $this->assertSame(1, (int) $this->app['db']->table('fuzzy_index_terms')->where('term', 'world')->value('doc_count'));
    }

    /**
     * SQL Server re-runs a MERGE that lost a race for a new key (2601/2627), but only while
     * XACT_ABORT is off. With it on, the error has rolled back the whole write already, and the
     * driver silently opens a new transaction at the next statement: a re-run and everything
     * after it would commit without what ran before. So the error must reach the caller, and
     * nothing may commit. A MERGE whose source holds one new key twice fails this way every time.
     */
    public function test_a_duplicate_key_under_xact_abort_fails_the_write_instead_of_rerunning(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('XACT_ABORT is SQL Server only.');
        }

        $upsert = new \ReflectionMethod(IndexManager::class, 'upsertShared');
        $upsert->setAccessible(true); // a no-op since PHP 8.1, kept for readers
        $merges = 0; // counted before each run: a failed statement fires no QueryExecuted
        DB::connection()->beforeExecuting(function (string $sql) use (&$merges) {
            $merges += (int) (bool) preg_match('/^\s*merge\b.*fuzzy_index_terms/is', $sql);
        });

        DB::unprepared('SET XACT_ABORT ON');
        $reached = false;
        try {
            DB::transaction(function () use ($upsert, &$reached) {
                DB::table('fuzzy_index_terms')->insert(['term' => 'before', 'doc_count' => 1, 'term_length' => 6]);
                $upsert->invoke(
                    $this->makeIndexManager(),
                    'fuzzy_index_terms',
                    [['term' => 'twice', 'doc_count' => 1, 'term_length' => 5], ['term' => 'twice', 'doc_count' => 1, 'term_length' => 5]],
                    ['term'],
                    ['doc_count' => DB::raw('fuzzy_index_terms.doc_count + 1')],
                );
                $reached = true;
                DB::table('fuzzy_index_terms')->insert(['term' => 'after', 'doc_count' => 1, 'term_length' => 5]);
            });
            $this->fail('the duplicate key did not reach the caller');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertContains($e->errorInfo[1] ?? null, [2601, 2627]);
        } finally {
            DB::unprepared('SET XACT_ABORT OFF');
        }

        $this->assertSame(1, $merges, 'the MERGE was re-run');
        $this->assertFalse($reached);
        $this->assertSame([], DB::table('fuzzy_index_terms')->whereIn('term', ['before', 'twice', 'after'])->pluck('term')->all());
    }

    public function test_meta_row_creation_does_not_use_insert_or_ignore(): void
    {
        // Laravel's SqlServerGrammar throws for insertOrIgnore(); the meta row must be
        // created with a portable statement (upsert) so SQL Server can index at all.
        //
        // DB::pretend() can't drive this to completion (same root cause as the comment on
        // test_term_upsert_increment_is_table_qualified_for_postgres below): pretend() has to
        // escape bound values into a human-readable SQL string, and get_class() on these
        // anonymous test models embeds a NUL byte, which the escaper rejects. We capture the
        // compiled SQL via DB::listen() instead and let the call execute for real.
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'meta probe']);

        $queries = [];
        $this->app['db']->listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $manager->indexModel($model);

        $sql = strtolower(implode("\n", $queries));

        $this->assertStringNotContainsString('insert or ignore', $sql);
        $this->assertStringNotContainsString('insert ignore', $sql);
        $this->assertStringContainsString('fuzzy_index_meta', $sql);
    }

    public function test_meta_row_is_created_once_and_counters_survive_reindex(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'stable counters']);

        $manager->indexModel($model);
        $manager->indexModel($model);

        $rows = $this->app['db']->table('fuzzy_index_meta')->where('model_type', get_class($model))->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows->first()->total_docs);
        $this->assertSame(2, (int) $rows->first()->total_tokens);
    }

    public function test_numeric_tokens_are_bound_as_strings(): void
    {
        // PHP normalises numeric-string array keys (e.g. '10') to int keys. Since the token
        // map is keyed by term, array_keys($tokens) yields int(10) for a purely numeric token,
        // and that int is then bound straight into the query. SQL Server's MERGE ... USING
        // (VALUES (...)) infers one type per column from the first batch of bindings, so a
        // mixed int/string 'term' column blows up with "Conversion failed when converting the
        // nvarchar value 'paginate' to data type int". Every binding for this term must be a
        // string.
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'paginate user 10']);

        $queries = [];
        $this->app['db']->listen(function ($query) use (&$queries) {
            $queries[] = $query;
        });

        $manager->indexModel($model);

        $termQueries = array_filter($queries, fn ($q) => str_contains($q->sql, 'fuzzy_index_terms'));

        $bindings = [];
        foreach ($termQueries as $q) {
            foreach ($q->bindings as $binding) {
                $bindings[] = $binding;
            }
        }

        $intTens = array_filter($bindings, fn ($b) => $b === 10);
        $this->assertEmpty($intTens, 'no binding against fuzzy_index_terms should be the PHP integer 10');
        $this->assertContains('10', $bindings, 'the numeric token must be bound as the string "10"');
    }

    public function test_numeric_token_is_indexed_and_searchable(): void
    {
        $manager = $this->makeIndexManager();
        $model   = $this->makeModel(['name' => 'order 10 confirmed']);

        $manager->indexModel($model);

        $this->assertDatabaseHas('fuzzy_index_terms', ['term' => '10']);

        $scorer  = new \Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer();
        $results = $scorer->search(['10'], get_class($model), 5);

        $this->assertContains($model->getKey(), $results->pluck('model_id')->toArray());
    }

    /**
     * Regression (B6): empty($value) treats the legitimate string "0" as empty and
     * skips indexing it. The default WhitespaceTokenizer also drops 1-char tokens, so
     * we swap in a stub tokenizer that returns the text unchanged to observe the guard
     * in isolation.
     */
    public function test_index_model_indexes_a_searchable_column_holding_the_string_zero(): void
    {
        $tokenizer = new class implements \Ashiqfardus\LaravelFuzzySearch\Indexing\TokenizerInterface {
            public function tokenize(string $text): array
            {
                return [$text];
            }
        };

        $manager = new \Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager(
            $tokenizer,
            new \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer()
        );

        $model = $this->makeModel(['name' => '0']);

        $manager->indexModel($model);

        $this->assertDatabaseHas('fuzzy_index_terms', ['term' => '0']);

        $termId = $this->app['db']->table('fuzzy_index_terms')->where('term', '0')->value('id');
        $this->assertNotNull($termId, 'term "0" should have been written to fuzzy_index_terms');

        $posting = $this->app['db']->table('fuzzy_index_postings')
            ->where('term_id', $termId)->where('model_id', $model->id)->first();
        $this->assertNotNull($posting, 'a posting for term "0" should exist for the indexed model');
    }

    /** Anonymous model bound to an existing users row (mirrors IndexingPipelineTest). */
    private function makeIndexableModel(int $id): \Illuminate\Database\Eloquent\Model
    {
        return new class($id) extends \Illuminate\Database\Eloquent\Model {
            public $incrementing = false;
            protected $table    = 'users';
            public $timestamps  = false;
            private int $pk;
            public function __construct(int $pk = 0) { parent::__construct(); $this->pk = $pk; }
            public function getKey()               { return $this->pk; }
            public function getKeyName()           { return 'id'; }
            public function getAttribute($key)     { return \Illuminate\Support\Facades\DB::table('users')->where('id', $this->pk)->value($key); }
            public function getSearchableColumns() { return ['name']; }
        };
    }
}
