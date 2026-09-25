<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;

class Bm25ScorerTest extends TestCase
{
    private IndexManager $manager;
    private Bm25Scorer   $scorer;
    private string       $modelType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager   = new IndexManager(new WhitespaceTokenizer(), new NullStemmer());
        $this->scorer    = new Bm25Scorer(k1: 1.5, b: 0.75);
        $this->modelType = 'TestBm25Model';
    }

    private function seedDoc(string $name): int
    {
        $id = $this->app['db']->table('users')->insertGetId([
            'name' => $name,
            'email' => 'bm25doc' . uniqid() . '@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);

        $terms = $this->manager->processTerms($name);
        foreach ($terms as $term) {
            $this->app['db']->table('fuzzy_index_terms')
                ->upsert(['term' => $term, 'doc_count' => 1], ['term'], ['doc_count' => \DB::raw('fuzzy_index_terms.doc_count + 1')]);
            $termId = $this->app['db']->table('fuzzy_index_terms')->where('term', $term)->value('id');
            $freq   = substr_count(strtolower($name), $term);
            $this->app['db']->table('fuzzy_index_postings')->insert([
                'term_id' => $termId, 'model_type' => $this->modelType,
                'model_id' => $id, 'frequency' => max(1, $freq),
            ]);
        }

        $termCount = count($terms);

        $this->app['db']->table('fuzzy_index_documents')->updateOrInsert(
            ['model_type' => $this->modelType, 'model_id' => $id],
            ['doc_length' => $termCount]
        );

        $this->app['db']->table('fuzzy_index_meta')->upsert([
            'model_type' => $this->modelType, 'total_docs' => 1,
            'total_tokens' => $termCount, 'avg_doc_length' => $termCount,
        ], ['model_type'], [
            'total_docs'     => \DB::raw('fuzzy_index_meta.total_docs + 1'),
            'total_tokens'   => \DB::raw("fuzzy_index_meta.total_tokens + {$termCount}"),
            'avg_doc_length' => \DB::raw('fuzzy_index_meta.total_tokens / fuzzy_index_meta.total_docs'),
        ]);

        return $id;
    }

    public function test_bm25_returns_empty_for_unknown_terms(): void
    {
        $results = $this->scorer->search(['xyzzy_nonexistent'], $this->modelType, 5);
        $this->assertTrue($results->isEmpty());
    }

    public function test_bm25_returns_empty_when_no_meta(): void
    {
        $results = $this->scorer->search(['john'], 'NonExistentModelType', 5);
        $this->assertTrue($results->isEmpty());
    }

    public function test_bm25_result_has_model_id_and_score(): void
    {
        $this->seedDoc('laravel framework');

        $results = $this->scorer->search(['laravel'], $this->modelType, 5);

        $this->assertNotEmpty($results);
        $first = $results->first();
        $this->assertObjectHasProperty('model_id', $first);
        $this->assertObjectHasProperty('score', $first);
        $this->assertGreaterThan(0, $first->score);
    }

    public function test_bm25_returns_results_in_descending_score_order(): void
    {
        $this->seedDoc('john smith');
        $id3 = $this->seedDoc('john john john');

        $results = $this->scorer->search(['john'], $this->modelType, 5);

        $this->assertGreaterThanOrEqual(1, $results->count());
        $ids = $results->pluck('model_id')->toArray();
        $this->assertContains($id3, $ids);
        // $id3 has 'john' 3 times, should rank first (or at least be present)
        $this->assertEquals($id3, $results->first()->model_id);
    }

    public function test_search_builder_use_inverted_index_returns_bm25_ordered_results(): void
    {
        $manager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);

        $u1 = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'name' => 'laravel developer', 'email' => 'u1bm25@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);
        $u2 = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'name' => 'php developer laravel laravel', 'email' => 'u2bm25@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $manager->indexModel($model::find($u1));
        $manager->indexModel($model::find($u2));

        $deprecations = $this->deprecationsFrom(function () use ($model, &$results) {
            $results = $model::search('laravel')->useIndex()->get();
        });

        $this->assertSame(['useIndex() is deprecated since v2.0.0; use useInvertedIndex() instead.'], $deprecations);

        $this->assertGreaterThan(0, $results->count());
        $this->assertEquals($u2, $results->first()->id); // u2 has 'laravel' twice → higher BM25
    }

    public function test_use_inverted_index_on_query_builder_falls_back_gracefully(): void
    {
        $fuzzySearch = app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class);
        $builder     = new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder(
            $this->app['db']->table('users'),
            $fuzzySearch
        );

        // Must NOT throw even without a model class — falls back to LIKE
        $results = $builder->search('john')->searchIn(['name'])->useInvertedIndex()->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_use_inverted_index_with_explicit_model_class_on_query_builder(): void
    {
        $manager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $id = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'name' => 'explicit test', 'email' => 'explicit' . uniqid() . '@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);
        $manager->indexModel($model::find($id));

        $fuzzySearch = app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class);
        $builder     = new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder(
            $this->app['db']->table('users'),
            $fuzzySearch
        );

        $results = $builder
            ->search('explicit')
            ->searchIn(['name'])
            ->useInvertedIndex(get_class($model))
            ->get();

        $this->assertGreaterThan(0, $results->count());
    }

    public function test_paginate_uses_bm25_path_when_inverted_index_enabled(): void
    {
        $manager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        // Insert 30 users — first 5 have 'laravel' twice (higher BM25 score)
        $highScoreIds = [];
        for ($i = 1; $i <= 30; $i++) {
            $name = $i <= 5 ? "laravel laravel paginate$i" : "paginate user $i";
            $id = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
                'name' => $name,
                'email' => "paginatebm25_{$i}@test.com",
                'created_at' => now(), 'updated_at' => now()
            ]);
            if ($i <= 5) {
                $highScoreIds[] = $id;
            }
        }

        // Only index the newly-inserted rows to avoid cross-test noise
        foreach ($model::whereIn('id', array_merge(
            $highScoreIds,
            \Illuminate\Support\Facades\DB::table('users')
                ->where('name', 'like', 'paginate user %')
                ->pluck('id')->toArray()
        ))->get() as $u) {
            $manager->indexModel($u);
        }

        // Paginate via BM25 path
        $deprecations = $this->deprecationsFrom(function () use ($model, &$page1) {
            $page1 = $model::search('laravel')->useIndex()->paginate(5);
        });

        $this->assertSame(['useIndex() is deprecated since v2.0.0; use useInvertedIndex() instead.'], $deprecations);

        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $page1);
        $this->assertGreaterThan(0, $page1->count());
        // The top result should be one of our high-score rows (laravel appearing twice)
        $this->assertContains($page1->first()->id, $highScoreIds);
    }

    public function test_legacy_rows_score_at_weight_one_whatever_the_column_weights(): void
    {
        // seedDoc() inserts postings without column_name — i.e. rows written before this phase.
        $this->seedDoc('alpha one');
        $this->seedDoc('alpha alpha two');

        $plain    = $this->scorer->rank(['alpha'], $this->modelType);
        $weighted = $this->scorer->rank(['alpha'], $this->modelType, ['name' => 10, 'email' => 1]);

        $this->assertCount(2, $plain);
        $this->assertSame($plain, $weighted);
    }
}
