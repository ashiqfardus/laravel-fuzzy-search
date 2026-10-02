<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Single-table inheritance as tightenco/parental hydrates it: a parent query returns child instances. */
class StiParent extends Model
{
    use Searchable;

    protected $table    = 'sti_items';
    protected $guarded  = [];
    public $timestamps  = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = ($attributes['kind'] ?? '') === 'child' ? StiChild::class : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class StiChild extends StiParent
{
    protected static function booted(): void
    {
        static::addGlobalScope('child', fn ($query) => $query->where('kind', 'child'));
    }
}

/** The same hierarchy, indexed once: the child names the parent as its index type (SA-1). */
class StiTypedParent extends Model
{
    use Searchable;

    protected $table    = 'sti_items';
    protected $guarded  = [];
    public $timestamps  = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = ($attributes['kind'] ?? '') === 'child' ? StiTypedChild::class : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class StiTypedChild extends StiTypedParent
{
    protected static function booted(): void
    {
        static::addGlobalScope('child', fn ($query) => $query->where('kind', 'child'));
    }

    public static function searchIndexType(): string
    {
        return StiTypedParent::class;
    }
}

/** The typed hierarchy on the Scout driver, with both traits as docs/integrations.md wires them. */
class StiScoutParent extends Model
{
    use \Laravel\Scout\Searchable, Searchable {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::search as scoutSearch;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::bootSearchable as bootScoutSearchable;
    }

    protected $table    = 'sti_items';
    protected $guarded  = [];
    public $timestamps  = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = ($attributes['kind'] ?? '') === 'child' ? StiScoutChild::class : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class StiScoutChild extends StiScoutParent
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('child', fn ($query) => $query->where('kind', 'child'));
    }

    public static function searchIndexType(): string
    {
        return StiScoutParent::class;
    }
}

/** An abstract base on the same table: no concrete parent, so the children name one of them (R11-L1). */
abstract class StiVehicle extends Model
{
    use Searchable;

