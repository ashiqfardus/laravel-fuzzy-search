<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Drivers;

use PHPUnit\Framework\TestCase;

/**
 * The fact SimilarTextDriver's min_percentage bound rests on (ruling ER-49): when the term is
 * contained in the value, similar_text(term, value) is exactly 200·t / (t + v). PHP counts bytes,
 * so t and v are character lengths for single-byte text and byte lengths in general; the driver
 * bounds characters, the same percentage on ASCII and its character form elsewhere.
 */
class SimilarTextClosedFormTest extends TestCase
{
    private function random(array $alphabet, int $min, int $max): string
    {
        $out = '';
        for ($i = mt_rand($min, $max); $i > 0; $i--) {
            $out .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        return $out;
    }

    public function test_a_contained_term_scores_200_t_over_t_plus_v(): void
    {
        mt_srand(49);
        $ascii     = str_split('abcxyzABC 0123-_%');
        $multibyte = ['a', 'b', ' ', 'é', 'ü', 'ß', 'д', '東', '京', '😀'];

        for ($case = 0; $case < 5000; $case++) {
            foreach (['ascii' => $ascii, 'multibyte' => $multibyte] as $name => $alphabet) {
                $term  = mb_strtolower($this->random($alphabet, 1, 10));
                $value = mb_strtolower($this->random($alphabet, 0, 15) . $term . $this->random($alphabet, 0, 15));

                similar_text($term, $value, $percent);

                $this->assertEqualsWithDelta(200 * strlen($term) / (strlen($term) + strlen($value)), $percent, 1e-9, "{$name}: {$term} in {$value}");
                if ($name === 'ascii') {
                    $this->assertEqualsWithDelta(200 * mb_strlen($term) / (mb_strlen($term) + mb_strlen($value)), $percent, 1e-9, "{$term} in {$value}");
                }
            }
        }
    }
}
