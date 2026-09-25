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
    /**
     * A model may customise the query the rebuild uses to load its rows (typically to
     * eager-load the relations its searchable accessors read, so a 100k-row rebuild does
     * not run 100k relation queries). The job must pass its query through that hook.
     */
    public function test_rebuild_job_applies_the_models_search_index_query_hook(): void
    {
        $hooked  = RebuildHookTestUser::create(['name' => 'hooked alpha', 'email' => 'h1@test.com']);
        $hooked2 = RebuildHookTestUser::create(['name' => 'hooked omega', 'email' => 'h7@test.com']);
        $plain   = RebuildHookTestUser::create(['name' => 'plain beta',   'email' => 'h2@test.com']);
        RebuildHookTestUser::$hookCalls = 0;

        $reads = 0;
        $this->app['db']->listen(function ($query) use (&$reads) {
            if (preg_match('/^\s*select\b.*\busers\b/is', $query->sql)) {
                $reads++;
            }
        });

        (new RebuildIndexJob(RebuildHookTestUser::class, [$hooked->id, $hooked2->id, $plain->id]))
            ->handle($this->makeManager());

        // Once for the job's load, once for the reload under the claim (ER-68): per job, never
        // per row. Two rows pass the hook, so a reload per row would count three.
        $this->assertSame(2, RebuildHookTestUser::$hookCalls, 'searchIndexQuery() must be called twice per job.');
        $this->assertSame(2, $reads, 'the job must read its rows twice, not once per row.');

        // The hook constrained the query to names starting with "hooked", proving it was applied.
        $this->assertDatabaseHas('fuzzy_index_postings', ['model_type' => RebuildHookTestUser::class, 'model_id' => $hooked->id]);
        $this->assertDatabaseHas('fuzzy_index_postings', ['model_type' => RebuildHookTestUser::class, 'model_id' => $hooked2->id]);
        $this->assertDatabaseMissing('fuzzy_index_postings', ['model_type' => RebuildHookTestUser::class, 'model_id' => $plain->id]);
    }

    public function test_rebuild_command_applies_the_hook_on_the_sync_path(): void
    {
        $hooked = RebuildHookTestUser::create(['name' => 'hooked gamma', 'email' => 'h3@test.com']);
        $plain  = RebuildHookTestUser::create(['name' => 'plain delta',  'email' => 'h4@test.com']);
        RebuildHookTestUser::$hookCalls = 0;

        $this->artisan('fuzzy-search:rebuild', ['model' => RebuildHookTestUser::class])->assertExitCode(0);

        $this->assertGreaterThanOrEqual(1, RebuildHookTestUser::$hookCalls);
        $this->assertDatabaseHas('fuzzy_index_postings', ['model_type' => RebuildHookTestUser::class, 'model_id' => $hooked->id]);
        $this->assertDatabaseMissing('fuzzy_index_postings', ['model_type' => RebuildHookTestUser::class, 'model_id' => $plain->id]);
    }

    public function test_rebuild_command_applies_the_hook_when_dispatching_async_batches(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        // --async checks that the batch table exists before it dispatches anything.
        config(['queue.batching.database' => config('database.default')]);
        \Illuminate\Support\Facades\Schema::dropIfExists('job_batches');
        \Illuminate\Support\Facades\Schema::create('job_batches', fn ($table) => $table->string('id')->primary());
        $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\Schema::dropIfExists('job_batches'));

        $hooked = RebuildHookTestUser::create(['name' => 'hooked epsilon', 'email' => 'h5@test.com']);
        RebuildHookTestUser::create(['name' => 'plain zeta', 'email' => 'h6@test.com']);
        RebuildHookTestUser::$hookCalls = 0;

        $this->artisan('fuzzy-search:rebuild', ['model' => RebuildHookTestUser::class, '--async' => true])->assertExitCode(0);

        \Illuminate\Support\Facades\Bus::assertBatched(function (\Illuminate\Bus\PendingBatch $batch) use ($hooked) {
            $ids = collect($batch->jobs)->flatMap(fn (RebuildIndexJob $job) => $job->modelIds)->all();
            return $ids === [$hooked->id]; // only the hooked row was chunked into jobs
        });
    }

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

class RebuildHookTestUser extends RebuildTestUser
{
    public static int $hookCalls = 0;

    public function searchIndexQuery(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        static::$hookCalls++;

        // Real models use this to eager-load relations; a where is used here because it is
        // observable through the index without needing a relation on the users table.
        return $query->where('name', 'like', 'hooked%');
    }
}
