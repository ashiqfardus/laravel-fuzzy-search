<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * The suite runs on config/fuzzy-search.php as it ships. TestCase once set a hand-kept copy
 * that differed on default_algorithm, levenshtein.max_distance, indexing.async and
 * unicode.accent_insensitive, so bugs that show only at the shipped values never failed.
 */
class ShippedConfigTest extends TestCase
{
    /**
     * Keys TestCase runs at a value other than the shipped one, each with its reason.
     * Add an entry here only together with the override in TestCase::defineEnvironment().
     */
    private const OVERRIDES = [];

    public function test_the_suite_runs_on_the_shipped_config_except_the_listed_overrides(): void
    {
        $shipped = require __DIR__ . '/../../config/fuzzy-search.php';

        $this->assertSame(array_replace_recursive($shipped, self::OVERRIDES), config('fuzzy-search'));
    }

    public function test_the_shipped_indexing_async_indexes_a_save_before_it_returns(): void
    {
        // Why indexing.async needs no override: TestCase pins the sync queue connection, so the
        // job a save dispatches has indexed the row by the next line, as with async off.
        config(['fuzzy-search.indexing.enabled' => true]);
        $this->assertTrue(config('fuzzy-search.indexing.async'));

        User::create(['name' => 'Zebediah Quill', 'email' => 'zebediah@example.com']);

        $this->assertSame(['Zebediah Quill'], User::search('zebediah')->useInvertedIndex()->get()->pluck('name')->all());
    }
}
