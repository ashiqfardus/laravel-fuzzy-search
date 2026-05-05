<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Illuminate\Database\Eloquent\Model;

class ScoutEngineTest extends TestCase
{
    public function test_scout_engine_registers_when_scout_is_installed(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->app->make(\Laravel\Scout\EngineManager::class)->engine('fuzzy-search');
        $this->assertInstanceOf(FuzzySearchEngine::class, $engine);
    }

    public function test_scout_engine_indexes_and_retrieves(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $manager = new IndexManager(new WhitespaceTokenizer(), new NullStemmer());
        $scorer  = new Bm25Scorer();
        $engine  = new FuzzySearchEngine($manager, $scorer);

        $id = $this->app['db']->table('users')->insertGetId([
            'name' => 'scout laravel test', 'email' => 'scout' . uniqid() . '@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $instance = $model::find($id);
        $engine->update(collect([$instance]));

        $builder = new \Laravel\Scout\Builder($instance, 'laravel');
        $results = $engine->search($builder);

        $this->assertGreaterThan(0, $results['total']);
        $ids = $engine->mapIds($results)->toArray();
        $this->assertContains($id, $ids);
    }

    // -------------------------------------------------------------------------
    // Helpers shared by the new gap-filling tests
    // -------------------------------------------------------------------------

    private function makeEngine(): FuzzySearchEngine
    {
        return new FuzzySearchEngine(
            new IndexManager(new WhitespaceTokenizer(), new NullStemmer()),
            new Bm25Scorer()
        );
    }

    /** Insert a row into users and return [id, modelInstance]. */
    private function insertUser(string $name): array
    {
        $id = $this->app['db']->table('users')->insertGetId([
            'name'       => $name,
            'email'      => 'engine_' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $model = new class extends Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table    = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        return [$id, $model::find($id)];
    }

    // -------------------------------------------------------------------------
    // Gap 3a — delete()
    // -------------------------------------------------------------------------

    /**
     * After update() followed by delete(), all postings for the model
     * must be removed from the index.
     */
    public function test_scout_engine_delete_removes_from_index(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine        = $this->makeEngine();
        [$id, $instance] = $this->insertUser('delete engine test');
        $modelType     = get_class($instance);

        $engine->update(collect([$instance]));

        $before = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->where('model_id', $id)
            ->count();
        $this->assertGreaterThan(0, $before, 'Postings must exist after update().');

        $engine->delete(collect([$instance]));

        $after = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->where('model_id', $id)
            ->count();
        $this->assertSame(0, $after, 'delete() must remove all postings for the model.');
    }

    // -------------------------------------------------------------------------
    // Gap 3b — flush()
    // -------------------------------------------------------------------------

    /**
     * flush() must remove every indexed document for the model's class,
     * regardless of how many were indexed.
     */
    public function test_scout_engine_flush_clears_all_for_model(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        [, $instance1] = $this->insertUser('flush engine test one');
        [, $instance2] = $this->insertUser('flush engine test two');

        $modelType = get_class($instance1);

        $engine->update(collect([$instance1, $instance2]));

        $before = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->count();
        $this->assertGreaterThan(0, $before, 'Postings must exist for both models before flush().');

        // flush() receives the model instance (engine calls $model::class internally)
        $engine->flush($instance1);

        $after = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->count();
        $this->assertSame(0, $after, 'flush() must clear all postings for the entire model class.');
    }

    // -------------------------------------------------------------------------
    // Gap 3c — map()
    // -------------------------------------------------------------------------

    /**
     * map() with total=0 must return an empty Eloquent collection without
     * hitting the database.
     */
    public function test_scout_engine_map_returns_empty_collection_for_zero_results(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        $modelStub = new class extends Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $builder = new \Laravel\Scout\Builder($modelStub, 'anything');

        $result = $engine->map($builder, ['results' => collect(), 'total' => 0], $modelStub);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $result);
        $this->assertCount(0, $result);
    }

    /**
     * map() with a non-empty result set must return the correct Eloquent
     * models sorted by descending score and with _score populated.
     * Uses Scout's Searchable trait so getScoutModelsByIds() is available.
     */
    public function test_scout_engine_map_returns_correct_models(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        // Insert a row directly — we need a stable class name so Scout can look it up
        $id = $this->app['db']->table('users')->insertGetId([
            'name'       => 'map engine result test',
            'email'      => 'engine_map_' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Use a named class that carries Scout's Searchable (provides getScoutModelsByIds)
        $instance  = ScoutMapTestUser::find($id);
        $modelType = ScoutMapTestUser::class;

        // Build a synthetic results array (what search() returns)
        $builder = new \Laravel\Scout\Builder($instance, 'map');
        $syntheticResults = [
            'results' => collect([
                ['model_type' => $modelType, 'model_id' => $id, 'score' => 0.75],
            ]),
            'total' => 1,
        ];

        $mapped = $engine->map($builder, $syntheticResults, $instance);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $mapped);
        $this->assertCount(1, $mapped);
        $this->assertEquals($id, $mapped->first()->getKey());
        $this->assertSame(0.75, $mapped->first()->_score);
    }

    // -------------------------------------------------------------------------
    // Gap 3d — paginate()
    // -------------------------------------------------------------------------

    /**
     * paginate() must return an array with 'results' (sliced to the page)
     * and 'total' (the full unsliced count) — ready for Scout to wrap in a
     * LengthAwarePaginator via getTotalCount().
     */
    public function test_scout_engine_paginate_returns_correct_page_slice(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        // Index three models so there is data to paginate
        [, $instance1] = $this->insertUser('paginate engine alpha');
        [, $instance2] = $this->insertUser('paginate engine beta');
        [, $instance3] = $this->insertUser('paginate engine gamma');

        $engine->update(collect([$instance1, $instance2, $instance3]));

        $builder = new \Laravel\Scout\Builder($instance1, 'paginate engine');

        // Page 1 — 2 per page
        $page1 = $engine->paginate($builder, 2, 1);

        $this->assertArrayHasKey('results', $page1);
        $this->assertArrayHasKey('total', $page1);
        $this->assertLessThanOrEqual(2, $page1['results']->count(),
            'Page 1 must contain at most 2 results.');

        // getTotalCount() must reflect the unsliced total
        $total = $engine->getTotalCount($page1);
        $this->assertGreaterThanOrEqual(1, $total,
            'getTotalCount() must return the total number of matching documents.');
    }
}

// ---------------------------------------------------------------------------
// Named model class required by test_scout_engine_map_returns_correct_models.
// Must be a named (non-anonymous) class so Scout's Searchable trait can look
// up rows via getScoutModelsByIds(). Uses Scout's own Searchable trait which
// provides that method.
// ---------------------------------------------------------------------------

class ScoutMapTestUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable;

    protected $table    = 'users';
    protected $fillable = ['name', 'email', 'created_at', 'updated_at'];
    public $timestamps  = true;
}
