<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Concerns;

/**
 * While a test runs, Laravel's error handler sits above PHPUnit's and logs every deprecation, so
 * phpunit.xml's failOnDeprecation never saw one raised in the package. This handler hands
 * deprecations raised in src/, config/ or database/ (the migrations) to PHPUnit and everything else
 * (vendor code, the tests themselves) to the handler it replaced. The teardown pops it, with every
 * other handler, except at the lowest-deps floor (Testbench 8.0, Laravel 10.0), which leaves it as
 * it leaves Laravel's own handler: the stack grows by two a test there, and only the newest pair is
 * ever called.
 *
 * The test bases push it first thing in defineEnvironment(), which Testbench runs after Laravel's
 * handler is installed and before the providers boot, so the service provider's boot(), the shipped
 * config the bases load, the migrations and the test are all covered. Two things come earlier and
 * are not: the provider's register(), and a compile-time deprecation in the provider file itself
 * (an implicitly nullable parameter, say), which is compiled once per process while the providers
 * register. Laravel drops every deprecation raised before the application is bootstrapped, which is
 * after the providers boot, so the deprecations log channel misses both of those and boot() too.
 *
 * A test of a deprecated method captures the notice with deprecationsFrom() and asserts it.
 */
trait ReportsSourceDeprecations
{
    protected function reportSourceDeprecations(): void
    {
        $previous = null;

        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous) {
            if (($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0 && self::isPackageFile($file)) {
                \PHPUnit\Runner\ErrorHandler::instance()($level, $message, $file, $line);

                return true;
            }

            return $previous === null ? false : $previous($level, $message, $file, $line);
        });
    }

    /** True for a file the package ships and an app runs: src/, config/ and database/ (the migrations). */
    protected static function isPackageFile(string $file): bool
    {
        $root = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR;

        foreach (['src', 'config', 'database'] as $dir) {
            if (str_starts_with($file, $root . $dir . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
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
