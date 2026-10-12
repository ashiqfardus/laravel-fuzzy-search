<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

/** The compact postings migration's cases on a connection with a table prefix. */
class CompactPostingsPrefixedMigrationTest extends CompactPostingsMigrationTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $default = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$default}.prefix", 'pfx_');
    }
}
