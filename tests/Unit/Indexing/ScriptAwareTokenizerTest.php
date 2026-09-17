<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Indexing;

use Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class ScriptAwareTokenizerTest extends TestCase
{
    public function test_latin_runs_tokenize_exactly_like_the_whitespace_tokenizer(): void
    {
        foreach (['Hello, World! café', "CAFE\u{0301}", 'মোবাইল ফোন', 'हिन्दी भाषा', 'ภาษาไทย', 'a b cd'] as $text) {
            $this->assertSame((new WhitespaceTokenizer())->tokenize($text), (new ScriptAwareTokenizer())->tokenize($text), $text);
        }
    }

    public function test_cjk_runs_become_ngrams_and_order_is_preserved(): void
    {
        $this->assertSame(['tokyo', '東京', 'tower'], (new ScriptAwareTokenizer())->tokenize('Tokyo 東京 tower'));
        $this->assertSame(['東京', '京都', 'hotel'], (new ScriptAwareTokenizer())->tokenize('東京都hotel'));
    }

    public function test_hiragana_katakana_and_hangul_count_as_cjk(): void
    {
        $this->assertSame(['ひら', 'らが', 'がな'], (new ScriptAwareTokenizer())->tokenize('ひらがな'));
        $this->assertSame(['カタ', 'タカ', 'カナ'], (new ScriptAwareTokenizer())->tokenize('カタカナ'));
        $this->assertSame(['한국', '국어'], (new ScriptAwareTokenizer())->tokenize('한국어'));
    }

    public function test_a_single_cjk_character_is_a_token(): void
    {
        $this->assertSame(['山', 'view'], (new ScriptAwareTokenizer())->tokenize('山 view'));
    }
}
