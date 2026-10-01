<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AfterQueryItem extends Model
{
    use Searchable;

    protected $table   = 'after_query_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/**
 * SA-6 (round 11). The index path reads a page's rows as get() does, then runs Eloquent's
 * afterQuery() callbacks (Laravel 11+) on the models, and kept only the instances it had hydrated
 * that a callback returned. A callback that returns other instances, legal in get(), which serves
 * what the callback returns (->map->withoutRelations(), which clones, or replicate()), emptied
 * get(), the ordered page and a cache hit, and paginate() reported a total over an empty page. A
 * returned model is now kept under its own key, when that is one of the page's; a returned model
 * without one is dropped, as before.
 */
class IndexAfterQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(Builder::class, 'afterQuery')) {
            $this->markTestSkipped('afterQuery() is Laravel 11+.');
        }

        Schema::dropIfExists('after_query_items');
        Schema::create('after_query_items', function ($table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('rank');
        });
        DB::table('after_query_items')->insert([
            ['title' => 'alpha one', 'rank' => 3],
            ['title' => 'alpha two', 'rank' => 1],
            ['title' => 'alpha three', 'rank' => 2],
            ['title' => 'beta', 'rank' => 4],
        ]);
        app(IndexManager::class)->indexBatch(AfterQueryItem::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('after_query_items');

        parent::tearDown();
    }

    /** @return array<string, array{\Closure, string[], string[]}> callback => [it, what get() serves on the table, what the index path serves], by rank */
    private function callbacks(): array
    {
        $all = ['alpha two', 'alpha three', 'alpha one'];

        return [
            'mutates in place'   => [fn ($models) => $models->each(fn ($model) => $model->setAttribute('tag', 'x')), $all, $all],
            'filters'            => [fn ($models) => $models->filter(fn ($model) => $model->title !== 'alpha three'), ['alpha two', 'alpha one'], ['alpha two', 'alpha one']],
            'withoutRelations()' => [fn ($models) => $models->map->withoutRelations(), $all, $all],
            'replicate()'        => [fn ($models) => $models->map(fn ($model) => tap($model->replicate(), fn ($copy) => $copy->setAttribute('id', $model->id))), $all, $all],
            'clones a filtered'  => [fn ($models) => $models->filter(fn ($model) => $model->title !== 'alpha one')->map(fn ($model) => clone $model), ['alpha two', 'alpha three'], ['alpha two', 'alpha three']],
            // A model without a key cannot be placed among the ranked ids: the index path drops it.
            'drops the key'      => [fn ($models) => $models->map(fn ($model) => $model->replicate()), $all, []],
        ];
    }

    public function test_an_after_query_callback_that_returns_other_instances_is_served_on_every_index_terminal(): void
    {
        config(['cache.default' => 'array']);

        // The page's rows are read bm25.candidate_chunk ids at a time: one read, and one per id.
        foreach ([200, 1] as $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);

            foreach ($this->callbacks() as $label => [$callback, $plain, $titles]) {
                $label  = "{$label}, chunk {$chunk}";
                $search = fn () => AfterQueryItem::searchOn(AfterQueryItem::query()->afterQuery($callback), 'alpha')->typoTolerance(0)->useInvertedIndex();
                $sorted = $titles;
                sort($sorted);

                $this->assertSame($plain, AfterQueryItem::query()->where('title', 'like', 'alpha%')->orderBy('rank')->afterQuery($callback)->get()->pluck('title')->all(), "{$label}: get() on the table");

                $this->assertSame($sorted, $search()->get()->pluck('title')->sort()->values()->all(), "{$label}: get");
                $this->assertSame($titles, $search()->orderBy('rank')->get()->pluck('title')->all(), "{$label}: the ordered page");
                $this->assertSame($titles[0] ?? null, $search()->orderBy('rank')->first()?->title, "{$label}: first");
                $this->assertSame($titles, $search()->orderBy('rank')->simplePaginate(5)->pluck('title')->all(), "{$label}: simplePaginate");
                $this->assertSame($titles, collect($search()->orderBy('rank')->paginate(5)->items())->pluck('title')->all(), "{$label}: the ordered paginate");

                $page = $search()->paginate(5);
                $this->assertSame($sorted, collect($page->items())->pluck('title')->sort()->values()->all(), "{$label}: paginate's page");
                $this->assertSame(3, $page->total(), "{$label}: paginate's total counts the matches, as count() does");
                $this->assertSame(3, $search()->count(), "{$label}: count");

                // A cache hit re-reads the rows by key, through the same read.
                \Illuminate\Support\Facades\Cache::flush();
                $this->assertSame($sorted, $search()->cache(60)->get()->pluck('title')->sort()->values()->all(), "{$label}: a cache miss");
                $this->assertSame($sorted, $search()->cache(60)->get()->pluck('title')->sort()->values()->all(), "{$label}: a cache hit");
            }
        }
    }

    public function test_a_returned_clone_is_served_under_the_key_it_was_read_under(): void
    {
        $search = AfterQueryItem::searchOn(AfterQueryItem::query()->afterQuery(fn ($models) => $models->map->withoutRelations()->reverse()), 'alpha')
            ->typoTolerance(0)->useInvertedIndex()->orderBy('rank');

        $served = $search->get();
        $this->assertSame(['alpha two', 'alpha three', 'alpha one'], $served->pluck('title')->all(), 'the order the search chose, not the callback\'s');
        $this->assertSame([2, 3, 1], $served->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['alpha two' => 1, 'alpha three' => 2, 'alpha one' => 3], $served->mapWithKeys(fn ($model) => [$model->title => (int) $model->rank])->all(), 'each row its own attributes');
    }
}
