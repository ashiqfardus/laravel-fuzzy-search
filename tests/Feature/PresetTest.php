<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidConfigException;
use Ashiqfardus\LaravelFuzzySearch\Support\StopWords;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * preset() had no test at all (M11), and tests/TestCase.php replaced the whole fuzzy-search
 * config without a `presets` key — so any test that had existed would have thrown
 * InvalidConfigException instead of exercising the five shipped presets. The test config now
 * carries the shipped presets (loaded from config/fuzzy-search.php, never copied), and these
 * tests hold each preset to what its own config declares.
 */
class PresetTest extends TestCase
{
    /** The shipped presets, read from the published config file rather than from a copy. */
    private static function shipped(): array
    {
        return (require __DIR__ . '/../../config/fuzzy-search.php')['presets'];
    }

    public static function presetProvider(): array
    {
        return array_map(fn (string $name) => [$name], array_combine(
            array_keys(self::shipped()),
            array_keys(self::shipped())
        ));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('presetProvider')]
    public function test_a_shipped_preset_applies_the_state_its_config_declares(string $name): void
    {
        $preset = self::shipped()[$name];
        $debug  = User::search('john')->preset($name)->getDebugInfo();

        $this->assertSame($preset['algorithm'], $debug['algorithm'], "[{$name}] algorithm");
        $this->assertSame($preset['typo_tolerance'], $debug['typo_tolerance'], "[{$name}] typo tolerance");

        // searchIn() appends, so the preset's columns join the model's own with their weights.
        foreach ($preset['columns'] ?? [] as $column => $weight) {
            $this->assertContains($column, $debug['searchable_columns'], "[{$name}] column {$column}");
            $this->assertSame($weight, $debug['column_weights'][$column], "[{$name}] weight for {$column}");
        }

        $this->assertSame(
            (bool) ($preset['accent_insensitive'] ?? config('fuzzy-search.unicode.accent_insensitive', false)),
            $debug['accent_insensitive'],
            "[{$name}] accent insensitivity"
        );

        $this->assertSame((bool) ($preset['partial_match'] ?? false), $debug['partial_match'], "[{$name}] partial match");

        $expectedStopWords = ($preset['stop_words_enabled'] ?? false)
            ? StopWords::forLocale($preset['locale'] ?? config('fuzzy-search.locale', 'en'))
            : [];

        $this->assertSame($expectedStopWords, $debug['stop_words'], "[{$name}] stop words");
    }

    public function test_a_preset_search_runs(): void
    {
        // phonetic: soundex on `name`, the one shipped preset whose columns all exist here.
        $results = User::search('john')->preset('phonetic')->get();

        $this->assertNotEmpty($results);
    }

    public function test_an_unknown_preset_throws(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("Preset 'nope' not found");

        User::search('john')->preset('nope');
    }

    public function test_the_exception_lists_the_available_presets(): void
    {
        try {
            User::search('john')->preset('nope');
            $this->fail('preset() accepted an unknown name');
        } catch (InvalidConfigException $e) {
            foreach (array_keys(self::shipped()) as $name) {
                $this->assertStringContainsString($name, $e->getMessage());
            }
        }
    }
}
