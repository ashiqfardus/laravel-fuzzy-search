<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

require_once __DIR__ . '/../TestModels.php';

/**
 * PHP's levenshtein() is O(n·m) and similar_text() worse, so FuzzySearch::levenshteinDistance()
 * and similarityPercentage() — and the Fuzzy trait's filterFuzzy()/sortByFuzzy() through them —
 * compare at most the first 255 characters of each string. Shorter strings score exactly as before.
 * Each result below is only reachable if both strings were cut to 255 characters.
 */
class PhpScoringCapTest extends TestCase
{
    public function test_levenshtein_distance_compares_at_most_255_characters_of_each_string(): void
    {
        // Uncut, a 20KB value and a 2,000-character term are 20,000 edits apart.
        $this->assertSame(255, FuzzySearch::levenshteinDistance(str_repeat('a', 20000), str_repeat('b', 2000)));

        $value = str_repeat('é', 20000);
        $term  = str_repeat('é', 200) . str_repeat('x', 1800);
        $this->assertSame(110, FuzzySearch::levenshteinDistance($value, $term), 'cut by characters, not bytes: 55 é (110 bytes) against 55 x');
    }

    public function test_similarity_percentage_compares_at_most_255_characters_of_each_string(): void
    {
        // Uncut: the 300 a's are all common, 200·300 / (300 + 600) = 66.7%. Cut: 255 a's against 255 b's.
        $this->assertSame(0.0, FuzzySearch::similarityPercentage(str_repeat('a', 300), str_repeat('b', 300) . str_repeat('a', 300)));
        $this->assertSame(0.0, FuzzySearch::similarityPercentage(str_repeat('b', 20000), str_repeat('a', 2000)));
    }

    public function test_filter_and_sort_by_fuzzy_compare_the_capped_strings(): void
    {
        $long  = ['name' => str_repeat('a', 20000)];
        $other = ['name' => str_repeat('b', 300)];
        $term  = str_repeat('a', 2000);

        // Their first 255 characters are identical: distance 0. Uncut it is 18,000.
        $this->assertCount(1, User::filterFuzzy(collect([$long]), 'name', $term, 0));
        $this->assertSame([$long, $other], User::sortByFuzzy(collect([$other, $long]), 'name', $term)->values()->all());
    }

    public function test_strings_of_255_characters_or_fewer_score_exactly_as_before(): void
    {
        mt_srand(255);
        $alphabet = ['a', 'b', 'c', 'J', 'o', 'H', 'n', ' ', 'é', 'ß'];
        $random   = function (int $max) use ($alphabet): string {
            $out = '';
            for ($i = mt_rand(0, $max); $i > 0; $i--) {
                $out .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }

            return mb_substr($out, 0, 255);
        };

        for ($case = 0; $case < 300; $case++) {
            [$a, $b] = [$random(255), $random(255)];

            $this->assertSame(levenshtein(strtolower($a), strtolower($b)), FuzzySearch::levenshteinDistance($a, $b));
            similar_text(strtolower($a), strtolower($b), $percent);
            $this->assertSame($percent, FuzzySearch::similarityPercentage($a, $b));
        }

        $this->assertSame(3, FuzzySearch::levenshteinDistance('kitten', 'sitting'));
        $this->assertSame(2, FuzzySearch::levenshteinDistance('john', 'jon', ['cost_delete' => 2]));
    }
}
