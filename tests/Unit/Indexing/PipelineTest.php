<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Indexing;

require_once __DIR__ . '/../../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Pipeline;
use Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class PipelineTest extends TestCase
{
    public function test_tokens_runs_tokenize_stop_words_and_stem_in_order(): void
    {
        $pipeline = new Pipeline(new WhitespaceTokenizer(), new PorterStemmer('English'), ['the']);

        $this->assertSame(['run', 'run', 'fast'], $pipeline->tokens('The running RUNS fast'));
    }

    public function test_extra_stop_words_extend_the_configured_ones(): void
    {
        $pipeline = new Pipeline(new WhitespaceTokenizer(), new NullStemmer(), ['the']);

        $this->assertSame(['pro'], $pipeline->tokens('the pro'));
        $this->assertSame([], $pipeline->tokens('the pro', ['PRO']));
    }

    public function test_index_manager_default_pipeline_matches_its_constructor(): void
    {
        $manager  = new IndexManager(new WhitespaceTokenizer(), new NullStemmer(), ['the']);
        $pipeline = $manager->pipelineFor(User::class);

        $this->assertInstanceOf(WhitespaceTokenizer::class, $pipeline->tokenizer());
        $this->assertInstanceOf(NullStemmer::class, $pipeline->stemmer());
        $this->assertSame(['the'], $pipeline->stopWords());
        $this->assertFalse($pipeline->foldsAccents());
    }

    public function test_process_terms_keeps_its_two_argument_behaviour_and_accepts_a_model_class(): void
    {
        $manager = new IndexManager(new WhitespaceTokenizer(), new NullStemmer(), ['the']);

        $this->assertSame(['pro'], $manager->processTerms('the pro'));
        $this->assertSame([], $manager->processTerms('the pro', ['pro']));
        $this->assertSame(['pro'], $manager->processTerms('the pro', null, User::class));
    }
}
