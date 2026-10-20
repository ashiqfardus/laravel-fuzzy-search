<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FiiBook extends Model
{
    use Searchable;

    protected $table   = 'fii_books';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10, 'summary' => 5]];
}

class FiiAuthor extends Model
{
    use Searchable;

    protected $table   = 'fii_authors';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'bio' => 5]];
}

/** Searchable, but its index is never built. */
class FiiMagazine extends Model
{
    use Searchable;

    protected $table   = 'fii_magazines';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

/** No Searchable trait: FederatedSearch searches its `title` with LIKE. */
class FiiNote extends Model
{
    protected $table   = 'fii_notes';
    protected $guarded = [];
    public $timestamps = false;
}

/**
 * F-1 (ruling ER-193). FederatedSearch could not use the BM25 index: every Searchable model was a
 * LIKE scan, read twice (countPerModel() for paginate()'s total, then searchModel() for the page).
 * useInvertedIndex() searches each Searchable model as Model::search($term)->useInvertedIndex()
 * does on its own; a model without the trait stays on LIKE.
 */
class FederatedInvertedIndexTest extends TestCase
{
    private const TABLES = ['fii_books', 'fii_authors', 'fii_magazines', 'fii_notes'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('fii_books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('summary');
        });
        Schema::create('fii_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('bio');
        });
        Schema::create('fii_magazines', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
        Schema::create('fii_notes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        // Six books match "laravel", one of them only in its summary; four authors, one only in its name.
        DB::table('fii_books')->insert([
            ['title' => 'Laravel Up and Running', 'summary' => 'A framework guide'],
            ['title' => 'Laravel Testing Decoded', 'summary' => 'Testing with phpunit'],
            ['title' => 'Laravel Queues in Action', 'summary' => 'Background jobs'],
            ['title' => 'Battle Ready Laravel', 'summary' => 'Auditing applications'],
            ['title' => 'Mastering Laravel', 'summary' => 'Advanced topics and more laravel'],
            ['title' => 'PHP Cookbook', 'summary' => 'Recipes for laravel developers'],
            ['title' => 'Domain Driven Design', 'summary' => 'Patterns'],
        ]);
        DB::table('fii_authors')->insert([
            ['name' => 'Taylor Otwell', 'bio' => 'Creator of Laravel'],
            ['name' => 'Matt Stauffer', 'bio' => 'Wrote a laravel book'],
            ['name' => 'Jeffrey Way', 'bio' => 'Teaches laravel daily'],
            ['name' => 'Laravel Daily', 'bio' => 'A channel'],
            ['name' => 'Adam Wathan', 'bio' => 'Builds tailwind'],
        ]);
        DB::table('fii_magazines')->insert([['title' => 'Laravel News'], ['title' => 'PHP Architect']]);
        DB::table('fii_notes')->insert([['title' => 'laravel note one'], ['title' => 'laravel note two'], ['title' => 'unrelated']]);

        app(IndexManager::class)->indexBatch(FiiBook::all());
        app(IndexManager::class)->indexBatch(FiiAuthor::all());
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * Without relevance the federated order is the across() order, then each model's key, and on the
     * index each model hands over its rows in that order: it handed over its best ranked ones, a set
     * that changed as the limit grew with the page, so page 2 repeated page 1 and some rows were never
     * served (P6C-1).
     */
    public function test_without_relevance_the_index_serves_the_rows_in_key_order_page_after_page(): void
    {
        $make = fn () => FederatedSearch::across([FiiBook::class, FiiAuthor::class])->search('laravel')->withRelevance(false)->useInvertedIndex();
        $tag  = fn ($row) => class_basename($row) . ':' . $row->getKey();
        $want = ['FiiBook:1', 'FiiBook:2', 'FiiBook:3', 'FiiBook:4', 'FiiBook:5', 'FiiBook:6', 'FiiAuthor:1', 'FiiAuthor:2', 'FiiAuthor:3', 'FiiAuthor:4'];

        $this->assertSame($want, $make()->limit(50)->get()->map($tag)->all());
        $this->assertSame(array_slice($want, 0, 2), $make()->limit(2)->get()->map($tag)->all());

        $paged  = [];
        $simple = [];
        for ($page = 1; $page <= 4; $page++) {
            $paginator = $make()->paginate(3, 'page', $page);
            $this->assertSame(10, $paginator->total());
            $paged  = [...$paged, ...$paginator->getCollection()->map($tag)->all()];
            $simple = [...$simple, ...collect($make()->simplePaginate(3, 'page', $page)->items())->map($tag)->all()];
        }
        $this->assertSame($want, $paged);
        $this->assertSame($want, $simple);
    }

    public function test_every_terminal_equals_each_models_own_index_search(): void
    {
        $federated = fn () => FederatedSearch::across([FiiBook::class, FiiAuthor::class])->search('laravel')->useInvertedIndex();
        $own       = [
            FiiBook::class   => fn () => FiiBook::search('laravel')->useInvertedIndex(),
            FiiAuthor::class => fn () => FiiAuthor::search('laravel')->useInvertedIndex(),
        ];
        $counts = array_map(fn ($make) => $make()->count(), $own);
        $this->assertSame([FiiBook::class => 6, FiiAuthor::class => 4], $counts);

        DB::enableQueryLog();

        // get(): each model's rows, in its own order and with its own _score; the merge by _score.
        $all = $federated()->limit(50)->get();
        $this->assertCount(10, $all);
        $this->assertSameAsOwn($own, $all, 50);
        $this->assertSame($all->pluck('_score')->sortDesc()->values()->all(), $all->pluck('_score')->all(), 'merged by _score');
        foreach ($all as $row) {
            $this->assertTrue(isset($row->_score), 'every row carries _score');
        }

        // count() is the rows get() returns: the totals and getCounts() agree with the pages.
        foreach ($own as $class => $make) {
            $this->assertSame($counts[$class], $make()->limit(50)->get()->count(), class_basename($class) . ': count() = get()');
        }
        $this->assertSame(['FiiBook' => 6, 'FiiAuthor' => 4], $federated()->getCounts());

        $first = $federated()->paginate(3);
        $this->assertSame(10, $first->total());
        $this->assertSame(4, $first->lastPage());
        $paged = [];
        for ($page = 1; $page <= 4; $page++) {
            $paged = [...$paged, ...self::ids($federated()->paginate(3, 'page', $page)->items())];
        }
        $this->assertSame(self::ids($all), $paged, 'paginate() pages');

        $simple = [];
        for ($page = 1; $page <= 4; $page++) {
            $pager  = $federated()->simplePaginate(3, 'page', $page);
            $simple = [...$simple, ...self::ids($pager->items())];
            $this->assertSame($page < 4, $pager->hasMorePages(), "simplePaginate() page {$page}");
        }
        $this->assertSame(self::ids($all), $simple, 'simplePaginate() pages');

        $grouped = $federated()->limit(50)->getGrouped();
        $this->assertSame(['FiiAuthor', 'FiiBook'], $grouped->keys()->sort()->values()->all());
        foreach ($own as $class => $make) {
            $this->assertSame($make()->stableRanking()->limit(50)->get()->modelKeys(), $grouped[class_basename($class)]->pluck('id')->all(), class_basename($class) . ': getGrouped()');
        }

        // The point of the feature: no LIKE scan on a Searchable model's table.
        $this->assertSame([], self::likeOn(['fii_books', 'fii_authors']), 'a LIKE on an indexed model');
    }

    public function test_search_in_narrows_the_columns_on_the_index(): void
    {
        DB::enableQueryLog();
        $rows = FederatedSearch::across([FiiBook::class, FiiAuthor::class])->search('laravel')->searchIn(['title' => 10, 'name' => 10])->useInvertedIndex()->limit(50)->get();

        // "PHP Cookbook" holds laravel only in its summary, and three authors only in their bio.
        $this->assertSameAsOwn([
            FiiBook::class   => fn () => FiiBook::searchOn(FiiBook::query(), 'laravel', ['title' => 10])->useInvertedIndex(),
            FiiAuthor::class => fn () => FiiAuthor::searchOn(FiiAuthor::query(), 'laravel', ['name' => 10])->useInvertedIndex(),
        ], $rows, 50);
        $this->assertSame(['Laravel Daily'], $rows->where('_model_class', FiiAuthor::class)->pluck('name')->all());
        $this->assertNotContains('PHP Cookbook', $rows->pluck('title')->all());
        $this->assertCount(6, $rows);
        $this->assertSame([], self::likeOn(['fii_books', 'fii_authors']));
    }

    public function test_a_typo_is_found_through_the_index(): void
    {
        // Rows without "laravel" bring it to half of each model's rows: a word in more is no typo expansion (ER-225a).
        DB::table('fii_books')->insert(array_map(fn ($i) => ['title' => "Gardening volume {$i}", 'summary' => 'Plants'], range(1, 5)));
        DB::table('fii_authors')->insert(array_map(fn ($i) => ['name' => "Poet number {$i}", 'bio' => 'Verses'], range(1, 3)));
        app(IndexManager::class)->indexBatch(FiiBook::all());
        app(IndexManager::class)->indexBatch(FiiAuthor::all());

        DB::enableQueryLog();
        $federated = FederatedSearch::across([FiiBook::class, FiiAuthor::class])->search('larvel')->typoTolerance(1)->useInvertedIndex();
        $rows      = $federated->limit(50)->get();

        $this->assertCount(10, $rows);
        $this->assertSameAsOwn([
            FiiBook::class   => fn () => FiiBook::search('larvel')->typoTolerance(1)->useInvertedIndex(),
            FiiAuthor::class => fn () => FiiAuthor::search('larvel')->typoTolerance(1)->useInvertedIndex(),
        ], $rows, 50);
        $this->assertSame(['FiiBook' => 6, 'FiiAuthor' => 4], $federated->getCounts());
        $this->assertSame([], self::likeOn(['fii_books', 'fii_authors']));

        // On the index the algorithm and options are not read: the typo tolerance alone expands.
        $this->assertSame(['FiiBook' => 6, 'FiiAuthor' => 4], FederatedSearch::across([FiiBook::class, FiiAuthor::class])->search('larvel')->typoTolerance(1)->using('simple')->options(['max_distance' => 0])->useInvertedIndex()->getCounts());
        $this->assertSame([], self::likeOn(['fii_books', 'fii_authors']));

        // Exact matching on the index: no expansion, nothing found.
        $this->assertSame(['FiiBook' => 0, 'FiiAuthor' => 0], FederatedSearch::across([FiiBook::class, FiiAuthor::class])->search('larvel')->typoTolerance(0)->useInvertedIndex()->getCounts());
    }

    public function test_a_model_whose_index_was_never_built_matches_nothing_as_on_its_own(): void
    {
        $own = fn () => FiiMagazine::search('laravel')->useInvertedIndex();
        $this->assertSame(0, $own()->count());
        $this->assertCount(0, $own()->get());

        $federated = fn () => FederatedSearch::across([FiiBook::class, FiiMagazine::class])->search('laravel');
        $this->assertSame(['FiiBook' => 6, 'FiiMagazine' => 0], $federated()->useInvertedIndex()->getCounts());
        $this->assertSame(6, $federated()->useInvertedIndex()->paginate(15)->total());
        $this->assertSame([], $federated()->useInvertedIndex()->limit(50)->get()->where('_model_class', FiiMagazine::class)->all());

        // Its rows do match: LIKE finds them.
        $this->assertSame(['FiiBook' => 6, 'FiiMagazine' => 1], $federated()->getCounts());
    }

    public function test_a_model_without_the_trait_still_answers_through_like(): void
    {
        DB::enableQueryLog();
        $federated = fn () => FederatedSearch::across([FiiBook::class, FiiNote::class])->search('laravel')->useInvertedIndex();
        $rows      = $federated()->limit(50)->get();

        $this->assertSame(['laravel note one', 'laravel note two'], $rows->where('_model_class', FiiNote::class)->pluck('title')->all());
        $this->assertSame(['FiiBook' => 6, 'FiiNote' => 2], $federated()->getCounts());
        $this->assertSame(8, $federated()->paginate(15)->total());
        $this->assertNotSame([], self::likeOn(['fii_notes']), 'the note table is read through LIKE');
        $this->assertSame([], self::likeOn(['fii_books']));
    }

    public function test_use_inverted_index_false_returns_to_like(): void
    {
        DB::enableQueryLog();
        $rows = FederatedSearch::across([FiiBook::class])->search('laravel')->useInvertedIndex()->useInvertedIndex(false)->limit(50)->get();

        $this->assertNotSame([], self::likeOn(['fii_books']));
        $this->assertSame(FiiBook::search('laravel')->stableRanking()->limit(50)->get()->modelKeys(), $rows->pluck('id')->all());
    }

    public function test_limit_per_model_and_max_candidates_cap_each_model(): void
    {
        $federated = fn () => FederatedSearch::across([FiiBook::class, FiiAuthor::class])->search('laravel')->useInvertedIndex();
        $own       = [
            FiiBook::class   => fn () => FiiBook::search('laravel')->useInvertedIndex(),
            FiiAuthor::class => fn () => FiiAuthor::search('laravel')->useInvertedIndex(),
        ];

        $rows = $federated()->limitPerModel(2)->limit(50)->get();
        $this->assertSameAsOwn($own, $rows, 2);
        $this->assertCount(4, $rows);
        $this->assertSame(['FiiBook' => 2, 'FiiAuthor' => 2], $federated()->limitPerModel(2)->getCounts());
        $this->assertSame(4, $federated()->limitPerModel(2)->paginate(15)->total());

        config(['fuzzy-search.max_candidates' => 3]);
        $rows = $federated()->limit(50)->get();
        $this->assertSameAsOwn($own, $rows, 3);
        $this->assertCount(6, $rows);
        $this->assertSame(['FiiBook' => 3, 'FiiAuthor' => 3], $federated()->getCounts());
        $this->assertSame(6, $federated()->paginate(3)->total());
    }

    /**
     * Each model's rows in $rows are its own builder's first $limit rows, in its order, with its _score.
     *
     * @param array<class-string, \Closure(): SearchBuilder> $own
     */
    private function assertSameAsOwn(array $own, Collection $rows, int $limit): void
    {
        foreach ($own as $class => $make) {
            $expected = $make()->stableRanking()->limit($limit)->get();
            $found    = $rows->where('_model_class', $class)->values();
            $this->assertNotEmpty($expected, class_basename($class));
            $this->assertSame($expected->modelKeys(), $found->pluck('id')->all(), class_basename($class) . ': rows');
            $this->assertEquals($expected->pluck('_score')->all(), $found->pluck('_score')->all(), class_basename($class) . ': _score');
        }
    }

    /** @return string[] model type#key, in the order given */
    private static function ids(iterable $rows): array
    {
        return collect($rows)->map(fn ($row) => $row->_model_type . '#' . $row->getKey())->all();
    }

    /**
     * The logged queries on any of $tables with a LIKE (ILIKE on PostgreSQL) predicate.
     *
     * @param string[] $tables
     * @return string[]
     */
    private static function likeOn(array $tables): array
    {
        return array_values(array_filter(
            array_column(DB::getQueryLog(), 'query'),
            fn (string $sql) => preg_match('/\bi?like\b/i', $sql) === 1 && array_filter($tables, fn (string $table) => str_contains($sql, $table)) !== []
        ));
    }
}
