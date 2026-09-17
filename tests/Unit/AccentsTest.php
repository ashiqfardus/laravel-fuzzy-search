<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Support\Accents;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class AccentsTest extends TestCase
{
    public function test_every_character_of_the_legacy_map_folds_as_before(): void
    {
        foreach (Accents::MAP as $from => $to) {
            $this->assertSame($to, Accents::fold($from), $from);
        }
    }

    public function test_decomposed_and_precomposed_forms_fold_to_the_same_ascii(): void
    {
        $this->assertSame('cafe', Accents::fold('café'));
        $this->assertSame('cafe', Accents::fold("cafe\u{0301}"));
        $this->assertSame('Resume', Accents::fold('Résumé'));
        $this->assertSame('strasse', Accents::fold('straße'));
        $this->assertSame('o', Accents::fold('ø'));
        $this->assertSame('plain ascii 123', Accents::fold('plain ascii 123')); // fast path
    }

    public function test_intl_path_folds_characters_the_map_does_not_know(): void
    {
        if (!Accents::usesIntl()) {
            $this->markTestSkipped('ext-intl not loaded');
        }
        $this->assertSame('Zelezny', Accents::fold('Železný')); // ž, ý via decomposition
        $this->assertSame('東京', Accents::fold('東京'));         // untouched
        $this->assertSame('মোবাইল', Accents::fold('মোবাইল'));     // Bengali vowel signs are Mc/Mn — must survive: see Step 3
    }
}
