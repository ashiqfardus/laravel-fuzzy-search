<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;

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
}
