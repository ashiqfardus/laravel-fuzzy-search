<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/** validate() takes the names searchIn() takes: identifiers, joined by single dots. */
class SearchableColumnsValidateTest extends TestCase
{
    public function test_a_dot_with_no_identifier_beside_it_is_rejected(): void
    {
        foreach (['name.', 'a..b', 'users.name.', 'a.1b'] as $name) {
            try {
                SearchableColumns::validate([$name]);
                $this->fail("{$name} should be rejected");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_facet_rejects_a_trailing_dot_when_it_is_named_not_at_getfacets(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        User::search('john')->facet('name.');
    }

    public function test_plain_and_dotted_names_pass(): void
    {
        $this->assertSame(['name', '_x1', 'users.name', 'author.profile.bio'], SearchableColumns::validate(['name', '_x1', 'users.name', 'author.profile.bio']));
    }
}
