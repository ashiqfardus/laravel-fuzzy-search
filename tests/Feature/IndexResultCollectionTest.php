<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class IndexResultUsers extends EloquentCollection
{
}

/** A model with its own collection (newCollection()), as an app overrides it. */
class IndexResultCustomUser extends Model
{
    use Searchable;

    protected $table = 'users';

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5]];

    public function newCollection(array $models = []): IndexResultUsers
    {
        return new IndexResultUsers($models);
    }

    public function twin()
    {
        return $this->hasOne(static::class, 'id');
    }
}

class IndexResultPlainUser extends Model
{
    use Searchable;

    protected $table = 'users';

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5]];

    public function twin()
    {
        return $this->hasOne(static::class, 'id');
    }
}

/**
 * VC-4. An index search's page was built by mapping the models to their scores, which Eloquent
 * turns into a base collection: an uncached useInvertedIndex()->get() returned a
 * Support\Collection of models, where the LIKE path, the same search's cache hit and 2.0.1 return
 * the model's own collection. load(), modelKeys() and the like threw on the miss only, and only(),
 * find() and contains() meant something else there. The index path now serves the model's
 * newCollection(), empty pages included, as the LIKE path does.
 */
class IndexResultCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::flush();
        app(IndexManager::class)->indexBatch(IndexResultCustomUser::query()->get());
        app(IndexManager::class)->indexBatch(IndexResultPlainUser::query()->get());
    }

    public static function models(): array
    {
        return [
            'a model with its own collection' => [IndexResultCustomUser::class, IndexResultUsers::class],
            'a plain model'                   => [IndexResultPlainUser::class, EloquentCollection::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function test_the_index_path_serves_the_models_collection_as_the_like_path_does(string $model, string $collection): void
    {
        $paths = [
            'index'         => fn (string $term) => $model::search($term)->useInvertedIndex(),
            'index ordered' => fn (string $term) => $model::search($term)->useInvertedIndex()->orderBy('name'),
            'index window'  => fn (string $term) => $model::search($term)->useInvertedIndex()->boostRecent(),
            'like'          => fn (string $term) => $model::search($term)->using('simple'),
        ];

        foreach ($paths as $path => $search) {
            foreach (['john' => true, 'zzqqxx' => false] as $term => $matches) {
                $label = "{$path}, {$term}";

                $rows = $search($term)->get();
                $this->assertSame($collection, $rows::class, "{$label}: get()");
                $this->assertSame($matches, $rows->isNotEmpty(), $label);
                $this->assertSame($collection, $search($term)->take(1)->get()::class, "{$label}: take(1)->get()");
                $this->assertSame($collection, $search($term)->paginate(2)->getCollection()::class, "{$label}: paginate()");
                $this->assertSame($collection, $search($term)->paginate(2, page: 9)->getCollection()::class, "{$label}: a page past the last");
                $this->assertSame($collection, $search($term)->simplePaginate(2)->getCollection()::class, "{$label}: simplePaginate()");

                $miss = $search($term)->cache(60, "vc4-{$model}-{$label}")->get();
                $hit  = $search($term)->cache(60, "vc4-{$model}-{$label}")->get();
                $this->assertSame($collection, $miss::class, "{$label}: a cache miss");
                $this->assertSame($collection, $hit::class, "{$label}: the cache hit");
                $this->assertSame($miss->modelKeys(), $hit->modelKeys(), $label);
            }

            $first = $search('john')->first();
            $this->assertInstanceOf($model, $first, "{$path}: first()");

            // An Eloquent collection's own methods, on the uncached search.
            $rows = $search('john')->get();
            $rows->load('twin');
            $this->assertTrue($rows->every(fn (Model $row) => $row->relationLoaded('twin') && $row->twin->is($row)), "{$path}: load()");
            $this->assertSame($rows->first()->getKey(), $rows->find($rows->first()->getKey())?->getKey(), "{$path}: find() by key");
        }
    }

    /** useInvertedIndex(Model::class) on a query builder serves models, so it serves them in the model's collection too. */
    public function test_an_index_search_of_a_query_builder_serves_the_models_collection(): void
    {
        $search = fn () => (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('john')->searchIn(['name', 'email'])->useInvertedIndex(IndexResultCustomUser::class);

        $this->assertSame(IndexResultUsers::class, $search()->get()::class);
        $this->assertSame(IndexResultUsers::class, $search()->orderBy('name')->get()::class);
        $this->assertSame(IndexResultUsers::class, $search()->cache(60, 'vc4-qb')->get()::class);
        $this->assertSame(IndexResultUsers::class, $search()->cache(60, 'vc4-qb')->get()::class);

        // Its LIKE search serves rows, not models: a base collection, as before.
        $like = (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('john')->searchIn(['name', 'email'])->using('simple')->get();
        $this->assertSame(\Illuminate\Support\Collection::class, $like::class);
        $this->assertInstanceOf(\stdClass::class, $like->first());
    }
}
