<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    /**
     * L4 (round 10). fuzzy-search:rebuild took a model as indexable only with getSearchableColumns(), so
     * it refused the hook-only model that D11 supports ("does not use the ... Searchable trait"), and
     * --async and the rebuild's ANALYZE were out of reach for it.
     */
    public function test_rebuild_indexes_it_sync_fresh_and_async(): void
    {
        $documents = fn () => DB::table('fuzzy_index_documents')->where('model_type', ScoutHookOnlyUser::class)->count();

        $this->artisan('fuzzy-search:rebuild', ['model' => ScoutHookOnlyUser::class])->assertExitCode(0);
        $this->assertSame(7, $documents());
        $this->assertSame(7, ScoutHookOnlyUser::search('mountaineer')->paginate()->total());

        // --fresh flushes first: a row deleted without a model event leaves the index.
        DB::table('users')->where('name', 'John Doe')->delete();
        $this->artisan('fuzzy-search:rebuild', ['model' => ScoutHookOnlyUser::class, '--fresh' => true])->assertExitCode(0);
        $this->assertSame(6, $documents());
        $this->assertSame(['Jane Doe'], ScoutHookOnlyUser::search('doe')->get()->pluck('name')->all());

        // --async on the sync queue: the batch's jobs run as it is dispatched.
        config(['queue.batching.database' => config('database.default'), 'queue.default' => 'sync']);
        Schema::dropIfExists('job_batches');
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        $this->beforeApplicationDestroyed(fn () => Schema::dropIfExists('job_batches'));

        $this->artisan('fuzzy-search:flush', ['model' => ScoutHookOnlyUser::class])->assertExitCode(0);
        $this->assertSame(0, $documents());
        $this->artisan('fuzzy-search:rebuild', ['model' => ScoutHookOnlyUser::class, '--async' => true])->assertExitCode(0);
        $this->assertSame(6, $documents());
        $this->assertSame(6, ScoutHookOnlyUser::search('mountaineer')->paginate()->total());
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
