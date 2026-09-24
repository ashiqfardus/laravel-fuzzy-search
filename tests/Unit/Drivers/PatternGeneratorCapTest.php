<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Drivers\BaseDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\FuzzyDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\LevenshteinDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\SoundexDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\TrigramDriver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/** Counts every LIKE-escaped piece a driver builds: each pattern escapes one or two of them. */
trait CountsEscapedPieces
{
    public int $escaped = 0;

    protected function escapeLike(string $value): string
    {
        $this->escaped++;

        return parent::escapeLike($value);
    }
}

/**
 * max_patterns must bound the work, not only the result: the pattern-based drivers used to build
 * every pattern (O(n²) for levenshtein) and slice the list afterwards.
 */
class PatternGeneratorCapTest extends TestCase
{
    private const MAX = 5;

    /** Every pattern distinct (no two neighbouring characters alike), and every soundex substitution applies. */
    private function term(): string
    {
        return substr(str_repeat('phfockzswrknghab', 20), 0, 300);
    }

    /** @return array<string, BaseDriver> the pattern-based drivers, on SQLite so soundex takes its pattern path */
    private function drivers(): array
    {
        $config = [
            'max_patterns'   => self::MAX,
            'fuzzy'          => ['max_distance' => 2],
            'levenshtein'    => ['max_distance' => 3],
            'typo_tolerance' => ['max_distance' => 2, 'min_word_length' => 4],
        ];

        return [
            'fuzzy'       => new class($config, 'sqlite') extends FuzzyDriver { use CountsEscapedPieces; },
            'levenshtein' => new class($config, 'sqlite') extends LevenshteinDriver { use CountsEscapedPieces; },
            'soundex'     => new class($config, 'sqlite') extends SoundexDriver { use CountsEscapedPieces; },
            'trigram'     => new class($config, 'sqlite') extends TrigramDriver { use CountsEscapedPieces; },
        ];
    }

    public function test_no_driver_builds_patterns_past_max_patterns(): void
    {
        foreach ($this->drivers() as $name => $driver) {
            $driver->apply($this->app['db']->table('users'), 'name', $this->term());

            $this->assertLessThanOrEqual(2 * self::MAX, $driver->escaped, "{$name} built patterns past max_patterns");
        }
    }

    public function test_each_generator_is_pulled_only_until_max_patterns_are_kept(): void
    {
        foreach ($this->drivers() as $name => $driver) {
            $pulled     = 0;
            $candidates = (new \ReflectionMethod($driver, 'patternCandidates'))->invoke($driver, $this->term());
            $counted    = (function () use ($candidates, &$pulled) {
                foreach ($candidates as $pattern) {
                    $pulled++;
                    yield $pattern;
                }
            })();

            $kept = (new \ReflectionMethod($driver, 'firstPatterns'))->invoke($driver, $counted);

            $this->assertCount(self::MAX, $kept, $name);
            $this->assertSame(self::MAX, $pulled, "{$name} produced more patterns than it kept");
        }
    }
}
