<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DistinctItem extends Model
{
    use Searchable;

    protected $table   = 'distinct_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'body' => 5]];
}

/**
 * SE-1. A SELECT DISTINCT may be ordered only by what it selects. The LIKE path ordered every
 * relevance search by a CASE over the searched columns, and stableRanking() by the key, so
 * search($t)->distinct() threw on get(), first(), paginate() and simplePaginate(): always on
 * PostgreSQL (42P10) and SQL Server (145), on MySQL when the select left out a searched column or
 * the key (3065). Under distinct() the search now adds no relevance ORDER BY (PHP rescoring orders
 * the window, as on the extended path) and orders by the key only when it is selected.
 */
class DistinctRelevanceOrderTest extends TestCase
{
    /** @var string[] the names of the rows holding "alpha" in name or body */
    private array $matching = [];

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('distinct_notes');
        Schema::dropIfExists('distinct_items');
        Schema::create('distinct_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('body')->nullable();
            $table->integer('cat');
        });
        Schema::create('distinct_notes', function ($table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('label');
        });

        $notes = [];
        for ($i = 1; $i <= 30; $i++) {
            $name = ($i % 3 ? 'alpha ' : 'beta ') . "item {$i}";
            DB::table('distinct_items')->insert(['name' => $name, 'body' => $i % 2 ? 'alpha' : null, 'cat' => $i % 4]);
            if ($i % 3 || $i % 2) {
                $this->matching[] = $name;
            }
            for ($k = $i % 3; $k > 0; $k--) { // 0, 1 or 2 notes an item: the join repeats items
                $notes[] = ['item_id' => DB::table('distinct_items')->where('name', $name)->value('id'), 'label' => "n{$k}"];
            }
        }
        DB::table('distinct_notes')->insert($notes);
        sort($this->matching);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('distinct_notes');
        Schema::dropIfExists('distinct_items');
        parent::tearDown();
    }

    public function test_distinct_searches_run_in_relevance_order_on_every_terminal(): void
    {
        $this->assertCount(25, $this->matching);

        $shapes = [
            'default'          => fn () => DistinctItem::search('alpha')->distinct(),
            'simple'           => fn () => DistinctItem::search('alpha')->using('simple')->distinct(),
            'select(name)'     => fn () => DistinctItem::search('alpha')->distinct()->select('name'),
            'select(name,body)' => fn () => DistinctItem::search('alpha')->distinct()->select('name', 'body'),
            'stable'           => fn () => DistinctItem::search('alpha')->stableRanking()->distinct(),
            'stable select'    => fn () => DistinctItem::search('alpha')->stableRanking()->distinct()->select('name')->orderBy('name'),
            'extended stable'  => fn () => DistinctItem::search('')->extended('alpha')->stableRanking()->distinct()->select('name'),
            'join'             => fn () => DistinctItem::search('alpha')
                ->leftJoin('distinct_notes', 'distinct_notes.item_id', '=', 'distinct_items.id')->distinct()->select('distinct_items.*'),
        ];

        foreach ($shapes as $label => $make) {
            $this->assertSame($this->matching, $make()->take(100)->get()->pluck('name')->sort()->values()->all(), "{$label} get");
            $this->assertContains($make()->first()?->name, $this->matching, "{$label} first");

            $page = $make()->paginate(10);
            $this->assertCount(10, array_intersect(array_map(fn ($row) => $row->name, $page->items()), $this->matching), "{$label} paginate");
            if ($label !== 'join') { // a distinct join's total counts joined rows: Laravel's own count
                $this->assertSame(25, $page->total(), "{$label} total");
            }
            $this->assertCount(10, $make()->simplePaginate(10)->items(), "{$label} simplePaginate");
        }

        // stableRanking() still orders by the key when the select holds it: pages partition the matches.
        $make  = fn () => DistinctItem::search('alpha')->stableRanking()->distinct();
        $pages = collect([...$make()->paginate(10)->items(), ...$make()->paginate(10, 'page', 2)->items(), ...$make()->paginate(10, 'page', 3)->items()]);
        $this->assertSame($this->matching, $pages->pluck('name')->sort()->values()->all());

        // The window is ranked in PHP: the best match still leads.
        $this->assertSame(25, DistinctItem::search('alpha')->distinct()->count());
        $this->assertSame(1.0, (float) DistinctItem::search('alpha')->distinct()->get()->first()->_score);
    }

    /**
     * R11-M3. A page past max_candidates is one OFFSET read. With no ORDER BY, SQL Server's grammar
     * orders it by (SELECT 0), which a SELECT DISTINCT rejects (145), so every deep page threw there;
     * elsewhere the window and the deep pages were read in no fixed order, so pages could overlap.
     * An unordered distinct search is now read by the key, or by its selected columns: every page
     * reads, and the pages partition the matches.
     */
    public function test_distinct_pages_past_max_candidates_partition_the_matches(): void
    {
        config(['fuzzy-search.max_candidates' => 5]);

        $shapes = [
            'default'          => fn () => DistinctItem::search('alpha')->distinct(),
            'no relevance'     => fn () => DistinctItem::search('alpha')->withRelevance(false)->distinct(),
            'select(name,body)' => fn () => DistinctItem::search('alpha')->distinct()->select('name', 'body'),
            'select(name)'     => fn () => DistinctItem::search('alpha')->distinct()->select('name'),
            'select(id,name)'  => fn () => DistinctItem::search('alpha')->distinct()->select('id', 'name'),
            'stable'           => fn () => DistinctItem::search('alpha')->stableRanking()->distinct(),
            'stable select'    => fn () => DistinctItem::search('alpha')->stableRanking()->distinct()->select('name', 'body'),
            'extended'         => fn () => DistinctItem::search('')->extended('alpha')->distinct(),
            'join'             => fn () => DistinctItem::search('alpha')
                ->leftJoin('distinct_notes', 'distinct_notes.item_id', '=', 'distinct_items.id')->distinct()->select('distinct_items.*'),
            'query builder'    => fn () => (new SearchBuilder(DB::table('distinct_items'), app(FuzzySearch::class)))
                ->search('alpha')->searchIn(['name' => 10, 'body' => 5])->distinct(),
        ];

        foreach ($shapes as $label => $make) {
            $names = [];
            for ($page = 1; $page <= 5; $page++) {
                $paginator = $make()->paginate(5, 'page', $page);
                $this->assertCount(5, $paginator->items(), "{$label} page {$page}");
                $names = [...$names, ...array_map(fn ($row) => $row->name, $paginator->items())];
            }
            sort($names);
            $this->assertSame($this->matching, $names, "{$label} pages");

            // In one order, whatever plan the database picks (SQLite and MySQL happen to read in key order).
            $this->assertStringContainsString('order by', strtolower($make()->toSql()), "{$label} toSql");
        }

        // A select of another table's columns only names no key or plain column: it stays
        // unordered, and still reads (pages past the window need an orderBy() on SQL Server).
        $notes = DistinctItem::search('alpha')
            ->join('distinct_notes', 'distinct_notes.item_id', '=', 'distinct_items.id')->distinct()->select('distinct_notes.*');
        $this->assertStringNotContainsString('order by', strtolower($notes->toSql()));
        $this->assertCount(5, $notes->paginate(5)->items());
    }
}
