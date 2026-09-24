<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Scout\FuzzySearchEngine;
use Illuminate\Database\Eloquent\Model;

class ScoutEngineTest extends TestCase
{
    public function test_scout_engine_registers_when_scout_is_installed(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->app->make(\Laravel\Scout\EngineManager::class)->engine('fuzzy-search');
        $this->assertInstanceOf(FuzzySearchEngine::class, $engine);
    }

    /**
     * Scout 11.6+ hybrid() asks for a full-text + semantic ranking this engine cannot produce.
     * It must fail loudly rather than quietly return a plain BM25 page (semantic() alone is
     * already rejected by Scout itself, which checks SupportsSemanticSearch).
     */
    public function test_hybrid_search_is_rejected_instead_of_silently_running_bm25(): void
    {
        if (!class_exists(\Laravel\Scout\Builder::class) || !method_exists(\Laravel\Scout\Builder::class, 'hybrid')) {
            $this->markTestSkipped('This Scout version has no hybrid().');
        }

        $engine = $this->app->make(\Laravel\Scout\EngineManager::class)->engine('fuzzy-search');
        $builder = (new \Laravel\Scout\Builder(new ScoutIndexedUser, 'john'))->hybrid();

        foreach (['search' => fn () => $engine->search($builder), 'paginate' => fn () => $engine->paginate($builder, 15, 1)] as $method => $call) {
            try {
                $call();
                $this->fail("{$method}() ran a hybrid search as plain BM25");
            } catch (\Laravel\Scout\Exceptions\NotSupportedException $e) {
                $this->assertStringContainsString('hybrid', $e->getMessage());
            }
        }
    }

    public function test_scout_engine_indexes_and_retrieves(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $manager = new IndexManager(new WhitespaceTokenizer(), new NullStemmer());
        $scorer  = new Bm25Scorer();
        $engine  = new FuzzySearchEngine($manager, $scorer);

        $id = $this->app['db']->table('users')->insertGetId([
            'name' => 'scout laravel test', 'email' => 'scout' . uniqid() . '@test.com',
            'created_at' => now(), 'updated_at' => now()
        ]);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $instance = $model::find($id);
        $engine->update(collect([$instance]));

        $builder = new \Laravel\Scout\Builder($instance, 'laravel');
        $results = $engine->search($builder);

        $this->assertGreaterThan(0, $results['total']);
        $ids = $engine->mapIds($results)->toArray();
        $this->assertContains($id, $ids);
    }

    // -------------------------------------------------------------------------
    // Helpers shared by the new gap-filling tests
    // -------------------------------------------------------------------------

    private function makeEngine(): FuzzySearchEngine
    {
        return new FuzzySearchEngine(
            new IndexManager(new WhitespaceTokenizer(), new NullStemmer()),
            new Bm25Scorer()
        );
    }

