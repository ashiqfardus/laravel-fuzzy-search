<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Illuminate\Database\Eloquent\Model;

class RebuildIndexJobTest extends TestCase
{
    /** Concrete model class used across all tests in this file */
    private string $modelClass;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelClass = RebuildTestUser::class;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeManager(): IndexManager
    {
        return new IndexManager(new WhitespaceTokenizer(), new NullStemmer());
    }

    private function createUser(string $name): Model
    {
        return RebuildTestUser::create([
            'name'       => $name,
            'email'      => 'rebuild_' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /**
     * Dispatching a RebuildIndexJob with a non-empty ID list must index the
     * specified models and write postings to fuzzy_index_postings.
     */
    public function test_rebuild_job_handles_successfully(): void
    {
        $user1 = $this->createUser('rebuild alpha test');
        $user2 = $this->createUser('rebuild beta test');

        $job     = new RebuildIndexJob($this->modelClass, [$user1->id, $user2->id]);
        $manager = $this->makeManager();

        // Must not throw
        $job->handle($manager);

        // Both models must have postings written
        $this->assertDatabaseHas('fuzzy_index_postings', [
            'model_type' => $this->modelClass,
            'model_id'   => $user1->id,
        ]);
        $this->assertDatabaseHas('fuzzy_index_postings', [
            'model_type' => $this->modelClass,
            'model_id'   => $user2->id,
        ]);
    }

    /**
     * A RebuildIndexJob with an empty ID list must silently do nothing —
     * no exception and no postings inserted.
     */
    public function test_rebuild_job_with_empty_ids_is_noop(): void
    {
        $countBefore = $this->app['db']->table('fuzzy_index_postings')->count();

        $job     = new RebuildIndexJob($this->modelClass, []);
        $manager = $this->makeManager();

        // Must not throw
        $job->handle($manager);

        $countAfter = $this->app['db']->table('fuzzy_index_postings')->count();
        $this->assertSame($countBefore, $countAfter,
            'RebuildIndexJob with empty IDs must leave postings table unchanged.');
    }
}

// ---------------------------------------------------------------------------
// Anonymous model – defined at file scope so RebuildIndexJob can instantiate
// it by class name (anonymous class names are not stable across calls).
// ---------------------------------------------------------------------------

class RebuildTestUser extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table    = 'users';
    protected $fillable = ['name', 'email', 'created_at', 'updated_at'];
    public $timestamps  = true;

    protected array $searchable = [
        'columns' => ['name' => 1],
    ];
}
