<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class IndexPathSynonymsStopWordsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Product::create(['title' => 'Notebook Pro', 'description' => 'thin and light', 'price' => 1200]);
        app(IndexManager::class)->indexBatch(Product::all());
    }

    public function test_synonyms_expand_query_terms_at_full_weight(): void
    {
        $titles = Product::search('laptop')->useInvertedIndex()->typoTolerance(0)
            ->withSynonyms(['laptop' => ['notebook']])->get()->pluck('title')->all();

        $this->assertContains('Notebook Pro', $titles);
        $this->assertContains('MacBook Pro', $titles); // seeded product whose description says "laptop"

        $builder = Product::search('laptop')->useInvertedIndex()->typoTolerance(0)->withSynonyms(['laptop' => ['notebook']]);
        $builder->get();
        $this->assertSame(1.0, $builder->getDebugInfo()['index_terms']['notebook']);
    }

    public function test_synonym_groups_work_on_the_index_path_too(): void
    {
        $titles = Product::search('laptop')->useInvertedIndex()->typoTolerance(0)
            ->synonymGroup(['laptop', 'notebook'])->get()->pluck('title')->all();

        $this->assertContains('Notebook Pro', $titles);
    }

    public function test_builder_stop_words_replace_the_configured_list_at_query_time(): void
    {
        // Config list drops "the"; "pro" is a real term (Notebook Pro, MacBook Pro, iPhone 15 Pro …).
        $titles = Product::search('the pro')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all();
        $this->assertContains('Notebook Pro', $titles);
        $this->assertContains('MacBook Pro', $titles);

        // Override: only "pro" is a stop word now → nothing left to match ("the" was never indexed).
        $this->assertCount(0, Product::search('the pro')->useInvertedIndex()->typoTolerance(0)->ignoreStopWords(['pro'])->get());
    }

    public function test_process_terms_keeps_its_one_argument_behaviour(): void
    {
        $manager = app(IndexManager::class);

        $this->assertSame(['pro'], $manager->processTerms('the pro'));
        $this->assertSame(['the'], $manager->processTerms('the pro', ['pro']));
        $this->assertSame(['the', 'pro'], $manager->processTerms('the pro', []));
    }
}
