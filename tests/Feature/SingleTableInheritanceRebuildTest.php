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
        $class      = match ($attributes['kind'] ?? '') {
            'child'   => StiChild::class,
            'sibling' => StiSibling::class,
            default   => static::class,
        };
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

class StiSibling extends StiParent
{
    protected static function booted(): void
    {
        static::addGlobalScope('sibling', fn ($query) => $query->where('kind', 'sibling'));
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

/** A subclass that is no kind of the hierarchy: its own query reads rows of the parent's kind as itself. */
class StiAlphaView extends StiParent
{
    protected static function booted(): void
    {
        static::addGlobalScope('alpha', fn ($query) => $query->where('title', 'like', 'alpha%'));
    }
}

/** The untyped hierarchy on the Scout driver: each class indexed under its own class. */
class StiScoutUntypedParent extends Model
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
        $class      = ($attributes['kind'] ?? '') === 'child' ? StiScoutUntypedChild::class : static::class;
        $model      = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class StiScoutUntypedChild extends StiScoutUntypedParent
{
    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('child', fn ($query) => $query->where('kind', 'child'));
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

/** A subclass on a table of its own, not single-table inheritance, that names its parent (UA-2). */
class StiOtherTableTyped extends StiParent
{
    protected $table = 'sti_archived_items';

    public static function searchIndexType(): string
    {
        return StiParent::class;
    }
}

/** A subclass of the same table on another connection, naming its parent (UA-2). */
class StiOtherConnectionTyped extends StiParent
{
    protected $connection = 'sti_other_connection';

    public static function searchIndexType(): string
    {
        return StiParent::class;
    }
}

/** Three levels on one table, each hook naming its own parent (UA-6). */
class StiChainVehicle extends Model
{
    use Searchable;

    protected $table    = 'sti_items';
    protected $guarded  = [];
    public $timestamps  = false;

    protected array $searchable = ['columns' => ['title' => 1]];

    public function newFromBuilder($attributes = [], $connection = null)
    {
        $attributes = (array) $attributes;
        $class      = match ($attributes['kind'] ?? '') {
            'car'    => StiChainCar::class,
            'sports' => StiChainSportsCar::class,
            default  => static::class,
        };
        $model = (new $class)->newInstance([], true);
        $model->setRawAttributes($attributes, true);
        $model->setConnection($connection ?: $this->getConnectionName());

        return $model;
    }
}

class StiChainCar extends StiChainVehicle
{
    protected static function booted(): void
    {
        static::addGlobalScope('kind', fn ($query) => $query->whereIn('kind', static::kinds()));
        static::creating(fn ($model) => $model->kind ??= static::kinds()[0]);
    }

    protected static function kinds(): array
    {
        return ['car', 'sports'];
    }

    public static function searchIndexType(): string
    {
        return StiChainVehicle::class;
    }
}

class StiChainSportsCar extends StiChainCar
{
    protected static function kinds(): array
    {
        return ['sports'];
    }

    public static function searchIndexType(): string
    {
        return StiChainCar::class;
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

        foreach (['sti_items', 'job_batches', 'sti_archived_items'] as $table) {
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
        // The refiles and unfiles, like every write path, keeps each type's word counts with its postings (ER-197).
        if ($this->status()->isSuccess()) {
            $this->assertModelTermsMirrorPostings(static::class . '::' . $this->name());
        }

        foreach (['sti_items', 'job_batches', 'sti_archived_items'] as $table) {
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

    /** @return array<string, int> model_type => total_docs, the BM25 totals */
    private function totals(): array
    {
        return DB::table('fuzzy_index_meta')->pluck('total_docs', 'model_type')->map(fn ($n) => (int) $n)->sortKeys()->all();
    }

    /** The number of indexed rows holding $term, across every index type. */
    private function docCount(string $term): int
    {
        return (int) DB::table('fuzzy_index_terms')->where('term', $term)->value('doc_count');
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

    /** The exceptions reported from here on (the observer reports a failed index write). */
    private function reports(): \ArrayObject
    {
        $reported = new \ArrayObject();
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, new class($reported) implements \Illuminate\Contracts\Debug\ExceptionHandler {
            public function __construct(private \ArrayObject $reported) {}
            public function report(\Throwable $e) { $this->reported[] = $e; }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw $e; }
            public function renderForConsole($output, \Throwable $e) { throw $e; }
        });

        return $reported;
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

        $reported = $this->reports();
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

    /**
     * UA-6. indexType() returned the ancestor a hook named, as named: in a three-level hierarchy
     * whose every hook names its own parent, the grandchild was indexed under the middle class,
     * whose own hook names the root, so no search read it (the root's and the middle class's index
     * searches read the root's index), and a rebuild --fresh of the root kept it so. The named
     * ancestor now resolves to its own index type, the root's.
     */
    public function test_a_hook_naming_its_parent_resolves_to_the_parents_own_index_type(): void
    {
        $this->assertSame(StiChainVehicle::class, IndexManager::indexType(StiChainSportsCar::class));
        $this->assertSame(StiChainVehicle::class, IndexManager::indexType(new StiChainSportsCar()));

        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        StiChainCar::create(['title' => 'red sedan']);
        StiChainSportsCar::create(['title' => 'red roadster']);
        StiChainVehicle::create(['title' => 'red tractor', 'kind' => 'vehicle']);

        $this->assertSame([StiChainVehicle::class => 3], $this->documents(), 'each saved through its own class');
        $this->assertSame(['red roadster', 'red sedan', 'red tractor'], $this->indexTitles(StiChainVehicle::class, 'red'));
        $this->assertSame(['red roadster', 'red sedan'], $this->indexTitles(StiChainCar::class, 'red'));
        $this->assertSame(['red roadster'], $this->indexTitles(StiChainSportsCar::class, 'red'));

        $this->rebuild(StiChainVehicle::class);
        $this->assertSame([StiChainVehicle::class => 8], $this->documents(), 'one index after a rebuild --fresh of the root');
        $this->assertSame(['red roadster', 'red sedan'], $this->indexTitles(StiChainCar::class, 'red'));
        $this->assertSame(2, StiChainCar::search('red')->useInvertedIndex()->typoTolerance(0)->count());
    }

    /**
     * UA-2. R11-L1's guard took any concrete ancestor: a subclass on a table of its own whose hook
     * names its parent was re-read through the parent's table on every write, so the parent's row
     * with each id was indexed in its place, its own text never was, and its index search served
     * its own rows for the parent's words. The ancestor must read the class's table on the class's
     * connection, or the hook throws, as R11-L1's do; a save reports it.
     */
    public function test_a_search_index_type_naming_an_ancestor_on_another_table_or_connection_throws(): void
    {
        Schema::create('sti_archived_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('kind', 16);
        });
        StiOtherTableTyped::query()->create(['title' => 'quokka archive', 'kind' => 'parent']);

        try {
            IndexManager::indexType(StiOtherConnectionTyped::class);
            $this->fail('another connection: no exception');
        } catch (\LogicException $e) {
            $this->assertStringContainsString(StiOtherConnectionTyped::class . '::searchIndexType() returned [' . StiParent::class . ']', $e->getMessage());
        }

        $class   = StiOtherTableTyped::class;
        $message = "{$class}::searchIndexType() returned [" . StiParent::class . ']';
        $paths   = [
            'indexType()'   => fn () => IndexManager::indexType($class),
            'index search'  => fn () => $class::search('quokka')->useInvertedIndex()->get(),
            'didYouMean()'  => fn () => $class::search('qokka')->didYouMean(),
            'rebuild'       => fn () => \Illuminate\Support\Facades\Artisan::call('fuzzy-search:rebuild', ['model' => $class]),
            'IndexModelJob' => fn () => (new IndexModelJob($class, 1))->handle(app(IndexManager::class)),
            'indexModel()'  => fn () => app(IndexManager::class)->indexModel($class::query()->find(1)),
        ];
        foreach ($paths as $path => $run) {
            try {
                $run();
                $this->fail("{$path}: no exception");
            } catch (\LogicException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $path);
            }
        }

        $reported = $this->reports();
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $class::query()->find(1)->update(['title' => 'quokka archived']);

        $this->assertSame('quokka archived', DB::table('sti_archived_items')->where('id', 1)->value('title'), 'the save goes on');
        $this->assertCount(1, $reported);
        $this->assertStringContainsString($message, $reported[0]->getMessage());
        $this->assertSame([], $this->documents(), 'the post with the same id is not indexed in its place');
    }

    public static function observerModes(): array
    {
        return ['in-process' => [false], 'queued' => [true]];
    }

    /**
     * UB-1, UD-4 (R12-M2 b, c). Without the hook each class has an index of its own, and a row is
     * filed under the class it hydrates as (R11-M1). A save named the index by the instance's class:
     * a child demoted through its own instance left the child's index (its scoped re-read no longer
     * found it) and joined none, and a change of the type column alone reindexed nothing, so the row
     * stayed under its old class and was missing from its new one's. A row is now filed by the class
     * its attributes hydrate as, and leaves every other type of its hierarchy.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('observerModes')]
    public function test_a_type_change_moves_the_row_to_the_index_of_the_class_it_now_hydrates_as(bool $async): void
    {
        $this->rebuild(StiParent::class);
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => $async]);

        StiChild::query()->find(1)->update(['kind' => 'parent']);                         // demoted, the type column alone
        StiChild::query()->find(3)->update(['kind' => 'parent', 'title' => 'alpha trois']); // demoted, with its title
        StiParent::query()->find(2)->update(['kind' => 'child']);                         // promoted through a parent instance

        $this->assertSame([2], $this->indexedIds(StiChild::class));
        $this->assertSame([1, 3, 4, 5], $this->indexedIds(StiParent::class));
        $this->assertSame(['alpha five', 'alpha four', 'alpha one', 'alpha trois'], $this->indexTitles(StiParent::class, 'alpha'));
        $this->assertSame(4, StiParent::search('alpha')->useInvertedIndex()->typoTolerance(0)->count());
        $this->assertSame(['alpha two'], $this->indexTitles(StiChild::class, 'alpha'));
        $this->assertSame([StiChild::class => 1, StiParent::class => 4], $this->totals());
        $this->assertSame(5, $this->docCount('alpha'), 'each row counted once');
        $this->assertSame(0, $this->docCount('three'), 'the old title of a demoted row is gone');

        // Promoted through a parent instance, then demoted through a child instance read afresh.
        $row = StiParent::create(['title' => 'promo six', 'kind' => 'parent']);
        $row->update(['kind' => 'child']);
        $this->assertSame(['promo six'], $this->indexTitles(StiChild::class, 'promo'));
        StiChild::query()->find($row->getKey())->update(['kind' => 'parent']);
        $this->assertSame(['promo six'], $this->indexTitles(StiParent::class, 'promo'));
        $this->assertSame([], $this->indexTitles(StiChild::class, 'promo'));
        $this->assertSame([StiChild::class => 1, StiParent::class => 5], $this->totals());
    }

    /**
     * UD-3 (R12-M2 a). A row saved through a parent instance is filed under its child class (R11-M1),
     * but deleting that instance named the parent's index, where the row was not: the child's index
     * kept the deleted row, its words were still suggested, and its BM25 totals counted it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('observerModes')]
    public function test_a_row_deleted_through_a_parent_instance_leaves_its_childs_index(bool $async): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => $async]);

        $row = StiParent::create(['title' => 'zulu yankee', 'kind' => 'child']);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiChild::class));
        $row->delete();

        $this->assertSame([], $this->documents());
        $this->assertSame([StiChild::class => 0], $this->totals());
        $this->assertSame(0, $this->docCount('zulu'));
        $this->assertSame(0, StiChild::search('zulu')->useInvertedIndex()->typoTolerance(0)->count());
        $this->assertSame([], StiChild::search('zul')->suggestFrom('index')->suggest());
        $this->assertSame([], StiChild::search('zulo')->didYouMean());

        // A child instance demoted, then deleted: the row was filed under the parent by then.
        $row = StiChild::create(['title' => 'xray whiskey', 'kind' => 'child']);
        $row->update(['kind' => 'parent']);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiParent::class));
        $row->delete();
        $this->assertSame([], $this->documents());
        $this->assertSame(0, $this->docCount('xray'));
    }

    /**
     * UB-1. A plain rebuild of the parent, the documented repair, wrote each row under the class it
     * hydrates as and left any entry it had under another type of the hierarchy: a row whose type
     * changed outside the app was then in both indexes. The rebuild now removes it from the others.
     */
    public function test_a_plain_rebuild_removes_a_row_from_the_type_it_was_filed_under_before(): void
    {
        $this->rebuild(StiParent::class);
        DB::table('sti_items')->where('id', 1)->update(['kind' => 'parent']); // no model event
        DB::table('sti_items')->where('id', 2)->update(['kind' => 'child']);

        $this->artisan('fuzzy-search:rebuild', ['model' => StiParent::class])->assertExitCode(0);

        $this->assertSame([2, 3], $this->indexedIds(StiChild::class));
        $this->assertSame([1, 4, 5], $this->indexedIds(StiParent::class));
        $this->assertSame([StiChild::class => 2, StiParent::class => 3], $this->totals());
        $this->assertSame(5, $this->docCount('alpha'));
        $this->assertSame(['alpha five', 'alpha four', 'alpha one'], $this->indexTitles(StiParent::class, 'alpha'));
    }

    /**
     * A subclass that is no kind of the hierarchy (its own query reads rows of the parent's kind as
     * itself) keeps the index of its own: a save through it writes that index, and the row's kind's.
     */
    public function test_a_subclass_that_is_no_kind_of_the_hierarchy_keeps_its_own_index(): void
    {
        $this->rebuild(StiParent::class);
        $this->rebuild(StiAlphaView::class);
        $this->assertSame([StiAlphaView::class => 3, StiChild::class => 2, StiParent::class => 3], $this->documents());
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        StiAlphaView::query()->find(2)->update(['title' => 'alpha deux']);
        $created = StiAlphaView::create(['title' => 'alpha view', 'kind' => 'parent']);

        $this->assertSame(['alpha deux'], $this->indexTitles(StiAlphaView::class, 'deux'));
        $this->assertSame(['alpha deux'], $this->indexTitles(StiParent::class, 'deux'), 'the row\'s kind is written too');
        $this->assertSame(['alpha view'], $this->indexTitles(StiAlphaView::class, 'view'));
        $this->assertSame([2, 4, 5, $created->getKey()], $this->indexedIds(StiAlphaView::class));
        $this->assertSame([2, 4, 5, $created->getKey()], $this->indexedIds(StiParent::class));
    }

    public static function scoutQueues(): array
    {
        return ['sync' => [false], 'queued' => [true]];
    }

    /**
     * UD-3, UB-1 on the Scout engine. Its delete() named the instance's class: a row created through
     * the parent class as a child stayed in the child's index when that instance was deleted, and a
     * queued delete, which knows only the key, could not tell where the row was. A queued update
     * restores the row as its new class, which cannot tell the type it was filed under before. The
     * engine now removes a row from every type of its hierarchy that holds it but the one it is
     * filed under.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('scoutQueues')]
    public function test_the_scout_engine_files_and_removes_a_row_by_the_class_it_hydrates_as(bool $queue): void
    {
        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => $queue, 'scout.after_commit' => false]);

        $row = StiScoutUntypedParent::create(['title' => 'zulu yankee', 'kind' => 'child']);
        $this->assertSame([$row->getKey()], $this->indexedIds(StiScoutUntypedChild::class));
        $row->delete();
        $this->assertSame([], $this->documents(), 'deleted through the parent instance it was created as');
        $this->assertSame(0, $this->docCount('zulu'));

        $this->app->make(\Laravel\Scout\EngineManager::class)->engine('fuzzy-search')->update(StiScoutUntypedParent::query()->get()); // the import; Scout queues no mixed collection
        StiScoutUntypedChild::query()->find(1)->update(['kind' => 'parent']); // demoted through its own instance
        StiScoutUntypedParent::query()->find(2)->update(['kind' => 'child']); // promoted through a parent instance
        $this->assertSame([2, 3], $this->indexedIds(StiScoutUntypedChild::class));
        $this->assertSame([1, 4, 5], $this->indexedIds(StiScoutUntypedParent::class));
        $this->assertSame(5, $this->docCount('alpha'));

        $child = StiScoutUntypedChild::query()->find(3);
        $child->update(['kind' => 'parent']);
        $child->delete(); // a queued delete restores only the key, as the child's class
        $this->assertSame([2], $this->indexedIds(StiScoutUntypedChild::class));
        $this->assertSame([1, 4, 5], $this->indexedIds(StiScoutUntypedParent::class));
        $this->assertSame([StiScoutUntypedChild::class => 1, StiScoutUntypedParent::class => 3], $this->totals());
        $this->assertSame(4, $this->docCount('alpha'));
        $this->assertSame(['alpha five', 'alpha four', 'alpha one'], StiScoutUntypedParent::scoutSearch('alpha')->get()->pluck('title')->sort()->values()->all());
    }

    public static function staleWrites(): array
    {
        $cases = [];
        foreach (['in-process' => false, 'queued' => true] as $mode => $async) {
            foreach (['a stale child save after a demotion', 'a stale child delete after a demotion', 'IndexModelJob named with the child after a raw demotion',
                'a delete through a parent instance read without the type column', 'IndexModelJob named with the parent after a query-builder delete',
                'a stale child save after a move to a sibling'] as $case) {
                $cases["{$case}, {$mode}"] = [$case, $async];
            }
        }

        return $cases;
    }

    /**
     * VB-1, R13-L5. A save or delete names its types from the instance, and IndexModelJob from the
     * class it is given, not from the row: a type whose scoped re-read missed the key only dropped
     * it. A stale child instance saved after another request moved the row left its new text in no
     * index (the new kind kept the old), deleted, it left the row in the new kind's index (counted
     * and suggested); a parent instance read without the type column deleted a child row out of
     * no index; and the documented per-row repair, IndexModelJob, named with the child after a mass
     * type change filed the row nowhere, named with the parent after a query-builder delete left
     * the child's entry. A key the re-read misses is now read through the root without scopes: a
     * live row is filed under its kind, a gone one leaves every type that holds it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('staleWrites')]
    public function test_a_write_whose_type_misses_the_row_files_it_by_the_row(string $case, bool $async): void
    {
        $this->rebuild(StiParent::class);
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => $async]);
        $job = fn (string $class, int $id) => (new IndexModelJob($class, $id))->handle(app(IndexManager::class));

        match ($case) {
            'a stale child save after a demotion' => (function () {
                $stale = StiChild::query()->find(1);
                StiChild::query()->find(1)->update(['kind' => 'parent']); // another request
                $stale->update(['title' => 'omega one']);                  // the UPDATE sets the title alone

                $this->assertSame(['kind' => 'parent', 'title' => 'omega one'], (array) DB::table('sti_items')->where('id', 1)->first(['kind', 'title']));
                $this->assertSame([3], $this->indexedIds(StiChild::class));
                $this->assertSame([1, 2, 4, 5], $this->indexedIds(StiParent::class));
                $this->assertSame(['omega one'], $this->indexTitles(StiParent::class, 'omega'), 'found by its new text');
                $this->assertSame(['alpha five', 'alpha four', 'alpha two'], $this->indexTitles(StiParent::class, 'alpha'), 'not by its old one');
            })(),
            'a stale child delete after a demotion' => (function () {
                $stale = StiChild::query()->find(3);
                StiChild::query()->find(3)->update(['kind' => 'parent']);
                $stale->delete();

                $this->assertNull(DB::table('sti_items')->where('id', 3)->first());
                $this->assertSame([1], $this->indexedIds(StiChild::class));
                $this->assertSame([2, 4, 5], $this->indexedIds(StiParent::class));
                $this->assertSame(0, StiParent::search('three')->useInvertedIndex()->typoTolerance(0)->count());
                $this->assertSame([], StiParent::search('thre')->suggestFrom('index')->suggest());
            })(),
            'IndexModelJob named with the child after a raw demotion' => (function () use ($job) {
                DB::table('sti_items')->where('id', 1)->update(['kind' => 'parent', 'title' => 'omega one']);
                $job(StiChild::class, 1);

                $this->assertSame([3], $this->indexedIds(StiChild::class));
                $this->assertSame([1, 2, 4, 5], $this->indexedIds(StiParent::class));
                $this->assertSame(['omega one'], $this->indexTitles(StiParent::class, 'omega'));
            })(),
            'a delete through a parent instance read without the type column' => (function () {
                $row = StiParent::query()->select(['id', 'title'])->find(1);
                $this->assertNotInstanceOf(StiChild::class, $row);
                $row->delete();

                $this->assertSame([3], $this->indexedIds(StiChild::class));
                $this->assertSame([2, 4, 5], $this->indexedIds(StiParent::class));
            })(),
            'IndexModelJob named with the parent after a query-builder delete' => (function () use ($job) {
                StiParent::query()->whereKey(1)->delete(); // no model event: the README's per-row repair follows
                $job(StiParent::class, 1);

                $this->assertSame([3], $this->indexedIds(StiChild::class));
                $this->assertSame([2, 4, 5], $this->indexedIds(StiParent::class));
            })(),
            'a stale child save after a move to a sibling' => (function () {
                $stale = StiChild::query()->find(1);
                StiParent::query()->find(1)->update(['kind' => 'sibling']); // hydrated as the child, moved by another request
                $this->assertSame([1], $this->indexedIds(StiSibling::class));
                $stale->update(['title' => 'omega one']);

                $this->assertSame([3], $this->indexedIds(StiChild::class));
                $this->assertSame([1], $this->indexedIds(StiSibling::class));
                $this->assertSame(['omega one'], $this->indexTitles(StiSibling::class, 'omega'));
                $this->assertSame([], $this->indexTitles(StiSibling::class, 'alpha'));
            })(),
        };

        // Each row once, in one index: the totals and every word's doc_count match the table.
        $this->assertSame((int) DB::table('sti_items')->count(), array_sum($this->totals()));
        $this->assertSame((int) DB::table('sti_items')->where('title', 'like', 'alpha%')->count(), $this->docCount('alpha'));
        $this->assertSame((int) DB::table('sti_items')->where('title', 'like', 'omega%')->count(), $this->docCount('omega'));
    }

    /** R13-L5 (15). rebuild --fresh of the parent flushes its own type: a child row deleted without events stayed in the child's index. */
    public function test_a_fresh_rebuild_of_the_parent_takes_a_deleted_row_out_of_every_kind(): void
    {
        $this->rebuild(StiParent::class);
        $this->rebuild(StiAlphaView::class);
        StiParent::query()->whereKey([1, 2])->delete(); // a child row and a parent row, no model event

        $this->rebuild(StiParent::class);

        $this->assertSame([3], $this->indexedIds(StiChild::class));
        $this->assertSame([4, 5], $this->indexedIds(StiParent::class));
        $this->assertSame([4, 5], $this->indexedIds(StiAlphaView::class), 'a subclass that is no kind keeps its own index, less the deleted row');
        $this->assertSame([StiAlphaView::class => 2, StiChild::class => 1, StiParent::class => 2], $this->totals());
        $this->assertSame(0, $this->docCount('one'));
        $this->assertSame(5, $this->docCount('alpha'));
    }

    /** The re-read of a missed key is for a class that hydrates other classes only: a plain model's write reads its table once. */
    public function test_only_a_class_that_hydrates_other_classes_rereads_a_missed_key(): void
    {
        $sql = null;
        DB::listen(function ($query) use (&$sql) {
            if ($sql !== null) {
                $sql[] = $query->sql;
            }
        });
        $reads = function (string $class) use (&$sql): int {
            $sql = [];
            (new IndexModelJob($class, 99))->handle(app(IndexManager::class)); // no such row
            $read = count(array_filter($sql, fn (string $statement) => stripos($statement, 'select') === 0 && str_contains($statement, 'sti_items')));
            $sql  = null;

            return $read;
        };

        $this->assertSame(1, $reads(StiCar::class), 'no newFromBuilder() of its own: the write\'s re-read alone');
        $this->assertSame(2, $reads(StiChild::class), 'the re-read, then the root\'s');
    }
}