    /** Insert a row into users and return [id, modelInstance]. */
    private function insertUser(string $name): array
    {
        $id = $this->app['db']->table('users')->insertGetId([
            'name'       => $name,
            'email'      => 'engine_' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $model = new class extends Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table    = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        return [$id, $model::find($id)];
    }

    // -------------------------------------------------------------------------
    // Column weights (M7): Scout must rank exactly like useInvertedIndex()
    // -------------------------------------------------------------------------

    public function test_scout_search_and_paginate_rank_with_the_model_column_weights(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        // Product weights: title 10, description 5. One hit each and identical unweighted
        // scores, so only the weight decides — and the description hit is inserted first, so
        // an unweighted ranking returns the rows the other way round.
        Product::create(['title' => 'Plain device', 'description' => 'A quantum gadget.', 'price' => 10]);
        Product::create(['title' => 'Quantum widget', 'description' => 'A small device.', 'price' => 10]);
        app(IndexManager::class)->indexBatch(Product::all());

        $expected = Product::search('quantum')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all();
        $this->assertSame(['Quantum widget', 'Plain device'], $expected, 'baseline: the heavier column wins');

        $engine  = $this->makeEngine();
        $builder = new \Laravel\Scout\Builder(new Product(), 'quantum');

        $byId   = Product::all()->keyBy(fn ($p) => (string) $p->getKey());
        $titles = fn (array $results) => $engine->mapIds($results)
            ->map(fn ($id) => $byId[(string) $id]->title)
            ->all();

        $this->assertSame($expected, $titles($engine->search($builder)), 'Scout search() ignored the column weights');
        $this->assertSame($expected, $titles($engine->paginate($builder, 10, 1)), 'Scout paginate() ignored the column weights');
    }

    // -------------------------------------------------------------------------
    // Gap 3a — delete()
    // -------------------------------------------------------------------------

    /**
     * After update() followed by delete(), all postings for the model
     * must be removed from the index.
     */
    public function test_scout_engine_delete_removes_from_index(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine        = $this->makeEngine();
        [$id, $instance] = $this->insertUser('delete engine test');
        $modelType     = get_class($instance);

        $engine->update(collect([$instance]));

        $before = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->where('model_id', $id)
            ->count();
        $this->assertGreaterThan(0, $before, 'Postings must exist after update().');

        $engine->delete(collect([$instance]));

        $after = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->where('model_id', $id)
            ->count();
        $this->assertSame(0, $after, 'delete() must remove all postings for the model.');
    }

    // -------------------------------------------------------------------------
    // Gap 3b — flush()
    // -------------------------------------------------------------------------

    /**
     * flush() must remove every indexed document for the model's class,
     * regardless of how many were indexed.
     */
    public function test_scout_engine_flush_clears_all_for_model(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        [, $instance1] = $this->insertUser('flush engine test one');
        [, $instance2] = $this->insertUser('flush engine test two');

        $modelType = get_class($instance1);

        $engine->update(collect([$instance1, $instance2]));

        $before = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->count();
        $this->assertGreaterThan(0, $before, 'Postings must exist for both models before flush().');

        // flush() receives the model instance (engine calls $model::class internally)
        $engine->flush($instance1);

        $after = $this->app['db']->table('fuzzy_index_postings')
            ->where('model_type', $modelType)
            ->count();
        $this->assertSame(0, $after, 'flush() must clear all postings for the entire model class.');
    }

    // -------------------------------------------------------------------------
    // Gap 3c — map()
    // -------------------------------------------------------------------------

    /**
     * map() with total=0 must return an empty Eloquent collection without
     * hitting the database.
     */
    public function test_scout_engine_map_returns_empty_collection_for_zero_results(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        $modelStub = new class extends Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $builder = new \Laravel\Scout\Builder($modelStub, 'anything');

        $result = $engine->map($builder, ['results' => collect(), 'total' => 0], $modelStub);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $result);
        $this->assertCount(0, $result);
    }

    /**
     * map() with a non-empty result set must return the correct Eloquent
     * models sorted by descending score and with _score populated.
     * Uses Scout's Searchable trait so getScoutModelsByIds() is available.
     */
    public function test_scout_engine_map_returns_correct_models(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        // Insert a row directly — we need a stable class name so Scout can look it up
        $id = $this->app['db']->table('users')->insertGetId([
            'name'       => 'map engine result test',
            'email'      => 'engine_map_' . uniqid() . '@test.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Use a named class that carries Scout's Searchable (provides getScoutModelsByIds)
        $instance  = ScoutMapTestUser::find($id);
        $modelType = ScoutMapTestUser::class;

        // Build a synthetic results array (what search() returns)
        $builder = new \Laravel\Scout\Builder($instance, 'map');
        $syntheticResults = [
            'results' => collect([
                ['model_type' => $modelType, 'model_id' => $id, 'score' => 0.75],
            ]),
            'total' => 1,
        ];

        $mapped = $engine->map($builder, $syntheticResults, $instance);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $mapped);
        $this->assertCount(1, $mapped);
        $this->assertEquals($id, $mapped->first()->getKey());
        $this->assertSame(0.75, $mapped->first()->_score);
    }

    // -------------------------------------------------------------------------
    // Gap 3d — paginate()
    // -------------------------------------------------------------------------

    /**
     * paginate() must return an array with 'results' (sliced to the page)
     * and 'total' (the full unsliced count) — ready for Scout to wrap in a
     * LengthAwarePaginator via getTotalCount().
     */
    public function test_scout_engine_paginate_returns_correct_page_slice(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();

        // Index three models so there is data to paginate
        [, $instance1] = $this->insertUser('paginate engine alpha');
        [, $instance2] = $this->insertUser('paginate engine beta');
        [, $instance3] = $this->insertUser('paginate engine gamma');

        $engine->update(collect([$instance1, $instance2, $instance3]));

        $builder = new \Laravel\Scout\Builder($instance1, 'paginate engine');

        // Page 1 — 2 per page
        $page1 = $engine->paginate($builder, 2, 1);

        $this->assertArrayHasKey('results', $page1);
        $this->assertArrayHasKey('total', $page1);
        $this->assertLessThanOrEqual(2, $page1['results']->count(),
            'Page 1 must contain at most 2 results.');

        // getTotalCount() must reflect the unsliced total
        $total = $engine->getTotalCount($page1);
        $this->assertGreaterThanOrEqual(1, $total,
            'getTotalCount() must return the total number of matching documents.');
    }

    // -------------------------------------------------------------------------
    // Constraints (where / whereIn / query()) must be applied before the ranking
    // is cut to the requested page, not after — otherwise a selective constraint
    // returns a short or empty page while matches exist further down the ranking.
    // -------------------------------------------------------------------------

    /** Three users ranked widget×3 > widget×2 > widget×1; returns [[id, model], ...] lowest rank first. */
    private function seedRankedWidgets(FuzzySearchEngine $engine): array
    {
        $rows = [
            $this->insertUser('widget'),
            $this->insertUser('widget widget'),
            $this->insertUser('widget widget widget'),
        ];
        $engine->update(collect(array_column($rows, 1)));

        return $rows;
    }

    public function test_scout_search_applies_builder_wheres_before_cutting_the_ranking(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();
        [[$lowestId, $lowest]] = $this->seedRankedWidgets($engine);

        $builder = (new \Laravel\Scout\Builder($lowest, 'widget'))->where('email', $lowest->email)->take(1);

        $this->assertSame([$lowestId], $engine->mapIds($engine->search($builder))->all());
    }

    /**
     * Scout 10 stored $builder->wheres as a flat [field => value] map; Scout 11+ stores a
     * list of ['field', 'operator', 'value']. composer.json allows laravel/scout ^10|^11|^12,
     * so constrainedQuery() must still handle the old shape even though every installed CI
     * job resolves Scout 11 and never exercises this branch otherwise.
     */
    public function test_scout_search_applies_scout10_shaped_wheres_before_cutting_the_ranking(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();
        [[$lowestId, $lowest]] = $this->seedRankedWidgets($engine);

        $builder = (new \Laravel\Scout\Builder($lowest, 'widget'))->take(1);
        $builder->wheres = ['email' => $lowest->email];

        $this->assertSame([$lowestId], $engine->mapIds($engine->search($builder))->all());
    }

    public function test_scout_search_applies_where_in_and_query_callback_constraints(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();
        [[$lowestId, $lowest], [$middleId]] = $this->seedRankedWidgets($engine);

        $whereIn = (new \Laravel\Scout\Builder($lowest, 'widget'))->whereIn('id', [$lowestId, $middleId])->take(1);
        $this->assertSame([$middleId], $engine->mapIds($engine->search($whereIn))->all());

        $callback = (new \Laravel\Scout\Builder($lowest, 'widget'))
            ->query(fn ($query) => $query->where('email', $lowest->email))
            ->take(1);
        $this->assertSame([$lowestId], $engine->mapIds($engine->search($callback))->all());
    }

    public function test_scout_search_total_is_the_match_count_not_the_page_size(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();
        [[$lowestId, $lowest], [$middleId]] = $this->seedRankedWidgets($engine);

        $unconstrained = $engine->search((new \Laravel\Scout\Builder($lowest, 'widget'))->take(1));
        $this->assertCount(1, $unconstrained['results']);
        $this->assertSame(3, $engine->getTotalCount($unconstrained));

        $constrained = $engine->search((new \Laravel\Scout\Builder($lowest, 'widget'))->whereIn('id', [$lowestId, $middleId])->take(1));
        $this->assertCount(1, $constrained['results']);
        $this->assertSame(2, $engine->getTotalCount($constrained));
    }

    /**
     * `scout:delete-index {name}` hands deleteIndex() an index NAME — the model's indexableAs()
     * (prefix + table by default) — while the inverted index is keyed by model class.
     */
    public function test_delete_index_flushes_the_models_whose_scout_index_has_that_name(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.prefix' => 'test_']);
        $engine = $this->makeEngine();

        $engine->update(ScoutIndexedUser::all());
        $engine->update(Product::all());
        $postings = fn (string $class) => $this->app['db']->table('fuzzy_index_postings')->where('model_type', $class)->count();
        $this->assertGreaterThan(0, $postings(ScoutIndexedUser::class));
        $productPostings = $postings(Product::class);
        $this->assertGreaterThan(0, $productPostings);

        $engine->deleteIndex('test_products'); // Product has no Scout index name: untouched
        $engine->deleteIndex('users');         // not the prefixed name Scout would pass
        $this->assertGreaterThan(0, $postings(ScoutIndexedUser::class));

        $engine->deleteIndex('test_users');

        $this->assertSame(0, $postings(ScoutIndexedUser::class));
        $this->assertSame(0, $this->app['db']->table('fuzzy_index_meta')->where('model_type', ScoutIndexedUser::class)->count());
        $this->assertSame($productPostings, $postings(Product::class));
    }

    // -------------------------------------------------------------------------
    // orderBy(): an explicit order replaces the relevance order, as on Scout's database engine
    // -------------------------------------------------------------------------

    /**
     * Three matches whose relevance, name and id orders all differ. Inserted in this order, so
     * by id: top ("widget" 3×), bottom (1×), middle (2×); relevance is top > middle > bottom.
     *
     * @return array{int, int, int} the ids of the top, bottom and middle match
     */
    private function seedOrderableWidgets(): array
    {
        $ids = [];
        foreach (['widget widget widget one', 'widget beta', 'widget widget gamma'] as $name) {
            $ids[] = $this->app['db']->table('users')->insertGetId([
                'name' => $name, 'email' => 'order_' . uniqid() . '@test.com', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->makeEngine()->update(ScoutIndexedUser::whereIn('id', $ids)->get());
        config(['scout.driver' => 'fuzzy-search']);

        return $ids;
    }

    private function scoutBuilder(string $query = 'widget'): \Laravel\Scout\Builder
    {
        return new \Laravel\Scout\Builder(new ScoutIndexedUser, $query);
    }

    /** @return array<int> the ids of a Scout result, a paginator's page or a collection, in order */
    private function resultIds(iterable $results): array
    {
        return collect($results instanceof \Illuminate\Contracts\Pagination\Paginator ? $results->items() : $results)
            ->map(fn ($model) => (int) $model->getKey())
            ->all();
    }

    public function test_scout_order_by_name_replaces_the_relevance_order_on_get_and_every_paginator(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        [$top, $bottom, $middle] = $this->seedOrderableWidgets();

        $this->assertSame([$top, $middle, $bottom], $this->resultIds($this->scoutBuilder()->get()), 'baseline: relevance order');

        $byName = [$bottom, $middle, $top]; // widget beta < widget widget gamma < widget widget widget one

        $this->assertSame($byName, $this->resultIds($this->scoutBuilder()->orderBy('name')->get()));
        $this->assertSame([$bottom, $middle], $this->resultIds($this->scoutBuilder()->orderBy('name')->take(2)->get()));
        $this->assertSame($bottom, (int) $this->scoutBuilder()->orderBy('name')->first()->getKey());

        $page1 = $this->scoutBuilder()->orderBy('name')->paginate(2, 'page', 1);
        $page2 = $this->scoutBuilder()->orderBy('name')->paginate(2, 'page', 2);
        $this->assertSame([$bottom, $middle], $this->resultIds($page1));
        $this->assertSame([$top], $this->resultIds($page2));
        $this->assertSame(3, $page1->total());

        $simple = $this->scoutBuilder()->orderBy('name')->simplePaginate(2, 'page', 1);
        $this->assertSame([$bottom, $middle], $this->resultIds($simple));
        $this->assertTrue($simple->hasMorePages());
        $this->assertSame([$top], $this->resultIds($this->scoutBuilder()->orderBy('name')->simplePaginate(2, 'page', 2)));
    }

    public function test_scout_order_by_id_desc_replaces_the_relevance_order_on_paginate(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        [$top, $bottom, $middle] = $this->seedOrderableWidgets();

        $page1 = $this->scoutBuilder()->orderBy('id', 'desc')->paginate(2, 'page', 1);
        $page2 = $this->scoutBuilder()->orderBy('id', 'desc')->paginate(2, 'page', 2);

        $this->assertSame([$middle, $bottom], $this->resultIds($page1));
        $this->assertSame([$top], $this->resultIds($page2));
        $this->assertSame(3, $page1->total());
        $this->assertSame([$middle, $bottom, $top], $this->resultIds($this->scoutBuilder()->orderBy('id', 'desc')->get()));
    }

    public function test_scout_order_by_applies_after_the_builder_constraints(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        [$top, $bottom, $middle] = $this->seedOrderableWidgets();

        $page = $this->scoutBuilder()->whereIn('id', [$top, $bottom])->orderBy('name')->paginate(1, 'page', 1);
        $this->assertSame([$bottom], $this->resultIds($page));
        $this->assertSame(2, $page->total());

        $callback = $this->scoutBuilder()->query(fn ($query) => $query->where('id', '!=', $bottom))->orderBy('name')->get();
        $this->assertSame([$middle, $top], $this->resultIds($callback));
    }

    /**
     * More matches than one bm25.candidate_chunk: binding every ranked id could pass SQL Server's
     * 2,100-parameter limit, so the order must hold without them.
     */
    public function test_scout_order_by_holds_when_the_matches_exceed_one_candidate_chunk(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        [$top, $bottom, $middle] = $this->seedOrderableWidgets();
        config(['fuzzy-search.bm25.candidate_chunk' => 2]);

        $this->assertSame([$bottom, $middle, $top], $this->resultIds($this->scoutBuilder()->orderBy('name')->get()));
        $this->assertSame([$top], $this->resultIds($this->scoutBuilder()->orderBy('name')->paginate(2, 'page', 2)));
        $this->assertSame(
            [$middle, $top],
            $this->resultIds($this->scoutBuilder()->whereIn('id', [$top, $middle])->orderBy('name')->paginate(2, 'page', 1))
        );
    }

    public function test_scout_order_by_rejects_a_column_that_is_not_an_identifier(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedOrderableWidgets();
        $engine = $this->makeEngine();

        foreach (['name desc, (select 1)', 'name) --', 'data->"$.x"'] as $column) {
            foreach (['search' => fn ($b) => $engine->search($b), 'paginate' => fn ($b) => $engine->paginate($b, 15, 1)] as $method => $call) {
                try {
                    $call($this->scoutBuilder()->orderBy($column));
                    $this->fail("{$method}() ordered by [{$column}]");
                } catch (\InvalidArgumentException $e) {
                    $this->assertStringContainsString('Invalid column name', $e->getMessage());
                }
            }
        }
    }

    public function test_scout_order_by_breaks_ties_by_key_descending(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        [$top, $bottom, $middle] = $this->seedOrderableWidgets();
        $this->app['db']->table('users')->whereIn('id', [$top, $bottom, $middle])->update(['created_at' => '2026-01-01 00:00:00']);

        // Every created_at is equal: orderBy('created_at', 'desc') — what latest() adds — leaves the
        // whole order to the tie-break, the key descending, on the id-list query and on the walk alike.
        foreach ([200, 2] as $chunk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);

            $this->assertSame([$middle, $bottom, $top], $this->resultIds($this->scoutBuilder()->orderBy('created_at', 'desc')->get()), "candidate_chunk {$chunk}");
            $this->assertSame([$top], $this->resultIds($this->scoutBuilder()->orderBy('created_at', 'desc')->paginate(2, 'page', 2)), "candidate_chunk {$chunk}");
        }
    }

    /**
     * The ordered statements a Scout call runs: those that read users with an ORDER BY. Each is
     * [sql, bindings, ids] where ids counts the key values it restricts to, inlined or bound.
     */
    private function orderedStatements(\Closure $call): array
    {
        $statements = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$statements) {
            if (preg_match('/\busers\b/', $query->sql) && stripos($query->sql, 'order by') !== false) {
                preg_match_all('/\bin \(([^)]*)\)/i', $query->sql, $lists);
                $inlined = array_sum(array_map(fn ($list) => count(explode(',', $list)), $lists[1]));
                $statements[] = [$query->sql, $query->bindings, $inlined + count($query->bindings)];
            }
        });

        $call();

        return $statements;
    }

    public function test_scout_order_by_restricts_by_the_ranked_ids_only_within_one_candidate_chunk(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedOrderableWidgets();

        // Within one chunk: one ordered statement, restricted to the three ranked ids.
        $within = $this->orderedStatements(fn () => $this->scoutBuilder()->orderBy('name')->paginate(2, 'page', 1));
        $this->assertCount(1, $within);
        $this->assertSame(3, $within[0][2], 'the ordered query is restricted to the ranked ids');

        // Past one chunk: no statement carries more ids than one chunk — the ids are not listed.
        config(['fuzzy-search.bm25.candidate_chunk' => 2]);
        $past = $this->orderedStatements(fn () => $this->scoutBuilder()->orderBy('name')->paginate(2, 'page', 1));
        $this->assertNotEmpty($past);
        foreach ($past as [$sql, , $ids]) {
            $this->assertLessThanOrEqual(2, $ids, "an id list past one chunk: {$sql}");
        }
    }

    public function test_scout_order_by_reads_the_ordered_rows_in_bounded_pages(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedOrderableWidgets();
        config(['fuzzy-search.bm25.candidate_chunk' => 2]);

        // cursor() fetched the whole ordered key column, and pdo_mysql and pdo_pgsql buffer all of
        // it before the first row; each statement of the walk now reads at most 1,000 rows (SQL
        // Server writes the first page as TOP 1000, later ones as FETCH NEXT 1000 ROWS).
        $walk = $this->orderedStatements(fn () => $this->scoutBuilder()->orderBy('name')->get());

        $this->assertNotEmpty($walk);
        foreach ($walk as [$sql]) {
            $this->assertMatchesRegularExpression('/\blimit 1000\b|\btop 1000\b|\bfetch next 1000 rows\b/i', $sql, "an unbounded walk: {$sql}");
        }
    }

    public function test_scout_paginate_serves_an_empty_page_for_a_page_too_large_for_an_offset(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        [$top] = $this->seedOrderableWidgets();

        // ($page - 1) * $perPage overflowed into a float and resultKeys(int) threw a TypeError.
        $builders = [
            'unconstrained' => fn () => $this->scoutBuilder(),
            'orderBy'       => fn () => $this->scoutBuilder()->orderBy('name'),
            'where'         => fn () => $this->scoutBuilder()->where('id', $top),
        ];

        foreach ($builders as $label => $builder) {
            $this->assertSame([], $this->resultIds($builder()->paginate(15, 'page', PHP_INT_MAX)), "{$label}: paginate()");
            $this->assertSame([], $this->resultIds($builder()->simplePaginate(15, 'page', PHP_INT_MAX)), "{$label}: simplePaginate()");

            $this->app['request']->query->set('page', (string) PHP_INT_MAX);
            $this->assertSame([], $this->resultIds($builder()->paginate(15)), "{$label}: ?page");
            $this->app['request']->query->remove('page');
        }
    }

    // -------------------------------------------------------------------------
    // query.max_term_length: the engine binds one parameter per query term, so an uncapped
    // query of a few thousand words passed SQL Server's 2,100-parameter limit
    // -------------------------------------------------------------------------

    /**
     * Runs $call and returns every executed query's bindings.
     *
     * @return array<int, array<int, mixed>>
     */
    private function bindingsOf(\Closure $call): array
    {
        $bindings = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$bindings) {
            $bindings[] = $query->bindings;
        });

        $call();

        return $bindings;
    }

