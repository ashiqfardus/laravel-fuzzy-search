<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Indexing;

use Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class ScriptAwareTokenizerTest extends TestCase
{
    public function test_latin_runs_tokenize_exactly_like_the_whitespace_tokenizer(): void
    {
        foreach (['Hello, World! café', "CAFE\u{0301}", 'মোবাইল ফোন', 'हिन्दी भाषा', 'ภาษาไทย', 'a b cd', "caf\xE9 latin1"] as $text) {
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

    public function test_prolonged_sound_marks_and_kana_combining_marks_stay_inside_the_cjk_run(): void
    {
        // ー (U+30FC) and ｰ (U+FF70) are Script=Common, ゙ (U+3099) is Inherited: on PCRE2 < 10.40
        // \p{Katakana} does not match them and the last bigram of every ー-ending word vanished.
        $this->assertSame(['ソニ', 'ニー'], (new ScriptAwareTokenizer())->tokenize('ソニー'));
        $this->assertSame(['らー', 'ーめ', 'めん'], (new ScriptAwareTokenizer())->tokenize('らーめん'));
        $this->assertSame(['ｿﾆ', 'ﾆｰ'], (new ScriptAwareTokenizer())->tokenize('ｿﾆｰ'));
        $this->assertSame(['か\u{3099}き'], (new ScriptAwareTokenizer())->tokenize("か\u{3099}き")); // decomposed が stays one run (≤ n chars after the mark)
        $this->assertSame(['東京', '京タ', 'タワ', 'ワー'], (new ScriptAwareTokenizer())->tokenize('東京タワー'));
    }

    public function test_invalid_utf8_is_scrubbed_instead_of_emptying_the_result(): void
    {
        $this->assertSame(['东京'], (new ScriptAwareTokenizer())->tokenize("\xE9 东京"));
    }
}
