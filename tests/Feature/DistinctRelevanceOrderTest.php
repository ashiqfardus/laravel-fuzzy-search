<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

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
}
