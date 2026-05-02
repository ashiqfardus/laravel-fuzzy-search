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

    private function seedDoc(int $id, string $name): void
    {
        $this->app['db']->table('users')->insert([
            'id' => $id, 'name' => $name,
            'email' => "bm25doc{$id}@test.com",
            'created_at' => now(), 'updated_at' => now()
        ]);

        $terms = $this->manager->processTerms($name);
        foreach ($terms as $term) {
            $this->app['db']->table('fuzzy_index_terms')
                ->upsert(['term' => $term, 'doc_count' => 1], ['term'], ['doc_count' => \DB::raw('doc_count + 1')]);
            $termId = $this->app['db']->table('fuzzy_index_terms')->where('term', $term)->value('id');
            $freq   = substr_count(strtolower($name), $term);
            $this->app['db']->table('fuzzy_index_postings')->insert([
                'term_id' => $termId, 'model_type' => $this->modelType,
                'model_id' => $id, 'frequency' => max(1, $freq),
            ]);
        }

        $termCount = count($terms);
        $this->app['db']->table('fuzzy_index_meta')->upsert([
            'model_type' => $this->modelType, 'total_docs' => 1,
            'total_tokens' => $termCount, 'avg_doc_length' => $termCount,
        ], ['model_type'], [
            'total_docs'     => \DB::raw('total_docs + 1'),
            'total_tokens'   => \DB::raw("total_tokens + {$termCount}"),
            'avg_doc_length' => \DB::raw('total_tokens / total_docs'),
        ]);
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
        $this->seedDoc(1001, 'laravel framework');

        $results = $this->scorer->search(['laravel'], $this->modelType, 5);

        $this->assertNotEmpty($results);
        $first = $results->first();
        $this->assertObjectHasProperty('model_id', $first);
        $this->assertObjectHasProperty('score', $first);
        $this->assertGreaterThan(0, $first->score);
    }

    public function test_bm25_returns_results_in_descending_score_order(): void
    {
        $this->seedDoc(1002, 'john smith');
        $this->seedDoc(1003, 'john john john');

        $results = $this->scorer->search(['john'], $this->modelType, 5);

        $this->assertGreaterThanOrEqual(1, $results->count());
        $ids = $results->pluck('model_id')->toArray();
        $this->assertContains(1003, $ids);
        // 1003 has 'john' 3 times, should rank first (or at least be present)
        $this->assertEquals(1003, $results->first()->model_id);
    }
}
