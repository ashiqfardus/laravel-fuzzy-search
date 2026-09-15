<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Concerns;

/**
 * Build a query on a fake, never-connected database connection for a given driver name.
 *
 * Several call sites (SearchBuilder::applyRelevanceOrdering(), FuzzySearch::applyFuzzyOrder())
 * read the driver name off the query's own connection rather than accepting it as a
 * parameter, so pinning their per-driver SQL needs a real connection object — not just a
 * string. Laravel only opens the PDO connection when a query actually executes; toSql()
 * and getDriverName() never touch the socket, so this works with no server running.
 */
trait FakesDriverConnections
{
    /**
     * Return a builder on a lazily-connected connection whose driver name is $driver.
     * Laravel creates the PDO only when a query executes, so toSql() and getDriverName()
     * work without a real server. Skips when Laravel lacks the driver (mariadb on Laravel 10).
     */
    protected function fakeConnectionTable(string $driver, string $table): \Illuminate\Database\Query\Builder
    {
        if ($driver === 'mariadb' && !class_exists(\Illuminate\Database\MariaDbConnection::class)) {
            $this->markTestSkipped('Laravel < 11 has no mariadb driver.');
        }

        $name = 'fake_' . $driver;
        config(["database.connections.{$name}" => [
            'driver'   => $driver,
            'host'     => '127.0.0.1',
            'port'     => 1,
            'database' => $driver === 'sqlite' ? ':memory:' : 'fake',
            'username' => 'fake',
            'password' => 'fake',
            'prefix'   => '',
        ]]);

        return $this->app['db']->connection($name)->table($table);
    }
}
