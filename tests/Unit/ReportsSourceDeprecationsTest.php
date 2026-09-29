<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * The source-deprecation forwarder (Tests\Concerns\ReportsSourceDeprecations) must already be the
 * error handler on top when the service provider boots, and must count config/ and database/ as
 * package code, so a deprecation raised in the provider's boot(), the shipped config or a migration
 * fails the run. Pushed after parent::setUp() and matching src/ only, it missed all three.
 */
class ReportsSourceDeprecationsTest extends TestCase
{
    private ?string $handlerAtBoot = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // booting() callbacks run just before the providers' boot().
        $app->booting(function () {
            $handler = set_error_handler(static fn () => false);
            restore_error_handler();

            $this->handlerAtBoot = $handler instanceof \Closure ? (string) (new \ReflectionFunction($handler))->getFileName() : null;
        });
    }

    public function test_the_forwarder_is_on_top_while_the_providers_boot(): void
    {
        $this->assertSame(realpath(__DIR__ . '/../Concerns/ReportsSourceDeprecations.php'), $this->handlerAtBoot);
    }

    public function test_src_config_and_database_are_package_code(): void
    {
        $root = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;

        $this->assertTrue(self::isPackageFile($root . 'src' . DIRECTORY_SEPARATOR . 'FuzzySearchServiceProvider.php'));
        $this->assertTrue(self::isPackageFile($root . 'config' . DIRECTORY_SEPARATOR . 'fuzzy-search.php'));
        $this->assertTrue(self::isPackageFile($root . 'database' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . 'create.php'));
        $this->assertFalse(self::isPackageFile($root . 'tests' . DIRECTORY_SEPARATOR . 'TestCase.php'));
        $this->assertFalse(self::isPackageFile($root . 'vendor' . DIRECTORY_SEPARATOR . 'laravel' . DIRECTORY_SEPARATOR . 'Str.php'));
        $this->assertFalse(self::isPackageFile($root . 'srcx' . DIRECTORY_SEPARATOR . 'File.php'));
    }
}
