<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class IndexManagerTest extends TestCase
{
    public function test_fuzzy_index_terms_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_terms'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'term'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'doc_count'));
    }

    public function test_fuzzy_index_postings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_postings'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'term_id'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_type'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_id'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'frequency'));
    }

    public function test_fuzzy_index_meta_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_meta'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'model_type'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'total_docs'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'avg_doc_length'));
    }

    public function test_whitespace_tokenizer_splits_on_word_boundaries(): void
    {
        $tokenizer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer();
        $tokens = $tokenizer->tokenize('Hello World, PHP!');
        $this->assertEquals(['hello', 'world', 'php'], $tokens);
    }

    public function test_whitespace_tokenizer_ignores_words_under_2_chars(): void
    {
        $tokenizer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer();
        $tokens = $tokenizer->tokenize('a be cat dog');
        $this->assertContains('be', $tokens);
        $this->assertContains('cat', $tokens);
        $this->assertNotContains('a', $tokens);
    }

    public function test_whitespace_tokenizer_lowercases(): void
    {
        $tokenizer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer();
        $tokens = $tokenizer->tokenize('Laravel PHP');
        $this->assertEquals(['laravel', 'php'], $tokens);
    }

    public function test_null_stemmer_returns_word_unchanged(): void
    {
        $stemmer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer();
        $this->assertEquals('running', $stemmer->stem('running'));
        $this->assertEquals('jumps', $stemmer->stem('jumps'));
    }

    public function test_porter_stemmer_stems_english_words(): void
    {
        $stemmer = new \Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer();
        $this->assertEquals('run', $stemmer->stem('running'));
        $this->assertEquals('jump', $stemmer->stem('jumps'));
    }
}
