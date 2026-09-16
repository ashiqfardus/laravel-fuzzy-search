<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Drivers\LevenshteinDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\SoundexDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\TrigramDriver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * Every LIKE-pattern driver used to slice the search term with strlen()/substr()/$value[$i].
 * On a multibyte term (Bengali is three bytes per character) that cut UTF-8 sequences in
 * half and produced patterns PostgreSQL rejects outright and other databases never match.
 */
class MultibytePatternsTest extends TestCase
{
    private const TERM = 'মোবাইল'; // 6 characters, 18 bytes

    private function bindingsFor(string $driverClass, array $overrides = []): array
    {
        $config = array_merge(config('fuzzy-search'), ['use_native_functions' => false], $overrides);
        $query  = (new $driverClass($config, 'sqlite'))->apply($this->app['db']->table('users'), 'name', self::TERM);

        return $query->getBindings();
    }

    private function assertAllValidUtf8(array $bindings): void
    {
        $this->assertNotEmpty($bindings);
        foreach ($bindings as $pattern) {
            $this->assertTrue(mb_check_encoding($pattern, 'UTF-8'), 'Invalid UTF-8 pattern: ' . bin2hex($pattern));
        }
    }

    public function test_levenshtein_patterns_are_character_safe(): void
    {
        $bindings = $this->bindingsFor(LevenshteinDriver::class, ['levenshtein' => ['max_distance' => 1]]);

        $this->assertAllValidUtf8($bindings);
        // exact + 6 deletions + 7 insertions + 6 substitutions
        $this->assertCount(20, $bindings);
        $this->assertSame('%মোবাইল%', $bindings[0]);
        $this->assertContains('%োবাইল%', $bindings);   // first character deleted
        $this->assertContains('%_মোবাইল%', $bindings); // insertion before the first character
        $this->assertContains('%মোবাই_%', $bindings);  // last character substituted
    }

    public function test_levenshtein_distance_two_and_three_patterns_are_character_safe(): void
    {
        $bindings = $this->bindingsFor(LevenshteinDriver::class, ['levenshtein' => ['max_distance' => 3]]);

        $this->assertAllValidUtf8($bindings);
        $this->assertContains('%বাইল%', $bindings);   // first two characters deleted
        $this->assertContains('%োমবাইল%', $bindings); // first two characters transposed
        $this->assertContains('মো%', $bindings);      // two-character prefix
        $this->assertContains('%ইল', $bindings);      // two-character suffix
        $this->assertContains('ম%ল', $bindings);      // first and last character
    }

    public function test_trigram_fallback_builds_trigrams_from_characters(): void
    {
        $bindings = $this->bindingsFor(TrigramDriver::class);

        $this->assertAllValidUtf8($bindings);
        $this->assertSame('%মোবাইল%', $bindings[0]);
        $this->assertContains('%মোব%', $bindings);
        $this->assertContains('%বাই%', $bindings);
        $this->assertContains('%াইল%', $bindings);
    }

    public function test_soundex_fallback_prefix_uses_the_first_three_characters(): void
    {
        $bindings = $this->bindingsFor(SoundexDriver::class);

        $this->assertAllValidUtf8($bindings);
        $this->assertSame('%মোবাইল%', $bindings[0]);
        $this->assertContains('মোব%', $bindings);
    }
}
