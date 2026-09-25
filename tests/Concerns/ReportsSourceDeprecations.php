<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Concerns;

/**
 * While a test runs, Laravel's error handler sits above PHPUnit's and logs every deprecation, so
 * phpunit.xml's failOnDeprecation never saw one raised in src/. This handler, pushed after the
 * application boots, hands deprecations raised in src/ to PHPUnit and everything else (vendor
 * code, the tests themselves) to the handler it replaced. The application's teardown pops it.
 *
 * A test of a deprecated method captures the notice with deprecationsFrom() and asserts it.
 */
trait ReportsSourceDeprecations
{
    protected function reportSourceDeprecations(): void
    {
        $src      = realpath(__DIR__ . '/../../src') . DIRECTORY_SEPARATOR;
        $previous = null;

        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use ($src, &$previous) {
            if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0 && str_starts_with($file, $src)) {
                \PHPUnit\Runner\ErrorHandler::instance()($level, $message, $file, $line);

                return true;
            }

            return $previous === null ? false : $previous($level, $message, $file, $line);
        });
    }

    /** @return string[] the E_USER_DEPRECATED messages $callback raised */
    protected function deprecationsFrom(\Closure $callback): array
    {
        $deprecations = [];
        set_error_handler(function (int $errno, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_USER_DEPRECATED);

        try {
            $callback();
        } finally {
            restore_error_handler();
        }

        return $deprecations;
    }
}
