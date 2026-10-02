<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JoinedRowKettle extends Model
{
    use Searchable;

    protected $table   = 'jro_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/** The dual-trait recipe (docs/integrations.md), for the Scout engine's read of the same rows. */
class JoinedRowScoutKettle extends Model
{
    use \Laravel\Scout\Searchable, Searchable {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'jro_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/**
 * TE-6 (pass 3): an ordered index page over a one-to-many join places each model at its first row by
 * the order (ROW_NUMBER(), ruling ER-108), then reads the page's models again by key. That read had no
 * order, so each model was served with the joined row the database returned first (the one written
 * first): a page sorted by offers.price ascending showed each kettle's dearest offer, 1000, 990, …
 * The Scout engine kept the last row read instead, wrong in the other write order. Each model's joined
 * columns are now its first row by the order, whichever offer was written first.
 */
class JoinedRowByOrderTest extends TestCase
{
    /** @var array<string, array<int, array{0: string, 1: int}>> kettle title => its offers, [label, price] */
    private array $offers = [];

    protected function tearDown(): void
    {
        Schema::dropIfExists('jro_offers');
        Schema::dropIfExists('jro_items');

        parent::tearDown();
    }

    /** Twelve kettles, three offers each, written dearest first, or cheapest first; every price and label distinct. */
    private function seedOffers(bool $cheapestFirst): void
    {
        Schema::dropIfExists('jro_offers');
        Schema::dropIfExists('jro_items');
        Schema::create('jro_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('pos');
        });
        Schema::create('jro_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id')->index();
            $table->string('label');
            $table->integer('price');
            $table->integer('pos');
        });

        for ($n = 0; $n < 12; $n++) {
            DB::table('jro_items')->insert(['title' => "kettle {$n}", 'pos' => 11 - $n]);
        }

        $this->offers = [];
        foreach (DB::table('jro_items')->orderBy('id')->pluck('id', 'title') as $title => $id) {
            $n      = (int) substr($title, 7);
            $offers = [['z' . sprintf('%02d', 11 - $n), 1000 - $n * 10], ['m' . sprintf('%02d', 11 - $n), 500 + $n], ['a' . sprintf('%02d', 11 - $n), $n]];

            foreach ($cheapestFirst ? array_reverse($offers) : $offers as [$label, $price]) {
                DB::table('jro_offers')->insert(['item_id' => $id, 'label' => $label, 'price' => $price, 'pos' => $price]);
                $this->offers[$title][] = [$label, $price];
            }
        }

        app(IndexManager::class)->indexBatch(JoinedRowKettle::all());
        app(IndexManager::class)->indexBatch(JoinedRowScoutKettle::all());
    }

    /**
     * Each kettle at its first offer by $column ('label' or 'price') in $direction, as [title, label,
     * price], in that order.
     *
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function expected(string $column, string $direction): array
    {
        $at   = $column === 'label' ? 0 : 1;
        $rows = [];

        foreach ($this->offers as $title => $offers) {
            usort($offers, fn (array $a, array $b) => $direction === 'asc' ? $a[$at] <=> $b[$at] : $b[$at] <=> $a[$at]);
            $rows[] = [$title, $offers[0][0], $offers[0][1]];
        }

        usort($rows, fn (array $a, array $b) => $direction === 'asc' ? $a[$at + 1] <=> $b[$at + 1] : $b[$at + 1] <=> $a[$at + 1]);

        return $rows;
    }

    /** @return array<int, array{0: string, 1: string, 2: int}> */
    private static function served(iterable $models): array
    {
        return collect($models)->map(fn (Model $m) => [$m->title, $m->label, (int) $m->price])->values()->all();
    }

    private function search()
    {
        return JoinedRowKettle::search('kettle')->typoTolerance(0)->useInvertedIndex()
            ->join('jro_offers', 'jro_offers.item_id', '=', 'jro_items.id');
    }

    /** @return array<string, array{\Closure, string, string}> case => [the search, the order's column, its direction] */
    private function cases(): array
    {
        $joined = fn () => $this->search()->select('jro_items.*', 'jro_offers.label', 'jro_offers.price');

        return [
            'joined column'            => [fn () => $joined()->orderBy('jro_offers.price'), 'price', 'asc'],
            'joined column, desc'      => [fn () => $joined()->orderBy('jro_offers.price', 'desc'), 'price', 'desc'],
            'joined label'             => [fn () => $joined()->orderBy('jro_offers.label'), 'label', 'asc'],
            'select alias'             => [fn () => $this->search()->select('jro_items.*', 'jro_offers.label')->selectRaw('jro_offers.price as price')->orderBy('price'), 'price', 'asc'],
            'distinct()'               => [fn () => $joined()->distinct()->orderBy('jro_offers.price', 'desc'), 'price', 'desc'],
            'fromSub() holding a join' => [
                fn () => JoinedRowKettle::search('kettle')->typoTolerance(0)->useInvertedIndex()
                    ->fromSub(DB::table('jro_items')->join('jro_offers', 'jro_offers.item_id', '=', 'jro_items.id')->select('jro_items.*', 'jro_offers.label', 'jro_offers.price'), 'jro_items')
                    ->orderBy('price', 'desc'),
                'price', 'desc',
            ],
        ];
    }

    private function assertEachModelAtItsFirstRowByTheOrder(bool $cheapestFirst): void
    {
        $this->seedOffers($cheapestFirst);
        $written = $cheapestFirst ? 'cheapest first' : 'dearest first';

        // The ranking listed by id, and capped at max_postings_per_term (read through the postings).
        foreach (['listed' => 50000, 'capped' => 3] as $read => $cap) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);

            foreach ($this->cases() as $case => [$make, $column, $direction]) {
                $expected = $this->expected($column, $direction);
                $at       = "{$case}, {$read}, offers written {$written}";

                $this->assertSame($expected, self::served($make()->take(100)->get()), "{$at}: get");
                $this->assertSame(array_slice($expected, 0, 1), self::served([$make()->first()]), "{$at}: first");
                $this->assertSame(array_slice($expected, 4, 4), self::served($make()->paginate(4, 'page', 2)->items()), "{$at}: paginate page 2");
                $this->assertSame(array_slice($expected, 4, 4), self::served($make()->simplePaginate(4, 'page', 2)->items()), "{$at}: simplePaginate page 2");
                $this->assertSame(12, $make()->count(), "{$at}: count");
            }
        }

        // distinct() with the order's column left out of the select list, which SELECT DISTINCT cannot
        // be ordered by (PostgreSQL, MySQL, SQL Server): the page's models are read without DISTINCT.
        $labels  = fn (iterable $models) => collect($models)->map(fn (Model $m) => [$m->title, $m->label])->values()->all();
        $byPrice = array_map(fn (array $row) => [$row[0], $row[1]], $this->expected('price', 'asc'));
        $make    = fn () => $this->search()->select('jro_items.*', 'jro_offers.label')->distinct()->orderBy('jro_offers.price');
        $at      = "distinct(), the order not selected, offers written {$written}";

        $this->assertSame($byPrice, $labels($make()->take(100)->get()), "{$at}: get");
        $this->assertSame(array_slice($byPrice, 4, 4), $labels($make()->paginate(4, 'page', 2)->items()), "{$at}: paginate page 2");

        // An order on the model's own column, named bare though the joined table has one so named
        // too: every row of a model shares it, so the page's models are read as before, unordered,
        // where an ORDER BY "pos" over the join would be ambiguous.
        $titles = array_map(fn (int $n) => "kettle {$n}", range(11, 0));
        $this->assertSame($titles, $this->search()->orderBy('pos')->take(100)->get()->pluck('title')->all(), "own column, offers written {$written}");

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            return;
        }

        config(['scout.driver' => 'fuzzy-search', 'fuzzy-search.bm25.max_postings_per_term' => 50000]);
        $scout = fn (\Closure $query) => (new \Laravel\Scout\Builder(new JoinedRowScoutKettle, 'kettle'))
            ->query(fn ($q) => $query($q->join('jro_offers', 'jro_offers.item_id', '=', 'jro_items.id')));

        foreach (['asc', 'desc'] as $direction) {
            $make     = fn () => $scout(fn ($q) => $q->select('jro_items.*', 'jro_offers.label', 'jro_offers.price'))->orderBy('jro_offers.price', $direction);
            $expected = $this->expected('price', $direction);
            $at       = "scout, price {$direction}, offers written {$written}";

            $this->assertSame($expected, self::served($make()->take(100)->get()), "{$at}: get");
            $this->assertSame(array_slice($expected, 4, 4), self::served($make()->paginate(4, 'page', 2)->items()), "{$at}: paginate page 2");
        }

        $make = fn () => $scout(fn ($q) => $q->select('jro_items.*', 'jro_offers.label')->distinct())->orderBy('jro_offers.price');
        $at   = "scout, distinct(), the order not selected, offers written {$written}";

        $this->assertSame($byPrice, $labels($make()->take(100)->get()), "{$at}: get");
        $this->assertSame(array_slice($byPrice, 4, 4), $labels($make()->paginate(4, 'page', 2)->items()), "{$at}: paginate page 2");
    }

    public function test_offers_written_dearest_first(): void
    {
        $this->assertEachModelAtItsFirstRowByTheOrder(false);
    }

    public function test_offers_written_cheapest_first(): void
    {
        $this->assertEachModelAtItsFirstRowByTheOrder(true);
    }
}
