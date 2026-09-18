<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Support;

use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;
use PHPUnit\Framework\TestCase;

class Utf8Test extends TestCase
{
    public function test_valid_text_is_returned_byte_identical(): void
    {
        foreach (['', 'john doe', "a\x00b", 'Größe', 'মোবাইলফোন', '東京', "\u{212A}", "\u{1F600} \u{10FFFF}", "cafe\u{0301}", 'a?b%c_'] as $valid) {
            $this->assertSame($valid, Utf8::clean($valid), bin2hex($valid));
        }
    }

    public function test_invalid_sequences_are_dropped_and_the_valid_bytes_around_them_kept(): void
    {
        $cases = [
            "john\xC3"             => 'john',     // truncated two-byte sequence at the end
            "jo\xC3hn"             => 'john',     // truncated sequence in the middle
            "jo\xE2\x82hn"         => 'john',     // truncated three-byte sequence
            "\xF0\x9F\x98john"     => 'john',     // truncated four-byte sequence
            "jo\x80hn"             => 'john',     // lone continuation byte
            "a\xC0\xAFb"           => 'ab',       // overlong encoding of '/'
            "a\xE0\x80\xAFb"       => 'ab',       // three-byte overlong
            "a\xED\xA0\x80b"       => 'ab',       // UTF-16 surrogate U+D800 encoded in UTF-8
            "a\xF4\x90\x80\x80b"   => 'ab',       // above U+10FFFF
            "doe\xFF"              => 'doe',
            "\xFE\xFF"             => '',
            "Grö\xC3ße\xC3"        => 'Größe',    // valid multibyte kept next to invalid bytes
            "মোবা\xE0\xA6ইল"       => 'মোবাইল',   // a Bengali character cut short
        ];

        foreach ($cases as $dirty => $clean) {
            $this->assertSame($clean, Utf8::clean($dirty), bin2hex($dirty));
            $this->assertTrue(mb_check_encoding(Utf8::clean($dirty), 'UTF-8'), bin2hex($dirty));
        }
    }
}
