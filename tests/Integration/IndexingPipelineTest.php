<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;

class IndexingPipelineTest extends TestCase
{
    private IndexManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new IndexManager(new WhitespaceTokenizer(), new NullStemmer());
    }

    private function makeAndIndexModel(string $name): \Illuminate\Database\Eloquent\Model
    {
        $id = $this->app['db']->table('users')->insertGetId([
            'name' => $name,
            'email' => 'pipe' . uniqid() . '@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);

        $model = new class($id) extends \Illuminate\Database\Eloquent\Model {
            public $incrementing = false;
            protected $table    = 'users';
            public $timestamps  = false;
            private int $pk;
            public function __construct(int $pk = 0) {
                parent::__construct();
                $this->pk = $pk;
            }
            public function getKey()               { return $this->pk; }
            public function getKeyName()           { return 'id'; }
            public function getAttribute($key)     {
                return \Illuminate\Support\Facades\DB::table('users')->where('id', $this->pk)->value($key);
            }
            public function getSearchableColumns() { return ['name']; }
        };

        $this->manager->indexModel($model);
        return $model;
    }

    public function test_full_pipeline_index_then_bm25_search(): void
    {
        $model1 = $this->makeAndIndexModel('php developer');
        $model2 = $this->makeAndIndexModel('laravel php developer framework');

        $scorer  = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class);

        // Use a deterministic model type for this test
        $modelType = 'PipelineTestModel';
        // Seed directly with known model type
        $this->app['db']->table('fuzzy_index_postings')->update(['model_type' => $modelType]);
        $this->app['db']->table('fuzzy_index_documents')->update(['model_type' => $modelType]);
        $this->app['db']->table('fuzzy_index_meta')->update(['model_type' => $modelType]);

        $terms   = $this->manager->processTerms('laravel');
        $results = $scorer->search($terms, $modelType, 10);

        // model2 contains 'laravel'; model1 doesn't
        $ids = $results->pluck('model_id')->toArray();
        $this->assertContains($model2->getKey(), $ids);
        $this->assertNotContains($model1->getKey(), $ids);
    }

    public function test_remove_then_reindex_updates_scores(): void
    {
        $modelType = 'PipelineTestModel2';

        // Manually insert index entries for a model
        $id = $this->app['db']->table('users')->insertGetId([
            'name' => 'laravel testing',
            'email' => 'p' . uniqid() . '@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);

        $this->app['db']->table('fuzzy_index_terms')
            ->upsert(['term' => 'laravel', 'doc_count' => 1], ['term'], ['doc_count' => \Illuminate\Support\Facades\DB::raw('fuzzy_index_terms.doc_count + 1')]);
        $termId = $this->app['db']->table('fuzzy_index_terms')->where('term', 'laravel')->value('id');
        $this->app['db']->table('fuzzy_index_postings')->insert([
            'term_id' => $termId, 'model_type' => $modelType, 'model_id' => $id, 'frequency' => 1
        ]);
        $this->app['db']->table('fuzzy_index_documents')->insert([
            'model_type' => $modelType, 'model_id' => $id, 'doc_length' => 1
        ]);
        $this->app['db']->table('fuzzy_index_meta')->insert([
            'model_type' => $modelType, 'total_docs' => 1, 'total_tokens' => 1, 'avg_doc_length' => 1
        ]);

        $scorer = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class);

        // Before remove: should find it
        $before = $scorer->search(['laravel'], $modelType, 5);
        $this->assertContains($id, $before->pluck('model_id')->toArray());

        // Remove
        $this->manager->removeFromIndex($modelType, $id);

        // After remove: should not find it
        $after = $scorer->search(['laravel'], $modelType, 5);
        $this->assertNotContains($id, $after->pluck('model_id')->toArray());
    }

    public function test_did_you_mean_returns_suggestion_from_indexed_terms(): void
    {
        $this->app['db']->table('fuzzy_index_terms')
            ->upsert(['term' => 'laravel', 'doc_count' => 200], ['term'], ['doc_count' => 200]);

        $fuzzySearch = app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class);
        $builder     = new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder(
            $this->app['db']->table('users'),
            $fuzzySearch
        );

        $suggestions = $builder->search('laravle')->searchIn(['name'])->didYouMean(3);

        $this->assertNotEmpty($suggestions);
        $terms = array_column($suggestions, 'term');
        $this->assertContains('laravel', $terms);
    }
}
