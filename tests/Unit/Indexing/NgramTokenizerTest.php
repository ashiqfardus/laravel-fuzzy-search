<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Indexing;

use Ashiqfardus\LaravelFuzzySearch\Indexing\NgramTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class NgramTokenizerTest extends TestCase
{
    public function test_bigrams_of_a_japanese_run(): void
    {
        $this->assertSame(['東京', '京タ', 'タワ', 'ワー'], (new NgramTokenizer())->tokenize('東京タワー'));
    }

    public function test_bigrams_of_a_chinese_run_and_trigrams(): void
    {
        $this->assertSame(['北京', '京大', '大学'], (new NgramTokenizer(2))->tokenize('北京大学'));
        $this->assertSame(['北京大', '京大学'], (new NgramTokenizer(3))->tokenize('北京大学'));
    }

    public function test_a_run_shorter_than_n_is_emitted_whole(): void
    {
        $this->assertSame(['山'], (new NgramTokenizer())->tokenize('山'));
        $this->assertSame(['ab'], (new NgramTokenizer(3))->tokenize('ab'));
    }

    public function test_punctuation_and_whitespace_split_runs_and_case_is_folded(): void
    {
        $this->assertSame(['東京', '大阪'], (new NgramTokenizer())->tokenize('東京、大阪'));
        $this->assertSame(['to', 'ok', 'ky', 'yo'], (new NgramTokenizer())->tokenize('TOKYO'));
    }

    public function test_duplicates_are_kept_for_frequency_counting(): void
    {
        $this->assertSame(['ああ', 'ああ'], (new NgramTokenizer())->tokenize('あああ'));
    }

    public function test_n_below_one_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NgramTokenizer(0);
    }
}
