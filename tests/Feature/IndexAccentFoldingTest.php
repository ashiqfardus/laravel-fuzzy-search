<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

class IndexAccentFoldingTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('fuzzy-search.indexing.accent_insensitive', true);
    }

    public function test_accented_and_plain_forms_share_one_dictionary_term_when_enabled(): void
    {
        User::create(['name' => 'Café cafe', 'email' => 'c@example.com']);
        app(IndexManager::class)->indexBatch(User::all());

        $this->assertSame(1, DB::table('fuzzy_index_terms')->whereIn('term', ['cafe', 'café'])->count());
        $this->assertSame('cafe', DB::table('fuzzy_index_terms')->whereIn('term', ['cafe', 'café'])->value('term'));
    }

    public function test_an_accented_query_finds_the_folded_term_on_the_index_path(): void
    {
        $u = User::create(['name' => 'Résumé writer', 'email' => 'r@example.com']);
        app(IndexManager::class)->indexBatch(User::all());

        $this->assertSame([$u->id], User::search('résumé')->useInvertedIndex()->get()->pluck('id')->all());
        $this->assertSame([$u->id], User::search('resume')->useInvertedIndex()->get()->pluck('id')->all());
        $this->assertContains('resume', User::search('rés')->suggest(5));
    }

    public function test_stop_words_are_folded_too(): void
    {
        // "où" (2 chars, survives the tokenizer's length filter) is configured accented; the
        // text carries the plain form — only a folded stop list drops it.
        $manager = new IndexManager(new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer(), new \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer(), ['où']);

        $this->assertSame(['paris'], $manager->processTerms('ou paris'));
    }
}
