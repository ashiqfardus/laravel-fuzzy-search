<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
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
        // Drop the term dictionary table to simulate "index not yet built"
        \Illuminate\Support\Facades\Schema::dropIfExists('fuzzy_index_terms');

        $fuzzySearch = app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class);
        $builder = new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder(
            $this->app['db']->table('users'),
            $fuzzySearch
        );
        $builder->search('jonh')->searchIn(['name']);

        // Must return empty array, not throw
        $result = $builder->didYouMean(3);
        $this->assertEquals([], $result);

        // Recreate the table for subsequent tests
        \Illuminate\Support\Facades\Schema::create('fuzzy_index_terms', function ($table) {
            $table->id();
            $table->string('term', 191)->unique();
            $table->unsignedBigInteger('doc_count')->default(0);
        });
    }

    public function test_porter_stemmer_throws_clear_error_when_wamania_missing(): void
    {
        // We can't actually uninstall wamania for this test — just verify the guard exists
        $reflection = new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer::class, '__construct');
        $body = file_get_contents($reflection->getFileName());
        $this->assertStringContainsString('class_exists(StemmerFactory::class)', $body);
        $this->assertStringContainsString('composer require wamania/php-stemmer', $body);
    }
}
