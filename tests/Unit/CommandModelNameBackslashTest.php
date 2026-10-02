<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Indexed through its searchableText() hook alone, without the package's trait. */
class BackslashHookUser extends Model
{
    protected $table   = 'users';
    protected $guarded = [];

    public function searchableText(): array
    {
        return ['name' => $this->name];
    }
}

/** A Scout model with its own getSearchableColumns(), without the package's trait. */
class BackslashScoutUser extends Model
{
    use \Laravel\Scout\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    public function getSearchableColumns(): array
    {
        return ['name'];
    }
}

/**
 * SA-4. A model named as PHP code names it, with a leading backslash ("\App\Models\User"), passes
 * class_exists(), and the commands took it as given: rebuild indexed every row under the
 * backslashed name, which no search reads (its --fresh flushed that name, so the real index stayed
 * stale), and clear cleared nothing. Each command now reads the name without it.
 */
class CommandModelNameBackslashTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('job_batches');
        // The schema Laravel's make:queue-batches-table migration creates; the sync queue runs the jobs.
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
        config(['queue.batching.database' => config('database.default')]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('job_batches');

        parent::tearDown();
    }

    /** @return array<string, int> model_type => documents */
    private function documents(): array
    {
        return DB::table('fuzzy_index_documents')->pluck('model_type')->countBy()->all();
    }

    private function names(string $term): array
    {
        return User::search($term)->useInvertedIndex()->typoTolerance(0)->get()->pluck('name')->sort()->values()->all();
    }

    public function test_rebuild_with_a_leading_backslash_rebuilds_the_index_searches_read(): void
    {
        foreach ([[], ['--async' => true]] as $options) {
            DB::table('users')->update(['name' => 'Kiwi']);
            $this->artisan('fuzzy-search:rebuild', ['model' => User::class, '--fresh' => true])->assertExitCode(0);
            $this->assertSame(array_fill(0, 7, 'Kiwi'), $this->names('kiwi'));

            // The table changes behind the index's back; the rebuild below must bring the index in line.
            DB::table('users')->where('email', 'john@example.com')->update(['name' => 'Mango']);

            $this->artisan('fuzzy-search:rebuild', ['model' => '\\' . User::class, '--fresh' => true] + $options)->assertExitCode(0);

            $this->assertSame([User::class => 7], $this->documents(), json_encode($options) . ': every row under the class name a search reads');
            $this->assertSame(['Mango'], $this->names('mango'), json_encode($options) . ': the searched index was rebuilt');
            $this->assertCount(6, $this->names('kiwi'), json_encode($options));
            $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'mango')->value('doc_count'), json_encode($options) . ': one copy of the row');
        }
    }

    public function test_clear_and_flush_with_a_leading_backslash_clear_the_model(): void
    {
        foreach (['fuzzy-search:clear', 'fuzzy-search:flush'] as $command) {
            app(IndexManager::class)->indexBatch(User::all());
            $this->assertSame([User::class => 7], $this->documents());

            $this->artisan($command, ['model' => '\\' . User::class])
                ->expectsOutputToContain('Cleared BM25 index for [' . User::class . ']')
                ->assertExitCode(0);

            $this->assertSame([], $this->documents(), $command);
            $this->assertSame(0, DB::table('fuzzy_index_meta')->count(), $command);
            $this->assertSame(0, DB::table('fuzzy_index_terms')->count(), $command);
        }
    }

    public static function modelsWithoutTheTrait(): array
    {
        return ['searchableText() only' => [BackslashHookUser::class], 'Scout only' => [BackslashScoutUser::class]];
    }

    /**
     * R11-L13. For a model with the package's trait, IndexManager::indexType() returns the hook's
     * static::class, which has no leading backslash, so the commands' own ltrim went untested. A model
     * without the trait has no hook: the name as typed is its type, and without the ltrim --fresh and
     * clear flushed the backslashed name, leaving the real index as it was.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('modelsWithoutTheTrait')]
    public function test_a_model_without_the_package_trait_named_with_a_leading_backslash(string $class): void
    {
        foreach ([[], ['--async' => true]] as $options) {
            app(IndexManager::class)->indexBatch($class::all());
            $live = $class::count();
            DB::table('users')->where('id', $class::max('id'))->delete(); // behind the index's back

            $this->artisan('fuzzy-search:rebuild', ['model' => '\\' . $class, '--fresh' => true] + $options)->assertExitCode(0);

            $this->assertSame([$class => $live - 1], $this->documents(), json_encode($options) . ': --fresh flushed the index the rows are under');
        }

        foreach (['fuzzy-search:clear', 'fuzzy-search:flush'] as $command) {
            app(IndexManager::class)->indexBatch($class::all());

            $this->artisan($command, ['model' => '\\' . $class])
                ->expectsOutputToContain('Cleared BM25 index for [' . $class . ']')
                ->assertExitCode(0);

            $this->assertSame([], $this->documents(), $command);
            $this->assertSame(0, DB::table('fuzzy_index_meta')->count(), $command);
        }
    }
}
