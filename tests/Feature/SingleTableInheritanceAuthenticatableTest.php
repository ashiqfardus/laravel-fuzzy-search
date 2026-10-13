<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** tightenco/parental's HasChildren, reduced: the row's kind picks the class. */
trait StiAuthKinds
{
    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = ($attributes['kind'] ?? null) === 'admin' ? static::ADMIN : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

/** An app's User as Laravel ships it: Illuminate\Foundation\Auth\User (concrete, table users) is its parent. */
class StiAuthUser extends \Illuminate\Foundation\Auth\User
{
    use Searchable, StiAuthKinds;

    const ADMIN = StiAuthAdmin::class;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

class StiAuthAdmin extends StiAuthUser
{
    protected static function booted(): void
    {
        static::addGlobalScope('admin', fn ($query) => $query->where('kind', 'admin'));
        static::creating(fn ($model) => $model->kind = 'admin');
    }
}

/** The same hierarchy on the Scout driver, with both traits as docs/integrations.md wires them. */
class StiAuthScoutUser extends \Illuminate\Foundation\Auth\User
{
    use \Laravel\Scout\Searchable, Searchable, StiAuthKinds {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::search as scoutSearch;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::bootSearchable as bootScoutSearchable;
    }

    const ADMIN = StiAuthScoutAdmin::class;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

class StiAuthScoutAdmin extends StiAuthScoutUser
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('admin', fn ($query) => $query->where('kind', 'admin'));
    }
}

/**
 * VD-5. A row's kind was read through the topmost concrete model on the hierarchy's table. On
 * Laravel's default User that is Illuminate\Foundation\Auth\User, whose newFromBuilder() is
 * Eloquent's, so every row's kind was the framework class: a rebuild wrote each chunk under it
 * (and reported "Indexed 0 of N"), a Scout save of an admin row through a User instance landed in
 * no index, and deleting it left it in the admin's (UD-3). The kind is now read through the
 * topmost class that dispatches the kinds.
 */
class SingleTableInheritanceAuthenticatableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::table('users', fn ($table) => $table->string('kind', 16)->default('user'));
        DB::table('users')->whereIn('email', ['jane@example.com', 'alice@example.com'])->update(['kind' => 'admin']);
        config(['fuzzy-search.indexing.chunk_size' => 2]);
    }

    /** @return array<string, int> model_type => documents */
    private function documents(): array
    {
        return DB::table('fuzzy_index_documents')->pluck('model_type')->countBy()->sortKeys()->all();
    }

    /** @return array<string, int> model_type => total_docs */
    private function totals(): array
    {
        return DB::table('fuzzy_index_meta')->pluck('total_docs', 'model_type')->map(fn ($n) => (int) $n)->sortKeys()->all();
    }

    private function docCount(string $term): int
    {
        return (int) DB::table('fuzzy_index_terms')->where('term', $term)->value('doc_count');
    }

    /** @return list<int> */
    private function indexedIds(string $type): array
    {
        return DB::table('fuzzy_index_documents')->where('model_type', $type)->pluck('model_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    private function indexNames(string $class, string $term): array
    {
        return $class::search($term)->useInvertedIndex()->typoTolerance(0)->get()->pluck('name')->sort()->values()->all();
    }

    private function createBatchesTable(): void
    {
        Schema::dropIfExists('job_batches');
        Schema::create('job_batches', function ($table) {
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

    public function test_the_kind_is_read_through_the_class_that_dispatches_it(): void
    {
        $admin = StiAuthUser::query()->where('email', 'jane@example.com')->first();
        $this->assertInstanceOf(StiAuthAdmin::class, $admin);

        $asUser = (new StiAuthUser())->newInstance([], true);
        $asUser->setRawAttributes($admin->getAttributes(), true);
        $this->assertSame(StiAuthAdmin::class, IndexManager::rowClass($asUser));
        $this->assertSame([StiAuthAdmin::class], IndexManager::rowTypes($asUser), 'a User instance holding an admin row');
        $this->assertSame(StiAuthUser::class, IndexManager::rowClass(StiAuthUser::query()->where('email', 'john@example.com')->first()));
        $this->assertSame([StiAuthUser::class], IndexManager::rowTypes(StiAuthUser::query()->where('email', 'john@example.com')->first()));
    }

    public function test_a_rebuild_of_the_user_indexes_each_row_under_its_kind_and_counts_it(): void
    {
        foreach ([[], ['--async' => true]] as $options) {
            if ($options !== []) {
                $this->createBatchesTable();
            }
            $this->artisan('fuzzy-search:rebuild', ['model' => StiAuthUser::class, '--fresh' => true] + $options)
                ->expectsOutputToContain($options === [] ? 'Done. Indexed 7 of 7 records.' : 'Batch dispatched')
                ->doesntExpectOutputToContain('no index terms were produced')
                ->assertExitCode(0);

            $this->assertSame([StiAuthAdmin::class => 2, StiAuthUser::class => 5], $this->documents(), json_encode($options));
            $this->assertSame([StiAuthAdmin::class => 2, StiAuthUser::class => 5], $this->totals());
            $this->assertSame(['Jane Doe'], $this->indexNames(StiAuthAdmin::class, 'doe'));
            $this->assertSame(['Alice Smith'], $this->indexNames(StiAuthAdmin::class, 'smith'));
            $this->assertSame(['John Doe'], $this->indexNames(StiAuthUser::class, 'doe'));
        }
    }

    public static function observerModes(): array
    {
        return ['in-process' => [false], 'queued' => [true]];
    }

    /** UD-3 and UB-1 on this shape: through the observer, a row is filed and removed by its kind. */
    #[\PHPUnit\Framework\Attributes\DataProvider('observerModes')]
    public function test_a_save_or_delete_through_a_user_instance_files_the_row_by_its_kind(bool $async): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => $async]);

        $row = StiAuthUser::create(['name' => 'zulu yankee', 'email' => 'z@example.com', 'kind' => 'admin']);
        $this->assertNotInstanceOf(StiAuthAdmin::class, $row);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiAuthAdmin::class));
        $this->assertSame(['zulu yankee'], $this->indexNames(StiAuthAdmin::class, 'zulu'));
        $this->assertSame([StiAuthAdmin::class => 1], $this->totals());

        $row->delete();
        $this->assertSame([], $this->documents());
        $this->assertSame([StiAuthAdmin::class => 0], $this->totals());
        $this->assertSame(0, $this->docCount('zulu'));
        $this->assertSame(0, StiAuthAdmin::search('zulu')->useInvertedIndex()->typoTolerance(0)->count());
        $this->assertSame([], StiAuthAdmin::search('zul')->suggestFrom('index')->suggest());

        // Promoted through a User instance by the type column alone, then demoted through the admin's.
        $row = StiAuthUser::create(['name' => 'xray whiskey', 'email' => 'x@example.com']);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiAuthUser::class));
        $row->update(['kind' => 'admin']);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiAuthAdmin::class));
        $this->assertSame([], $this->indexedIds(StiAuthUser::class));
        StiAuthAdmin::query()->find($row->getKey())->update(['kind' => 'user']);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiAuthUser::class));
        $this->assertSame([], $this->indexedIds(StiAuthAdmin::class));
        $this->assertSame(1, $this->docCount('xray'));
    }

    public static function scoutQueues(): array
    {
        return ['sync' => [false], 'queued' => [true]];
    }

    /** The Scout engine: a save through a User instance of an admin row lands in the admin's index, and its delete leaves it. */
    #[\PHPUnit\Framework\Attributes\DataProvider('scoutQueues')]
    public function test_the_scout_engine_files_and_removes_a_row_by_its_kind(bool $queue): void
    {
        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => $queue, 'scout.after_commit' => false]);

        $row = StiAuthScoutUser::create(['name' => 'zulu yankee', 'email' => 'z@example.com', 'kind' => 'admin']);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiAuthScoutAdmin::class));
        $this->assertSame([], $this->indexedIds(StiAuthScoutUser::class));
        $this->assertSame(['zulu yankee'], StiAuthScoutAdmin::scoutSearch('zulu')->get()->pluck('name')->all());

        $row->delete();
        $this->assertSame([], $this->documents());
        $this->assertSame(0, $this->docCount('zulu'));

        $this->app->make(\Laravel\Scout\EngineManager::class)->engine('fuzzy-search')->update(StiAuthScoutUser::query()->get()); // the import
        $this->assertSame([StiAuthScoutAdmin::class => 2, StiAuthScoutUser::class => 5], $this->documents());
        $this->assertSame([StiAuthScoutAdmin::class => 2, StiAuthScoutUser::class => 5], $this->totals());
    }
}
