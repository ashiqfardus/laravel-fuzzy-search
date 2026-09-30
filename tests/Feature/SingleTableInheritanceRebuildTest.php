<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

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

/**
 * RC-3. indexBatch() took the model type from a chunk's first model, and re-read the chunk's keys
 * through that class's query: a rebuild of an STI parent whose chunk began with a child indexed the
 * chunk under the child's class, and the child's global scope dropped every parent row of it. The
 * rebuild (sync and --async) now indexes every row under the class it was asked to rebuild.
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
        // A child first in every chunk of two: the chunk's first model is a StiChild.
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

    private function assertEveryRowIndexedUnderTheParent(): void
    {
        $this->assertSame([StiParent::class => 5], DB::table('fuzzy_index_documents')->pluck('model_type')->countBy()->all());
        $this->assertSame(5, (int) DB::table('fuzzy_index_meta')->where('model_type', StiParent::class)->value('total_docs'));
        $this->assertCount(5, StiParent::search('alpha')->useInvertedIndex()->typoTolerance(0)->get());
    }

    public function test_a_rebuild_of_the_parent_indexes_every_row_under_it(): void
    {
        $this->artisan('fuzzy-search:rebuild', ['model' => StiParent::class, '--fresh' => true])->assertExitCode(0);

        $this->assertEveryRowIndexedUnderTheParent();
    }

    public function test_an_async_rebuild_of_the_parent_indexes_every_row_under_it(): void
    {
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

        $this->artisan('fuzzy-search:rebuild', ['model' => StiParent::class, '--fresh' => true, '--async' => true])->assertExitCode(0);

        $this->assertEveryRowIndexedUnderTheParent();
    }

    /**
     * The Scout engine has no class to be asked for: a collection that mixes classes (Scout's import
     * of the parent) is indexed a class at a time, each model under its own class, as delete()
     * removes it; under the first model's class, a deleted parent row stayed in the index.
     */
    public function test_the_scout_engine_indexes_each_model_under_its_own_class(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->app->make(\Laravel\Scout\EngineManager::class)->engine('fuzzy-search');
        $models = StiParent::query()->orderBy('id')->get();
        $engine->update($models);

        $this->assertSame([StiChild::class => 2, StiParent::class => 3], DB::table('fuzzy_index_documents')->pluck('model_type')->countBy()->sortKeys()->all());

        $engine->delete($models->where('kind', 'parent'));
        $this->assertSame([StiChild::class => 2], DB::table('fuzzy_index_documents')->pluck('model_type')->countBy()->all());
        $this->assertSame(0, (int) DB::table('fuzzy_index_meta')->where('model_type', StiParent::class)->value('total_docs'));
    }
}
