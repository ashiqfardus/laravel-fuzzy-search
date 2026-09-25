<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Integration\ScoutRecipe\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\SearchableScope;

/**
 * The docs/integrations.md "Scout Driver → Usage" recipe, as users copy it (ScoutRecipe\User):
 * no $searchable property, so every read of it must not fall through Eloquent's __get() to
 * Scout's searchable() method, which Eloquent takes for a relation and throws on.
 */
class ScoutDualTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'scout.driver'                   => 'fuzzy-search',
            'scout.queue'                    => false,
            'fuzzy-search.indexing.enabled'  => true,
            'fuzzy-search.indexing.async'    => false,
        ]);
    }

    public function test_the_fixture_is_the_documented_recipe_verbatim(): void
    {
        $docs = file_get_contents(__DIR__ . '/../../docs/integrations.md');
        preg_match('/```php\n(use Laravel\\\\Scout\\\\Searchable;\n.*?\n)```/s', $docs, $recipe);
        $this->assertNotEmpty($recipe, 'the Scout recipe is no longer in docs/integrations.md');

        $modelEnd = strpos($recipe[1], "\n}\n") + 3; // the class, without the usage lines after it
        $this->assertStringContainsString(substr($recipe[1], 0, $modelEnd), file_get_contents(__DIR__ . '/ScoutRecipe/User.php'));
    }

    public function test_the_recipe_boots_with_both_traits_wired(): void
    {
        $this->assertInstanceOf(SearchBuilder::class, User::search('john'));
        $this->assertInstanceOf(\Laravel\Scout\Builder::class, User::scoutSearch('john'));
        $this->assertTrue(User::hasGlobalScope(SearchableScope::class), 'Scout\'s bootSearchable() must have run from booted()');
    }

    public function test_create_indexes_through_the_package_observer_and_scout(): void
    {
        $user = User::unguarded(fn () => User::create(['name' => 'Dual Trait', 'email' => 'dual@example.com']));

        $this->assertTrue($user->exists);
        $this->assertSame(1, DB::table('fuzzy_index_documents')->where('model_type', User::class)->where('model_id', (string) $user->getKey())->count());
    }

    public function test_the_package_search_runs_on_the_auto_detected_columns(): void
    {
        $this->assertSame(['name', 'email'], (new User)->getSearchableColumns());

        $names = User::search('john')->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertNotContains('Alice Smith', $names);
    }

    public function test_scouts_own_search_path_runs_on_the_fuzzy_search_engine(): void
    {
        User::unguarded(fn () => User::create(['name' => 'Scouted Person', 'email' => 'scouted@example.com']));

        $names = User::scoutSearch('scouted')->get()->pluck('name')->all();

        $this->assertSame(['Scouted Person'], $names);
    }
}