    /** The query words ("word1", "word2", …) each executed query bound, whichever table it read. */
    private function boundWords(\Closure $call): array
    {
        $bound = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$bound) {
            $words = array_values(array_filter($query->bindings, fn ($b) => is_string($b) && preg_match('/^word\d*$/D', $b)));
            if ($words !== []) {
                $bound[] = $words;
            }
        });

        $call();

        return $bound;
    }

    public function test_scout_caps_a_5000_character_query_at_max_term_length(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedOrderableWidgets();
        config(['fuzzy-search.query.max_term_length' => 128]);
        $engine = $this->makeEngine();

        // One 5,000-character word, and 1,000 distinct words of five characters or so.
        $word  = str_repeat('widget', 834);
        $words = 'widget ' . implode(' ', array_map(fn ($i) => 'w' . $i, range(1000, 1999)));
        $this->assertGreaterThanOrEqual(5000, strlen($words));

        foreach (['one word' => $word, 'many words' => $words] as $label => $query) {
            foreach (['search' => fn () => $engine->search($this->scoutBuilder($query)), 'paginate' => fn () => $engine->paginate($this->scoutBuilder($query), 15, 1)] as $method => $call) {
                foreach ($this->bindingsOf($call) as $bindings) {
                    $this->assertLessThan(2100, count($bindings), "{$label}: {$method}() bound " . count($bindings) . ' parameters');

                    foreach ($bindings as $binding) {
                        $this->assertLessThanOrEqual(128, mb_strlen((string) $binding), "{$label}: {$method}() bound a term longer than max_term_length");
                    }
                }
            }
        }

        // The capped query still searches: its first 128 characters start with "widget".
        $this->assertSame(3, $engine->search($this->scoutBuilder($words))['total']);
    }

    public function test_scout_looks_up_only_the_words_within_max_term_length_of_a_500_word_query(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedOrderableWidgets();
        config(['fuzzy-search.query.max_term_length' => 128]);
        $engine = $this->makeEngine();

        $query    = implode(' ', array_map(fn ($i) => 'word' . $i, range(1, 500)));
        $expected = app(IndexManager::class)->processTerms(mb_substr($query, 0, 128), null, ScoutIndexedUser::class);
        $this->assertLessThan(30, count($expected));

        foreach (['search' => fn () => $engine->search($this->scoutBuilder($query)), 'paginate' => fn () => $engine->paginate($this->scoutBuilder($query), 15, 1)] as $method => $call) {
            $bound = $this->boundWords($call);

            $this->assertContains($expected, $bound, "{$method}() did not look up the words within max_term_length");
            foreach ($bound as $words) {
                $this->assertSame([], array_diff($words, $expected), "{$method}() looked up words past max_term_length");
            }
        }
    }

    public function test_scout_paginate_total_and_page_reflect_builder_wheres(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $engine = $this->makeEngine();
        [[$lowestId, $lowest]] = $this->seedRankedWidgets($engine);

        $builder = (new \Laravel\Scout\Builder($lowest, 'widget'))->where('email', $lowest->email);
        $page    = $engine->paginate($builder, 2, 1);

        $this->assertSame(1, $engine->getTotalCount($page));
        $this->assertSame([$lowestId], collect($page['results'])->pluck('model_id')->all());
    }
}

// ---------------------------------------------------------------------------
// Named model class required by test_scout_engine_map_returns_correct_models.
// Must be a named (non-anonymous) class so Scout's Searchable trait can look
// up rows via getScoutModelsByIds(). Uses Scout's own Searchable trait which
// provides that method.
// ---------------------------------------------------------------------------

class ScoutMapTestUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable;

    protected $table    = 'users';
    protected $fillable = ['name', 'email', 'created_at', 'updated_at'];
    public $timestamps  = true;
}

/** Scout's trait for the index name (searchableAs()/indexableAs()), the package's for indexing. */
class ScoutIndexedUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}
