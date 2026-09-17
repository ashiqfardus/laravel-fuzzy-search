<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class WeightedBm25Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Product weights: title 10, description 5. One hit each, so only the weight decides.
        Product::create(['title' => 'Quantum widget', 'description' => 'A small device.', 'price' => 10]);
        Product::create(['title' => 'Plain device', 'description' => 'A quantum gadget.', 'price' => 10]);
        app(IndexManager::class)->indexBatch(Product::all());
    }

    private function titles(?callable $tweak = null): array
    {
        $builder = Product::search('quantum')->useInvertedIndex()->typoTolerance(0);
        if ($tweak) {
            $tweak($builder);
        }
        return $builder->get()->pluck('title')->all();
    }

    public function test_the_heavier_column_wins_with_the_model_weights(): void
    {
        $this->assertSame(['Quantum widget', 'Plain device'], $this->titles());
    }

    public function test_search_in_can_flip_the_weights_at_query_time(): void
    {
        $this->assertSame(['Plain device', 'Quantum widget'], $this->titles(fn ($b) => $b->searchIn(['description' => 10, 'title' => 1])));
    }

    public function test_a_zero_weight_removes_the_column_from_ranking(): void
    {
        $this->assertSame(['Quantum widget'], $this->titles(fn ($b) => $b->searchIn(['description' => 0])));
    }

    public function test_scores_are_exposed_and_count_and_paginate_agree(): void
    {
        $results = Product::search('quantum')->useInvertedIndex()->typoTolerance(0)->get();

        $this->assertGreaterThan($results[1]->_score, $results[0]->_score);
        $this->assertSame(2, Product::search('quantum')->useInvertedIndex()->typoTolerance(0)->count());
        $this->assertSame(2, Product::search('quantum')->useInvertedIndex()->typoTolerance(0)->paginate(1)->total());
    }

    public function test_a_zero_weight_column_is_excluded_from_count_and_paginate_too(): void
    {
        $builder = fn () => Product::search('quantum')->useInvertedIndex()->typoTolerance(0)->searchIn(['description' => 0]);

        $this->assertCount(1, $builder()->get());
        $this->assertSame(1, $builder()->count());
        $this->assertSame(1, $builder()->paginate(1)->total());
    }

    public function test_a_mixed_corpus_ranks_the_rebuilt_document_first(): void
    {
        // Legacy row: a title hit stored without a column (weight 1) versus a rebuilt description hit (weight 5).
        $legacy = Product::create(['title' => 'Legacy quantum', 'description' => 'nothing here', 'price' => 10]);
        app(IndexManager::class)->indexModel($legacy);
        DB::table('fuzzy_index_postings')->where('model_type', Product::class)->where('model_id', (string) $legacy->getKey())->update(['column_name' => '']);

        $titles = Product::search('quantum')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all();

        $this->assertSame('Quantum widget', $titles[0]);            // title weight 10, rebuilt
        $this->assertContains('Legacy quantum', $titles);           // still found, at weight 1
        $this->assertGreaterThan(array_search('Plain device', $titles), array_search('Legacy quantum', $titles)); // description (5) beats legacy (1)
    }
}
