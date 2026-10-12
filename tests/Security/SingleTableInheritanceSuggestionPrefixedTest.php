<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

/** SingleTableInheritanceSuggestionTest on a connection with a table prefix: the column dictionary the visibility filter reads is the prefixed one. */
class SingleTableInheritanceSuggestionPrefixedTest extends SingleTableInheritanceSuggestionTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $default = $app['config']->get('database.default');
        $app['config']->set("database.connections.{$default}.prefix", 'stp_');
    }
}
