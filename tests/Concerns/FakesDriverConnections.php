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
     * Whether a fake connection for $driver can be built on this Laravel version.
     * False only for 'mariadb' on Laravel 10, which has no MariaDbConnection class.
     *
     * Callers that loop over several drivers in one test MUST check this before calling
     * fakeConnectionTable() and `continue` past the unavailable one, rather than calling
     * fakeConnectionTable() unconditionally inside the loop: markTestSkipped() throws and
     * unwinds the *entire* test method immediately, so triggering it mid-loop silently
     * drops every assertion for the drivers that were still to come, and PHPUnit reports
     * the whole method as "skipped" rather than partially passed.
     */
    protected function fakeDriverAvailable(string $driver): bool
    {
        return $driver !== 'mariadb' || class_exists(\Illuminate\Database\MariaDbConnection::class);
    }

    /**
     * Return a builder on a lazily-connected connection whose driver name is $driver.
     * Laravel creates the PDO only when a query executes, so toSql() and getDriverName()
     * work without a real server. Skips (aborting the whole test method — see
     * fakeDriverAvailable() above) when Laravel lacks the driver (mariadb on Laravel 10);
     * fine for a test that only asks for one driver, wrong for a per-driver loop.
     * A $prefix is the connection's table prefix; that connection gets its own name.
     */
    protected function fakeConnectionTable(string $driver, string $table, string $prefix = ''): \Illuminate\Database\Query\Builder
    {
        if (!$this->fakeDriverAvailable($driver)) {
            $this->markTestSkipped('Laravel < 11 has no mariadb driver.');
        }

        $name = 'fake_' . $driver . ($prefix === '' ? '' : '_' . $prefix);
        config(["database.connections.{$name}" => [
            'driver'   => $driver,
            'host'     => '127.0.0.1',
            'port'     => 1,
            'database' => $driver === 'sqlite' ? ':memory:' : 'fake',
            'username' => 'fake',
            'password' => 'fake',
            'prefix'   => $prefix,
        ]]);

        return $this->app['db']->connection($name)->table($table);
    }
}
