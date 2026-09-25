<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

/**
 * DatabaseTestCase runs on config/fuzzy-search.php as it ships, as TestCase does (see
 * Tests\Unit\ShippedConfigTest). It once merged its own values over the shipped file, outside
 * that guard, so a changed default would never have reached the driver tests.
 */
class ShippedConfigTest extends DatabaseTestCase
{
    public function test_the_driver_tests_run_on_the_shipped_config(): void
    {
        $this->assertSame(require __DIR__ . '/../../config/fuzzy-search.php', config('fuzzy-search'));
    }
}
