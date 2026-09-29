<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

/**
 * Tests\Unit\ReportsSourceDeprecationsTest for this base: the source-deprecation forwarder is on top
 * when the service provider boots, so a deprecation raised in its boot() fails the run.
 */
class ReportsSourceDeprecationsTest extends DatabaseTestCase
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
}
