<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Builder as ScoutBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

class UuidNote extends Model
{
    use HasUuids, Searchable;

    protected $table   = 'uuid_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['body' => 10]];
}

class UlidNote extends Model
{
    use HasUlids, Searchable;

    protected $table   = 'ulid_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['body' => 10]];
}

class ScoutUuidNote extends Model
{
    use HasUuids, \Laravel\Scout\Searchable;

    protected $table   = 'uuid_notes';
    protected $guarded = [];
    public $timestamps = false;

    /** IndexManager reads this; Scout's trait does not provide it. */
    public function getSearchableColumns(): array
    {
        return ['body'];
    }
}

class ScoutUlidNote extends Model
{
    use HasUlids, \Laravel\Scout\Searchable;

    protected $table   = 'ulid_notes';
    protected $guarded = [];
    public $timestamps = false;

    /** IndexManager reads this; Scout's trait does not provide it. */
    public function getSearchableColumns(): array
    {
        return ['body'];
    }
}

class StringKeyIndexingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('uuid_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('body');
        });
        Schema::create('ulid_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('body');
        });

        foreach ([UuidNote::class, UlidNote::class] as $class) {
            $class::create(['body' => 'alpha widget']);
            $class::create(['body' => 'beta widget']);
            $class::create(['body' => 'gamma gadget']);
            app(IndexManager::class)->indexBatch($class::all());
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('uuid_notes');
        Schema::dropIfExists('ulid_notes');
        parent::tearDown();
    }

    /** @return array<int, array{0: class-string<Model>}> */
    public static function keyedModels(): array
    {
        return ['uuid' => [UuidNote::class], 'ulid' => [UlidNote::class]];
    }

    /** @return array<string, array{0: class-string<Model>}> */
    public static function scoutKeyedModels(): array
    {
        return ['uuid' => [ScoutUuidNote::class], 'ulid' => [ScoutUlidNote::class]];
    }

    #[DataProvider('keyedModels')]
    public function test_index_search_paginate_and_count_work_with_string_keys(string $class): void
    {
        $results = $class::search('widget')->useInvertedIndex()->typoTolerance(0)->get();

        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf($class, $results);
        $this->assertSame(2, $class::search('widget')->useInvertedIndex()->typoTolerance(0)->count());

        $page = $class::search('widget')->useInvertedIndex()->typoTolerance(0)->paginate(1);
        $this->assertSame(2, $page->total());
        $this->assertCount(1, $page->items());
    }

    #[DataProvider('keyedModels')]
    public function test_ranking_keys_are_the_string_primary_keys(string $class): void
    {
        $ranked = app(Bm25Scorer::class)->rank(['widget'], $class);

        foreach (array_keys($ranked) as $key) {
            $this->assertIsString($key);
            $this->assertNotNull($class::find($key));
        }
    }

    #[DataProvider('keyedModels')]
    public function test_remove_and_filter_use_the_string_key(string $class): void
    {
        $gone = $class::where('body', 'alpha widget')->first();
        app(IndexManager::class)->removeFromIndex($class, $gone->getKey());

        $this->assertSame(0, DB::table('fuzzy_index_postings')->where('model_type', $class)->where('model_id', $gone->getKey())->count());
        $this->assertCount(1, $class::search('widget')->useInvertedIndex()->typoTolerance(0)->get());

        $keep = $class::where('body', 'beta widget')->first();
        $this->assertCount(1, $class::search('widget')->useInvertedIndex()->typoTolerance(0)->filter('id', $keep->getKey())->get());
    }

    #[DataProvider('keyedModels')]
    public function test_stable_ranking_orders_ties_by_the_string_key(string $class): void
    {
        $twice = $class::search('widget')->useInvertedIndex()->typoTolerance(0)->stableRanking()->get()->pluck('id')->all();
        $again = $class::search('widget')->useInvertedIndex()->typoTolerance(0)->stableRanking()->get()->pluck('id')->all();

        $this->assertSame($twice, $again);
    }

    #[DataProvider('keyedModels')]
    public function test_rebuild_command_chunks_by_the_string_key(string $class): void
    {
        DB::table('fuzzy_index_postings')->where('model_type', $class)->delete();
        DB::table('fuzzy_index_documents')->where('model_type', $class)->delete();

        config(['fuzzy-search.indexing.chunk_size' => 2]);
        Artisan::call('fuzzy-search:rebuild', ['model' => $class]);

        $this->assertSame(3, DB::table('fuzzy_index_documents')->where('model_type', $class)->count());
    }

    #[DataProvider('scoutKeyedModels')]
    public function test_scout_engine_searches_paginates_and_maps_string_keys(string $class): void
    {
        app(IndexManager::class)->indexBatch($class::all());

        $engine  = app(FuzzySearchEngine::class);
        $model   = new $class;
        $builder = new ScoutBuilder($model, 'widget');

        $results = $engine->search($builder);
        $ids     = $engine->mapIds($results)->all();
        $this->assertCount(2, $ids);
        $this->assertContainsOnlyString($ids);

        $mapped = $engine->map($builder, $results, $model);
        $this->assertCount(2, $mapped);
        $this->assertContainsOnlyInstancesOf($class, $mapped);

        $page = $engine->paginate($builder, 1, 1);
        $this->assertSame(2, $page['total']);
    }
}
