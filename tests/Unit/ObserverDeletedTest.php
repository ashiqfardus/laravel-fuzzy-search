<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableIndexingObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Queue;

class ObserverDeletedTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeManager(): IndexManager
    {
        return new IndexManager(new WhitespaceTokenizer(), new NullStemmer());
    }

    private function makeModel(string $name): Model
    {
        $model = new class extends Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

            protected $table    = 'users';
            protected $fillable = ['name', 'email', 'created_at', 'updated_at'];
            public $timestamps  = true;

            protected array $searchable = [
                'columns' => ['name' => 1],
            ];
        };

        return $model::create([
            'name'       => $name,
            'email'      => 'obs_del_' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * When indexing is synchronous, calling the observer's deleted() method
     * directly must remove all postings for the given model.
     */
    public function test_observer_deleted_removes_model_from_index(): void
    {
        config([
            'fuzzy-search.indexing.enabled' => true,
            'fuzzy-search.indexing.async'   => false,
        ]);

        $manager = $this->makeManager();
        $model   = $this->makeModel('observer delete test user');

        // Pre-condition: index the model so postings exist
        $manager->indexModel($model);

        $postingsBefore = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', get_class($model))
            ->where('model_id', $model->id)
            ->count();
        $this->assertGreaterThan(0, $postingsBefore,
            'Postings must exist before the observer deleted() call.');

        // Act: call the observer's deleted() branch directly (sync mode)
        $observer = new SearchableIndexingObserver();
        $observer->deleted($model);

        // Assert: postings for this model are gone
        $postingsAfter = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', get_class($model))
            ->where('model_id', $model->id)
            ->count();
        $this->assertSame(0, $postingsAfter,
            'observer deleted() must remove all postings for the deleted model.');
    }

    /**
     * When indexing is asynchronous, calling the observer's deleted() method
     * must dispatch an IndexModelJob to the configured queue.
     */
    public function test_observer_deleted_dispatches_job_when_queued(): void
    {
        config([
            'fuzzy-search.indexing.enabled' => true,
            'fuzzy-search.indexing.async'   => true,
            'fuzzy-search.indexing.queue'   => 'default',
        ]);

        Queue::fake();

        $model    = $this->makeModel('observer async delete');
        $observer = new SearchableIndexingObserver();

        $observer->deleted($model);

        Queue::assertPushed(IndexModelJob::class, function (IndexModelJob $job) use ($model) {
            return $job->modelClass === get_class($model)
                && $job->modelId === $model->getKey();
        });
    }

    /**
     * When indexing is disabled via config, the observer's deleted() method
     * must be a no-op — no jobs dispatched, no DB changes.
     */
    public function test_observer_deleted_is_noop_when_indexing_disabled(): void
    {
        config(['fuzzy-search.indexing.enabled' => false]);

        Queue::fake();

        $manager = $this->makeManager();
        $model   = $this->makeModel('observer disabled test');
        $manager->indexModel($model);

        $observer = new SearchableIndexingObserver();
        $observer->deleted($model);

        // No job dispatched
        Queue::assertNotPushed(IndexModelJob::class);

        // Postings still intact (observer was a no-op)
        $count = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', get_class($model))
            ->where('model_id', $model->id)
            ->count();
        $this->assertGreaterThan(0, $count,
            'observer deleted() must not touch the index when indexing is disabled.');
    }
}
