<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

/** DictionaryIndexesTest on a connection with a table prefix: the migration finds the prefixed tables. */
class DictionaryIndexesPrefixedTest extends DictionaryIndexesTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $default = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$default}.prefix", 'dxp_');
    }
}
