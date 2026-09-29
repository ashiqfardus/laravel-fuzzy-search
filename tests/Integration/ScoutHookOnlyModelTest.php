<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** A model with Scout's trait and a searchableText() hook, but not the package's trait. */
class ScoutHookOnlyUser extends Model
{
    use \Laravel\Scout\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    public function searchableText(): array
    {
        return ['name' => $this->name, 'role' => 'mountaineer'];
    }
}

/**
 * L4 (round 9), ruling D11. A searchableText() hook is enough to be indexed (the engine accepts the
 * model, IndexManager::indexesModel() says yes), but the index write then called
 * getSearchableColumns(), which only the package's trait defines: every save, searchable() and
 * import threw BadMethodCallException. Such a model is indexed through its hook.
 */
class ScoutHookOnlyModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);
    }

    public function test_a_model_with_only_the_hook_is_indexed_and_searched(): void
    {
        ScoutHookOnlyUser::all()->searchable();
        $created = ScoutHookOnlyUser::create(['name' => 'Tenzing Norgay', 'email' => 'tenzing@example.com']); // the save path

        $this->assertSame(['John Doe'], ScoutHookOnlyUser::search('doe')->where('email', 'john@example.com')->get()->pluck('name')->all());
        $this->assertSame([$created->getKey()], ScoutHookOnlyUser::search('tenzing')->keys()->all());
        $this->assertSame(8, ScoutHookOnlyUser::search('mountaineer')->paginate(3)->total(), 'a word only the hook returns');
    }

    public function test_delete_and_flush_remove_its_entries(): void
    {
        ScoutHookOnlyUser::all()->searchable();
        $john = ScoutHookOnlyUser::where('name', 'John Doe')->first();

        $john->delete(); // Scout's observer removes it from the index

        $this->assertSame(['Jane Doe'], ScoutHookOnlyUser::search('doe')->get()->pluck('name')->all());

        ScoutHookOnlyUser::removeAllFromSearch();

        $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_type', ScoutHookOnlyUser::class)->count());
        $this->assertSame(0, ScoutHookOnlyUser::search('mountaineer')->paginate()->total());
    }
}
