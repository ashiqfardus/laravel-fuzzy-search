<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Keyed for Scout by its email column. */
class ScoutEmailKeyUser extends Model
{
    use \Laravel\Scout\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    public function searchableText(): array
    {
        return ['name' => $this->name];
    }

    public function getScoutKey(): mixed
    {
        return $this->email;
    }

    public function getScoutKeyName(): mixed
    {
        return 'email';
    }
}

/** getScoutKey() alone overridden: the name is still the primary key's. */
class ScoutEmailValueUser extends Model
{
    use \Laravel\Scout\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    public function searchableText(): array
    {
        return ['name' => $this->name];
    }

    public function getScoutKey(): mixed
    {
        return $this->email;
    }
}

/**
 * L10 (round 10). The engine keys the index by the primary key, and Scout looks a page's models up,
 * and restores a queued delete's, by getScoutKeyName(): a model with a custom Scout key was indexed,
 * a search then found none of its models, and its queued delete threw a TypeError. Indexing or
 * searching one throws a LogicException naming the fix.
 */
class ScoutCustomKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);
    }

    private function assertRejected(callable $call, string $label): void
    {
        try {
            $call();
            $this->fail("{$label}: no exception");
        } catch (\LogicException $e) {
            $this->assertStringContainsString('custom Scout key', $e->getMessage(), $label);
            $this->assertStringContainsString('getScoutKey()', $e->getMessage(), $label);
        }
    }

    public function test_indexing_a_model_with_a_custom_scout_key_throws(): void
    {
        $this->assertRejected(fn () => ScoutEmailKeyUser::all()->searchable(), 'searchable()');
        $this->assertRejected(fn () => ScoutEmailKeyUser::create(['name' => 'Tenzing Norgay', 'email' => 'tenzing@example.com']), 'the save path');
        $this->assertRejected(fn () => ScoutEmailValueUser::all()->searchable(), 'getScoutKey() alone');

        $this->assertSame(0, DB::table('fuzzy_index_documents')->count(), 'nothing was indexed');
    }

    public function test_searching_a_model_with_a_custom_scout_key_throws(): void
    {
        // Indexed by primary key, as fuzzy-search:rebuild writes it: the rows are there to find.
        app(IndexManager::class)->indexBatch(ScoutEmailKeyUser::all());

        $this->assertRejected(fn () => ScoutEmailKeyUser::search('doe')->get(), 'get()');
        $this->assertRejected(fn () => ScoutEmailKeyUser::search('doe')->paginate(3), 'paginate()');
        $this->assertRejected(fn () => ScoutEmailKeyUser::search('doe')->keys(), 'keys()');
    }

    public function test_a_delete_removes_the_row_by_primary_key_and_a_queued_one_throws(): void
    {
        app(IndexManager::class)->indexBatch(ScoutEmailKeyUser::all());
        $documents = fn () => DB::table('fuzzy_index_documents')->where('model_type', ScoutEmailKeyUser::class)->count();
        $before    = $documents();

        // The model itself reaches the engine: it has its primary key.
        ScoutEmailKeyUser::query()->orderBy('id')->first()->delete();
        $this->assertSame($before - 1, $documents());

        // Scout restores a queued delete's model with only the Scout key set.
        config(['scout.queue' => true, 'queue.default' => 'sync']);
        $this->assertRejected(fn () => ScoutEmailKeyUser::query()->orderBy('id')->first()->delete(), 'a queued delete');
    }
}
