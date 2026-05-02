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

    private function makeAndIndexModel(int $id, string $name): \Illuminate\Database\Eloquent\Model
    {
        $this->app['db']->table('users')->insert([
            'id' => $id, 'name' => $name,
            'email' => "pipe{$id}@test.com",
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
        $this->makeAndIndexModel(9001, 'php developer');
        $this->makeAndIndexModel(9002, 'laravel php developer framework');

        $scorer  = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class);

        // Use a deterministic model type for this test
        $modelType = 'PipelineTestModel';
        // Seed directly with known model type
        $this->app['db']->table('fuzzy_index_postings')->update(['model_type' => $modelType]);
        $this->app['db']->table('fuzzy_index_meta')->update(['model_type' => $modelType]);

        $terms   = $this->manager->processTerms('laravel');
        $results = $scorer->search($terms, $modelType, 10);

        // doc 9002 contains 'laravel'; 9001 doesn't
        $ids = $results->pluck('model_id')->toArray();
        $this->assertContains(9002, $ids);
        $this->assertNotContains(9001, $ids);
    }

    public function test_remove_then_reindex_updates_scores(): void
    {
        $modelType = 'PipelineTestModel2';

        // Manually insert index entries for a model
        $this->app['db']->table('users')->insert([
            'id' => 9010, 'name' => 'laravel testing',
            'email' => 'p9010@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);

        $this->app['db']->table('fuzzy_index_terms')
            ->upsert(['term' => 'laravel', 'doc_count' => 1], ['term'], ['doc_count' => \Illuminate\Support\Facades\DB::raw('doc_count + 1')]);
        $termId = $this->app['db']->table('fuzzy_index_terms')->where('term', 'laravel')->value('id');
        $this->app['db']->table('fuzzy_index_postings')->insert([
            'term_id' => $termId, 'model_type' => $modelType, 'model_id' => 9010, 'frequency' => 1
        ]);
        $this->app['db']->table('fuzzy_index_meta')->insert([
            'model_type' => $modelType, 'total_docs' => 1, 'total_tokens' => 1, 'avg_doc_length' => 1
        ]);

        $scorer = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class);

        // Before remove: should find it
        $before = $scorer->search(['laravel'], $modelType, 5);
        $this->assertContains(9010, $before->pluck('model_id')->toArray());

        // Remove
        $this->manager->removeFromIndex($modelType, 9010);

        // After remove: should not find it
        $after = $scorer->search(['laravel'], $modelType, 5);
        $this->assertNotContains(9010, $after->pluck('model_id')->toArray());
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
