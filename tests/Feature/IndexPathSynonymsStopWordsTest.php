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

    public function test_a_synonym_is_found_when_the_query_word_ends_in_punctuation(): void
    {
        // The tokenizer splits on punctuation, so the synonym lookup must too.
        $titles = Product::search('laptop,')->useInvertedIndex()->typoTolerance(0)
            ->withSynonyms(['laptop' => ['notebook']])->get()->pluck('title')->all();

        $this->assertContains('Notebook Pro', $titles);
    }

    public function test_a_synonym_key_containing_punctuation_still_matches(): void
    {
        $product = Product::create(['title' => 'Router X', 'description' => 'wireless router', 'price' => 90]);
        app(IndexManager::class)->indexModel($product);

        // 'wi-fi' is one word to the caller but two tokens to the tokenizer: the lookup must try both.
        $titles = Product::search('wi-fi')->useInvertedIndex()->typoTolerance(0)
            ->withSynonyms(['wi-fi' => ['wireless']])->get()->pluck('title')->all();

        $this->assertContains('Router X', $titles);
    }

    public function test_builder_stop_words_extend_the_configured_list_on_the_index_path(): void
    {
        // Config list drops "the"; "pro" is a real term (Notebook Pro, MacBook Pro, iPhone 15 Pro …).
        $titles = Product::search('the pro')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all();
        $this->assertContains('Notebook Pro', $titles);
        $this->assertContains('MacBook Pro', $titles);

        // The builder list is ADDED to the configured one, so "the" and "pro" are both
        // dropped → nothing left to match.
        $this->assertCount(0, Product::search('the pro')->useInvertedIndex()->typoTolerance(0)->ignoreStopWords(['pro'])->get());
    }

    public function test_process_terms_keeps_its_one_argument_behaviour(): void
    {
        $manager = app(IndexManager::class);

        $this->assertSame(['pro'], $manager->processTerms('the pro'));
        $this->assertSame([], $manager->processTerms('the pro', ['pro']));
        $this->assertSame(['pro'], $manager->processTerms('the pro', []));
    }
}
