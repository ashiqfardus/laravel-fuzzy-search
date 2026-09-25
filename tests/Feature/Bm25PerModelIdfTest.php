<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * BM25's idf reads the term's document frequency within the searched model, the same
 * population as N (that model's total_docs). The dictionary's doc_count counts every model's
 * documents, so once another model used a word more often than this model has rows, the idf
 * went negative and the best match ranked last.
 */
class Bm25PerModelIdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The review's repro: the 7 seeded users, then 30 products that all say "john".
        for ($i = 1; $i <= 30; $i++) {
            Product::create(['title' => "John widget {$i}", 'description' => 'john', 'price' => 1]);
        }

        app(IndexManager::class)->indexBatch(User::all());
        app(IndexManager::class)->indexBatch(Product::all());
    }

    private function docCount(string $term): int
    {
        return (int) DB::table('fuzzy_index_terms')->where('term', $term)->value('doc_count');
    }

    public function test_the_exact_match_ranks_first_with_a_positive_raw_score_when_another_model_shares_the_word(): void
    {
        $ranked = app(Bm25Scorer::class)->rank(['john'], User::class);
        $johnId = User::where('name', 'John Doe')->value('id');

        $this->assertEquals([$johnId], array_keys($ranked)); // SQL Server may hand the key back as a string
        $this->assertGreaterThan(0, $ranked[$johnId]);

        $this->assertSame('John Doe', User::search('john')->useInvertedIndex()->get()->first()->name);
        $this->assertSame('John Doe', User::search('john')->useInvertedIndex()->first()->name);
        $this->assertSame('John Doe', User::search('john')->useInvertedIndex()->paginate(3)->items()[0]->name);
        $this->assertSame('John Doe', User::search('john')->useInvertedIndex()->simplePaginate(3)->items()[0]->name);
    }

    public function test_no_score_is_negative_on_any_path(): void
    {
        $results = User::search('john')->useInvertedIndex()->get();

        $this->assertNotEmpty($results);
        foreach ($results as $user) {
            $this->assertGreaterThan(0, $user->_raw_score, $user->name);
            $this->assertGreaterThan(0, $user->_score, $user->name);
        }

        foreach (User::search('john')->useInvertedIndex()->paginate(10)->items() as $user) {
            $this->assertGreaterThan(0, $user->_score, $user->name);
        }
    }

    public function test_three_fresh_rebuilds_leave_the_shared_doc_count_unchanged(): void
    {
        // 1 user ("John Doe") + 30 products hold "john".
        $this->assertSame(31, $this->docCount('john'));

        for ($run = 1; $run <= 3; $run++) {
            $this->artisan('fuzzy-search:rebuild', ['model' => Product::class, '--fresh' => true])->assertExitCode(0);
            $this->assertSame(31, $this->docCount('john'), "after rebuild --fresh #{$run}");
        }

        // The flushed model's share is given back even when the term survives the orphan sweep.
        app(IndexManager::class)->flush(Product::class);
        $this->assertSame(1, $this->docCount('john'));
    }

    public function test_deleting_one_document_gives_back_its_share_of_doc_count(): void
    {
        app(IndexManager::class)->removeFromIndex(Product::class, Product::where('title', 'John widget 1')->value('id'));

        $this->assertSame(30, $this->docCount('john'));
    }
}