    protected $table    = 'sti_items';
    protected $guarded  = [];
    public $timestamps  = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public static function searchIndexType(): string
    {
        return StiCar::class;
    }
}

class StiCar extends StiVehicle
{
}

/** Names its sibling. */
class StiTruck extends StiVehicle
{
}

/** Names its abstract parent. */
class StiAbstractTyped extends StiVehicle
{
    public static function searchIndexType(): string
    {
        return StiVehicle::class;
    }
}

/** Names a morph alias, as a model_type column often holds. */
class StiAliasTyped extends StiParent
{
    public static function searchIndexType(): string
    {
        return 'sti_item';
    }
}

/** Names Eloquent's Model. */
class StiModelTyped extends StiParent
{
    public static function searchIndexType(): string
    {
        return Model::class;
    }
}

/** Names a model of another hierarchy. */
class StiUnrelatedTyped extends StiParent
{
    public static function searchIndexType(): string
    {
        return StiTypedParent::class;
    }
}

/**
 * RC-3. indexBatch() took the model type from a chunk's first model, and re-read the chunk's keys
 * through that class's query: a rebuild of an STI parent whose chunk began with a child indexed the
 * chunk under the child's class, and the child's global scope dropped every parent row of it.
 *
 * SA-1. RC-3's fix indexed every row under the class the rebuild was asked for, while every later
 * write, delete and the Scout engine used the row's own class: after a rebuild of the parent, a
 * child's edit or delete never reached the parent's index, and Scout's import built another index
 * than the rebuild. Every write, delete, rebuild (per row) and search now names the index type
 * through searchIndexType(): the row's own class by default, the parent where the children say so.
 */
class SingleTableInheritanceRebuildTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['sti_items', 'job_batches'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('sti_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('kind', 16);
        });
        // A child first in every chunk of two: the chunk's first model is a child.
        DB::table('sti_items')->insert([
            ['title' => 'alpha one', 'kind' => 'child'],
            ['title' => 'alpha two', 'kind' => 'parent'],
            ['title' => 'alpha three', 'kind' => 'child'],
            ['title' => 'alpha four', 'kind' => 'parent'],
            ['title' => 'alpha five', 'kind' => 'parent'],
        ]);
        config(['fuzzy-search.indexing.chunk_size' => 2]);
    }

    protected function tearDown(): void
    {
        foreach (['sti_items', 'job_batches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function createBatchesTable(): void
    {
        // The schema Laravel's make:queue-batches-table migration creates; the sync queue runs the jobs.
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
        config(['queue.batching.database' => config('database.default')]);
    }

    /** @return array<string, int> model_type => documents */
    private function documents(): array
    {
        return DB::table('fuzzy_index_documents')->pluck('model_type')->countBy()->sortKeys()->all();
    }

    /** @return list<int> the ids indexed under $type */
    private function indexedIds(string $type): array
    {
        return DB::table('fuzzy_index_documents')->where('model_type', $type)->pluck('model_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** @param class-string<Model> $class */
    private function indexTitles(string $class, string $term): array
    {
        return $class::search($term)->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->sort()->values()->all();
    }

    private function rebuild(string $class, array $options = []): void
    {
        if (isset($options['--async']) && !Schema::hasTable('job_batches')) {
            $this->createBatchesTable();
        }

        $this->artisan('fuzzy-search:rebuild', ['model' => $class, '--fresh' => true] + $options)->assertExitCode(0);
    }

    public function test_without_search_index_type_a_rebuild_of_the_parent_indexes_each_row_under_its_own_class(): void
    {
        foreach ([[], ['--async' => true]] as $options) {
            $this->rebuild(StiParent::class, $options);

            // No row dropped (RC-3), and each where its own class's writes and searches find it.
            $this->assertSame([StiChild::class => 2, StiParent::class => 3], $this->documents(), json_encode($options));
            $this->assertSame(3, (int) DB::table('fuzzy_index_meta')->where('model_type', StiParent::class)->value('total_docs'));
            $this->assertSame(['alpha five', 'alpha four', 'alpha two'], $this->indexTitles(StiParent::class, 'alpha'));
            $this->assertSame(['alpha one', 'alpha three'], $this->indexTitles(StiChild::class, 'alpha'));
        }
    }

    public static function writesForTheParentClass(): array
    {
        return [
            'reindexRelated() on the parent'   => ['reindexRelated'],
            'IndexModelJob for the parent'     => ['job'],
            'a save through a parent instance' => ['save'],
        ];
    }

    /**
     * R11-M1. A write for the parent class re-read its keys through the parent's query, which
     * hydrates a child row as the child, and filed every row under the parent: the parent's
     * reindexRelated(), an IndexModelJob named with the parent class, and a child row saved through
     * a parent instance (created as one, or a parent row turned into a child) put child rows in the
     * parent's index, where the child's later saves (under its own type) never reached them. Each
     * re-read row is now indexed under its own type.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writesForTheParentClass')]
    public function test_without_search_index_type_a_write_for_the_parent_class_files_a_child_row_under_the_child(string $write): void
    {
        $this->rebuild(StiParent::class);
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        if ($write === 'reindexRelated') {
            $this->assertSame(2, StiParent::reindexRelated('kind', 'child'));
        } elseif ($write === 'job') {
            (new IndexModelJob(StiParent::class, 1))->handle(app(IndexManager::class));
            (new IndexModelJob(StiParent::class, 3))->handle(app(IndexManager::class));
        } else {
            $created = StiParent::create(['title' => 'alpha six', 'kind' => 'child']);
            $this->assertInstanceOf(StiParent::class, $created);
            $this->assertNotInstanceOf(StiChild::class, $created);
            $this->assertSame([1, 3, 6], $this->indexedIds(StiChild::class), 'created through the parent class');

            // A parent row that becomes a child leaves the parent's index for the child's.
            $row = StiParent::query()->find(2);
            $this->assertNotInstanceOf(StiChild::class, $row);
            $row->update(['title' => 'alpha deux', 'kind' => 'child']);
            $this->assertSame([1, 2, 3, 6], $this->indexedIds(StiChild::class), 'turned into a child');
            $this->assertSame([4, 5], $this->indexedIds(StiParent::class));
            $this->assertSame(2, (int) DB::table('fuzzy_index_meta')->where('model_type', StiParent::class)->value('total_docs'));
            $this->assertSame(['alpha five', 'alpha four'], $this->indexTitles(StiParent::class, 'alpha'));

            return;
        }

        $this->assertSame([StiChild::class => 2, StiParent::class => 3], $this->documents(), 'no child row under the parent');
        $this->assertSame(3, (int) DB::table('fuzzy_index_meta')->where('model_type', StiParent::class)->value('total_docs'));

        // The child's own save updates the one copy there is: the parent's index never serves it by its old title.
        StiParent::query()->find(1)->update(['title' => 'omega one']);
        $this->assertSame(['alpha five', 'alpha four', 'alpha two'], $this->indexTitles(StiParent::class, 'alpha'));
        $this->assertSame([], $this->indexTitles(StiParent::class, 'omega'));
        $this->assertSame(['omega one'], $this->indexTitles(StiChild::class, 'omega'));
    }

    /**
     * The Scout engine has no class to be asked for: a collection that mixes classes (Scout's import
     * of the parent) is indexed under each model's index type, as delete() removes it; under the
     * first model's class, a deleted parent row stayed in the index.
     */
    public function test_the_scout_engine_indexes_each_model_under_its_own_class(): void
    {
        $engine = $this->app->make(\Laravel\Scout\EngineManager::class)->engine('fuzzy-search');
        $models = StiParent::query()->orderBy('id')->get();
        $engine->update($models);

        $this->assertSame([StiChild::class => 2, StiParent::class => 3], $this->documents());

        $engine->delete($models->where('kind', 'parent'));
        $this->assertSame([StiChild::class => 2], $this->documents());
        $this->assertSame(0, (int) DB::table('fuzzy_index_meta')->where('model_type', StiParent::class)->value('total_docs'));
    }

    public function test_with_search_index_type_a_rebuild_indexes_the_hierarchy_under_the_parent(): void
    {
        foreach ([[], ['--async' => true]] as $options) {
            $this->rebuild(StiTypedParent::class, $options);

            $this->assertSame([StiTypedParent::class => 5], $this->documents(), json_encode($options));
            $this->assertSame(5, (int) DB::table('fuzzy_index_meta')->where('model_type', StiTypedParent::class)->value('total_docs'));
            $this->assertSame(['alpha five', 'alpha four', 'alpha one', 'alpha three', 'alpha two'], $this->indexTitles(StiTypedParent::class, 'alpha'));

            // The child searches the parent's index, and its global scope keeps its own rows.
            $child = fn () => StiTypedChild::search('alpha')->useInvertedIndex()->typoTolerance(0);
            $this->assertSame(['alpha one', 'alpha three'], $this->indexTitles(StiTypedChild::class, 'alpha'));
            $this->assertSame(2, $child()->count());
            $this->assertSame(2, $child()->paginate(1)->total());
            $page = $child()->simplePaginate(1, page: 2);
            $this->assertCount(1, $page);
            $this->assertFalse($page->hasMorePages());
            $this->assertInstanceOf(StiTypedChild::class, $child()->first());
            $this->assertSame(['alpha one', 'alpha three'], $child()->orderBy('title')->get()->pluck('title')->all());
        }
    }

    public function test_with_search_index_type_a_rebuild_or_clear_of_the_child_acts_on_the_parents_index(): void
    {
        $this->rebuild(StiTypedParent::class);

        // The child's index is the parent's: --fresh flushes it all, so the rebuild reads the whole
        // hierarchy through the parent, or the parent's own rows would be left out.
        $this->artisan('fuzzy-search:rebuild', ['model' => StiTypedChild::class, '--fresh' => true])
            ->expectsOutputToContain('[' . StiTypedChild::class . '] is indexed as [' . StiTypedParent::class . ']')
            ->assertExitCode(0);
        $this->assertSame([StiTypedParent::class => 5], $this->documents());

        // A row the child left under its own name before it named the parent goes with it.
        DB::table('fuzzy_index_documents')->insert(['model_type' => StiTypedChild::class, 'model_id' => '1', 'doc_length' => 2]);

        $this->artisan('fuzzy-search:clear', ['model' => StiTypedChild::class])
            ->expectsOutputToContain('Cleared BM25 index for [' . StiTypedParent::class . ']')
            ->expectsOutputToContain('Cleared BM25 index for [' . StiTypedChild::class . ']')
            ->assertExitCode(0);
        $this->assertSame([], $this->documents());
    }

    /** The probe's first case: after a rebuild of the parent, a child's edit reaches the parent's index. */
    public function test_with_search_index_type_a_saved_child_updates_the_parents_index(): void
    {
        $this->rebuild(StiTypedParent::class);
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        // Edited through the parent's query, which hydrates the child, as every STI package does.
        $row = StiTypedParent::query()->find(1);
        $this->assertInstanceOf(StiTypedChild::class, $row);
        $row->update(['title' => 'omega one']);
        StiTypedParent::query()->find(2)->update(['title' => 'omega two']);

        $this->assertSame([StiTypedParent::class => 5], $this->documents());
        $this->assertSame(['omega one', 'omega two'], $this->indexTitles(StiTypedParent::class, 'omega'), 'the edited child is found by its new title');
        $this->assertSame(['alpha five', 'alpha four', 'alpha three'], $this->indexTitles(StiTypedParent::class, 'alpha'), 'and not by its old one');
        $this->assertSame(['omega one'], $this->indexTitles(StiTypedChild::class, 'omega'));

        // A child created on its own class goes to the parent's index too.
        StiTypedChild::create(['title' => 'omega six', 'kind' => 'child']);
        $this->assertSame([StiTypedParent::class => 6], $this->documents());
        $this->assertSame(['omega one', 'omega six'], $this->indexTitles(StiTypedChild::class, 'omega'));
    }

    /** The probe's second case: a child deleted after a rebuild of the parent leaves the parent's index. */
    public function test_with_search_index_type_a_deleted_child_leaves_the_parents_index(): void
    {
        $this->rebuild(StiTypedParent::class);
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        StiTypedParent::query()->find(3)->delete();

        $this->assertSame([1, 2, 4, 5], $this->indexedIds(StiTypedParent::class));
        $this->assertSame([StiTypedParent::class => 4], $this->documents());
        $this->assertSame(4, (int) DB::table('fuzzy_index_meta')->where('model_type', StiTypedParent::class)->value('total_docs'));
    }

    /** A job named with the child's class (reindexRelated(), one queued before the hook was added). */
    public function test_with_search_index_type_a_job_for_the_child_class_writes_the_parents_index(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        $this->assertSame(2, StiTypedChild::reindexRelated('kind', 'child'));
        $this->assertSame([1, 3], $this->indexedIds(StiTypedParent::class));

        (new IndexModelJob(StiTypedChild::class, 2))->handle(app(IndexManager::class));
        $this->assertSame([StiTypedParent::class => 3], $this->documents(), 'the parent row 2 read through the parent, not dropped by the child scope');
    }

    public function test_with_search_index_type_the_childs_suggestions_come_from_the_parents_index(): void
    {
        $this->rebuild(StiTypedParent::class);

        $this->assertSame(['three'], array_column(StiTypedChild::search('thre')->didYouMean(), 'term'));
        $this->assertSame([], StiTypedChild::search('fuor')->didYouMean(), 'four is only on a parent row, which the child scope hides');
        $this->assertSame(['four'], array_column(StiTypedParent::search('fuor')->didYouMean(), 'term'));
        $this->assertSame(['alpha'], StiTypedChild::search('alp')->suggestFrom('index')->suggest());
    }

    public static function invalidIndexTypes(): array
    {
        return [
            'a sibling'             => [StiTruck::class, StiCar::class],
            'an abstract ancestor'  => [StiAbstractTyped::class, StiVehicle::class],
            'a morph alias'         => [StiAliasTyped::class, 'sti_item'],
            'Eloquent\'s Model'     => [StiModelTyped::class, Model::class],
            'an unrelated model'    => [StiUnrelatedTyped::class, StiTypedParent::class],
        ];
    }

    /**
     * R11-L1. indexType() took whatever the hook returned. A class that is not the model or one of
     * its ancestors failed silently: a sibling's global scope hid every row of the model from its
     * write, so the model was never indexed, and an unrelated model's postings were served to its
     * search. Such a hook now throws a LogicException naming it, on every path that reads the type;
     * the observer reports it, as it reports every failed index write, and the save goes on.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidIndexTypes')]
    public function test_a_search_index_type_that_is_neither_the_class_nor_a_concrete_ancestor_throws_naming_the_hook(string $class, string $type): void
    {
        $message = "{$class}::searchIndexType() returned [{$type}]";
        $paths   = [
            'index search'  => fn () => $class::search('alpha')->useInvertedIndex()->get(),
            'didYouMean()'  => fn () => $class::search('alpah')->didYouMean(),
            'rebuild'       => fn () => \Illuminate\Support\Facades\Artisan::call('fuzzy-search:rebuild', ['model' => $class]),
            'clear'         => fn () => \Illuminate\Support\Facades\Artisan::call('fuzzy-search:clear', ['model' => $class]),
            'IndexModelJob' => fn () => (new IndexModelJob($class, 1))->handle(app(IndexManager::class)),
            'indexModel()'  => fn () => app(IndexManager::class)->indexModel($class::query()->find(2)), // a parent row: hydrated as $class
        ];
        foreach ($paths as $path => $run) {
            try {
                $run();
                $this->fail("{$path}: no exception");
            } catch (\LogicException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $path);
            }
        }

        $reported = [];
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, new class($reported) implements \Illuminate\Contracts\Debug\ExceptionHandler {
            public function __construct(private array &$reported) {}
            public function report(\Throwable $e) { $this->reported[] = $e; }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw $e; }
            public function renderForConsole($output, \Throwable $e) { throw $e; }
        });
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $class::query()->find(2)->update(['title' => 'omega two']);

        $this->assertSame('omega two', DB::table('sti_items')->where('id', 2)->value('title'), 'the save goes on');
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(\LogicException::class, $reported[0]);
        $this->assertStringContainsString($message, $reported[0]->getMessage());
        $this->assertSame([], $this->documents());
    }

    /** The model's own class, and a concrete ancestor named in any spelling PHP accepts, are its type. */
    public function test_a_search_index_type_naming_the_class_or_a_concrete_ancestor_is_the_type(): void
    {
        $this->assertSame(StiCar::class, IndexManager::indexType(StiCar::class));
        $this->assertSame(StiCar::class, IndexManager::indexType(new StiCar()));
        $this->assertSame(StiTypedParent::class, IndexManager::indexType(StiTypedChild::class));
        $this->assertSame(StiTypedParent::class, IndexManager::indexType('\\' . StiTypedChild::class));
        $this->assertSame(StiTypedParent::class, IndexManager::indexType(strtolower(StiTypedChild::class)));
        $this->assertSame(StiChild::class, IndexManager::indexType(new StiChild())); // no override: its own class
    }

    /** The probe's third case: Scout's import and fuzzy-search:rebuild of the parent build one index. */
    public function test_with_search_index_type_scout_import_and_rebuild_build_one_index(): void
    {
        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);

        StiScoutParent::query()->get()->searchable();
        $this->assertSame([StiScoutParent::class => 5], $this->documents());
        $imported = StiScoutParent::scoutSearch('alpha')->get()->pluck('title')->sort()->values()->all();
        $this->assertSame(['alpha five', 'alpha four', 'alpha one', 'alpha three', 'alpha two'], $imported);

        $this->rebuild(StiScoutParent::class);
        $this->assertSame([StiScoutParent::class => 5], $this->documents());
        $this->assertSame($imported, StiScoutParent::scoutSearch('alpha')->get()->pluck('title')->sort()->values()->all());

        // The child's Scout search reads the parent's index through its own scope.
        $this->assertSame(['alpha one', 'alpha three'], StiScoutChild::scoutSearch('alpha')->get()->pluck('title')->sort()->values()->all());
        $this->assertSame(2, StiScoutChild::scoutSearch('alpha')->paginate(1)->total());

        // Scout's delete of a child, and its save, reach the parent's index.
        StiScoutParent::query()->find(3)->delete();
        StiScoutParent::query()->find(1)->update(['title' => 'omega one']);
        $this->assertSame([1, 2, 4, 5], $this->indexedIds(StiScoutParent::class));
        $this->assertSame(['omega one'], StiScoutParent::scoutSearch('omega')->get()->pluck('title')->all());
    }
}
