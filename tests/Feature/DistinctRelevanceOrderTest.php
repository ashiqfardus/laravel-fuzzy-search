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

    /**
     * UA-1, UA-5, UF-6. Three selects R11-M3's order missed. PostgreSQL's distinct('name') (DISTINCT
     * ON) was ordered by the key, which it rejects unless the order starts with the DISTINCT ON
     * columns (42P10, on every read); an aliased column (`name as label`) was left out of the order,
     * and a query builder's `select *` over a table with no `id` was not ordered at all, so their
     * pages past max_candidates repeated rows on PostgreSQL. Each is now read in one total order.
     */
    public function test_distinct_on_an_alias_and_a_table_without_id_are_read_in_one_total_order(): void
    {
        config(['fuzzy-search.max_candidates' => 5]);
        Schema::dropIfExists('distinct_codes');
        Schema::create('distinct_codes', function ($table) {
            $table->string('code', 20)->primary();
            $table->string('name');
            $table->string('body')->nullable();
        });
        $codes = [];
        foreach (DB::table('distinct_items')->orderBy('name')->get(['name', 'body']) as $n => $item) {
            $codes[] = ['code' => sprintf('c%02d', 30 - $n), 'name' => $item->name, 'body' => $item->body];
        }
        DB::table('distinct_codes')->insert($codes);

        $builder = fn ($base) => (new SearchBuilder($base, app(FuzzySearch::class)))->search('alpha');
        $shapes  = [
            'distinct on'       => [fn () => DistinctItem::search('alpha')->distinct('name'), 'name'],
            'distinct on norel' => [fn () => DistinctItem::search('alpha')->withRelevance(false)->distinct('name'), 'name'],
            'alias'             => [fn () => DistinctItem::search('alpha')->withRelevance(false)->distinct()->select('cat', 'name as label'), 'label'],
            'qb alias'          => [fn () => $builder(DB::table('distinct_items')->select('cat', 'name as label'))->searchIn(['name' => 10, 'body' => 5])->distinct(), 'label'],
            'qb no id'          => [fn () => $builder(DB::table('distinct_codes'))->searchIn(['name' => 10, 'body' => 5])->distinct(), 'name'],
            'qb no id table.*'  => [fn () => $builder(DB::table('distinct_codes')->select('distinct_codes.*'))->searchIn(['name' => 10, 'body' => 5])->withRelevance(false)->distinct(), 'name'],
        ];

        foreach ($shapes as $label => [$make, $column]) {
            $names = [];
            for ($page = 1; $page <= 5; $page++) {
                $paginator = $make()->paginate(5, 'page', $page);
                $this->assertSame(25, $paginator->total(), "{$label} total");
                $names = [...$names, ...array_map(fn ($row) => $row->{$column}, $paginator->items())];
            }
            sort($names);
            $this->assertSame($this->matching, $names, "{$label} pages");
            $this->assertContains($make()->first()?->{$column}, $this->matching, "{$label} first");
            $this->assertCount(5, $make()->get(), "{$label} get"); // the max_candidates window
        }

        // The order each read takes, whatever plan the database picks.
        $grammar = DB::connection()->getQueryGrammar();
        $order   = fn (SearchBuilder $builder) => strtolower((string) strstr($builder->toSql(), 'order by'));
        $this->assertStringContainsString($grammar->wrap('cat') . ' asc, ' . $grammar->wrap('label') . ' asc', $order($shapes['alias'][0]()));
        $this->assertStringContainsString($grammar->wrap('cat') . ' asc, ' . $grammar->wrap('label') . ' asc', $order($shapes['qb alias'][0]()));
        $this->assertStringContainsString($grammar->wrap('code') . ' asc, ' . $grammar->wrap('name') . ' asc, ' . $grammar->wrap('body') . ' asc', $order($shapes['qb no id'][0]()));
        $this->assertStringContainsString($grammar->wrap('code') . ' asc, ' . $grammar->wrap('name') . ' asc, ' . $grammar->wrap('body') . ' asc', $order($shapes['qb no id table.*'][0]()));
        if (DB::connection()->getDriverName() === 'pgsql') {
            // DISTINCT ON's columns first, then the key: one row a name, the same one on every read.
            $this->assertStringStartsWith('order by "name" asc, "distinct_items"."id" asc', $order($shapes['distinct on norel'][0]()));
        }

        Schema::dropIfExists('distinct_codes');
    }
}
