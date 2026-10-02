<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnionItem extends Model
{
    use Searchable, SoftDeletes;

    protected $table   = 'union_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'body' => 5]];
}

class UnionCodeItem extends Model
{
    use Searchable;

    protected $table      = 'union_code_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 10, 'body' => 5]];
}

/**
 * SE-2. On the LIKE and extended paths the search predicate and every filter() went into the
 * query's own wheres, which on a union are its first part's, and the relevance ORDER BY became
 * the union's: the other parts were never searched. MySQL and MariaDB served every row of them for
 * any term, PostgreSQL and SQLite threw in relevance order, and stableRanking() threw on MySQL and
 * MariaDB. The index path reads a union as one derived table (rulings ER-125, ER-127); the LIKE and
 * extended paths now do too, so all three serve and count only matches.
 */
class UnionLikePathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('union_items');
        Schema::dropIfExists('union_code_items');
        Schema::create('union_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('body')->nullable();
            $table->integer('cat');
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('union_code_items', function ($table) {
            $table->string('code')->primary();
            $table->string('name');
            $table->string('body')->nullable();
            $table->integer('cat');
        });

        for ($i = 1; $i <= 30; $i++) {
            $row = ['name' => ($i % 3 ? 'alpha ' : 'beta ') . "item {$i}", 'body' => $i % 2 ? 'alpha' : null, 'cat' => $i % 4];
            DB::table('union_items')->insert($row);
            DB::table('union_code_items')->insert(['code' => sprintf('c-%02d', $i)] + $row);
        }
        // A trashed match in the second part: its own SoftDeletes scope keeps it out.
        DB::table('union_items')->where('name', 'alpha item 2')->update(['deleted_at' => now()]);

        app(IndexManager::class)->indexBatch(UnionItem::all());
        app(IndexManager::class)->indexBatch(UnionCodeItem::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('union_items');
        Schema::dropIfExists('union_code_items');
        parent::tearDown();
    }

    /** @return array<string, array{class-string<Model>, \Closure}> the union shape => [model, the query() callback] */
    private function unions(): array
    {
        return [
            'union'        => [UnionItem::class, fn ($q) => $q->where('cat', 1)->union(UnionItem::query()->where('cat', 2))],
            'unionAll'     => [UnionItem::class, fn ($q) => $q->where('cat', 1)->unionAll(UnionItem::query()->where('cat', 2))],
            'string key'   => [UnionCodeItem::class, fn ($q) => $q->where('cat', 1)->union(UnionCodeItem::query()->where('cat', 2))],
        ];
    }

    /** The names of the rows of cat 1 or 2 holding "alpha", live ones only. */
    private function expected(string $model): array
    {
        return $model::query()->whereIn('cat', [1, 2])
            ->where(fn ($q) => $q->where('name', 'like', '%alpha%')->orWhere('body', 'like', '%alpha%'))
            ->orderBy('name')->pluck('name')->sort()->values()->all();
    }

    public function test_every_path_serves_and_counts_only_the_matches_of_every_part(): void
    {
        foreach ($this->unions() as $shape => [$model, $union]) {
            $expected = $this->expected($model);
            $this->assertNotContains('alpha item 2', $model === UnionItem::class ? $expected : []);

            $paths = [
                'default'     => fn () => $model::search('alpha')->query($union),
                'simple'      => fn () => $model::search('alpha')->using('simple')->query($union),
                'noRelevance' => fn () => $model::search('alpha')->withRelevance(false)->query($union),
                'orderBy'     => fn () => $model::search('alpha')->orderBy('name')->query($union),
                'stable'      => fn () => $model::search('alpha')->stableRanking()->query($union),
                'filter'      => fn () => $model::search('alpha')->filter('cat', '>', 0)->query($union),
                'extended'    => fn () => $model::search('')->extended('alpha')->query($union),
                'index'       => fn () => $model::search('alpha')->useInvertedIndex()->query($union),
            ];

            foreach ($paths as $path => $make) {
                $label = "{$shape} {$path}";
                $names = fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all();

                $this->assertSame($expected, $names($make()->take(100)->get()), "{$label} get");
                $this->assertSame(count($expected), $make()->count(), "{$label} count");
                $page = $make()->paginate(50);
                $this->assertSame(count($expected), $page->total(), "{$label} paginate total");
                $this->assertSame($expected, $names($page->items()), "{$label} paginate");
                $this->assertSame($expected, $names($make()->simplePaginate(50)->items()), "{$label} simplePaginate");
                $this->assertContains($make()->first()?->name, $expected, "{$label} first");
            }

            // getFacets() counts the matches of both parts.
            $facets = $model::search('alpha')->query($union)->facet('cat')->getFacets()['cat'];
            $this->assertSame(count($expected), array_sum($facets), "{$shape} facets");
            $this->assertEqualsCanonicalizing([1, 2], array_map('intval', array_keys($facets)), "{$shape} facets");

            // suggest()'s table scan reads the matches of every part.
            foreach ($model::search('alp')->suggestFrom('table')->query($union)->suggest(10) as $suggestion) {
                $this->assertStringStartsWith('alp', strtolower($suggestion), "{$shape} suggest");
            }

            // A cache hit serves what the miss did.
            $miss = $model::search('alpha')->query($union)->cache()->take(100)->get()->pluck('name')->sort()->values()->all();
            $this->assertSame($expected, $miss, "{$shape} cache miss");
            $this->assertSame($miss, $model::search('alpha')->query($union)->cache()->take(100)->get()->pluck('name')->sort()->values()->all(), "{$shape} cache hit");
        }
    }

    /**
     * R11-L12. suggest()'s table scan reads limit × 3 rows of the union. Unwrapped, its prefix
     * predicate went into the first part only, so the scan read the second part's rows whatever they
     * held, and more than 30 of them ahead of its match left that match unread.
     */
    public function test_suggest_reads_the_matches_of_a_later_part_past_the_scan_limit(): void
    {
        DB::table('union_items')->insert(['name' => 'zebra first part', 'cat' => 1]);
        for ($i = 1; $i <= 40; $i++) {
            DB::table('union_items')->insert(['name' => "gamma filler {$i}", 'cat' => 2]);
        }
        DB::table('union_items')->insert(['name' => 'zebrafish second part', 'cat' => 2]);

        $suggest = fn (\Closure $union) => UnionItem::search('zeb')->suggestFrom('table')->query($union)->suggest(10);

        foreach ([
            'union'    => fn ($q) => $q->where('cat', 1)->union(UnionItem::query()->where('cat', 2)),
            'unionAll' => fn ($q) => $q->where('cat', 1)->unionAll(UnionItem::query()->where('cat', 2)),
        ] as $shape => $union) {
            $suggestions = $suggest($union);
            $this->assertContains('zebrafish', $suggestions, "{$shape}: only the second part holds it");
            $this->assertContains('zebra', $suggestions, $shape);
        }
    }

    public function test_a_plain_query_builder_union_is_searched_whole(): void
    {
        $query = DB::table('union_items')->whereNull('deleted_at')->where('cat', 1)
            ->union(DB::table('union_items')->whereNull('deleted_at')->where('cat', 2));

        $rows = (new SearchBuilder($query, app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class)))
            ->search('alpha')->searchIn(['name', 'body'])->take(100)->get();

        $this->assertSame($this->expected(UnionItem::class), $rows->pluck('name')->sort()->values()->all());
    }
}
