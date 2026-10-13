<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class JoinedRowPagingItem extends Model
{
    use Searchable;

    protected $table   = 'jrp_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'body' => 5]];
}

/**
 * VC-7. On the LIKE and extended paths the pages are one SQL order: the window PHP rescoring ranks,
 * then one OFFSET read a page. stableRanking() and an unordered distinct() end that order on the key,
 * which a one-to-many join repeats once per joined row, so the rows of one model tied, and MySQL and
 * PostgreSQL broke the tie differently from one read to the next: paginate() served some joined rows
 * twice and others never, and a distinct() select of the joined table's columns had no order at all.
 * Under a join the key is now followed by the selected columns.
 */
class JoinedRowPagingTest extends TestCase
{
    private const ITEMS = 'jrp_items';
    private const NOTES = 'jrp_notes';

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists(self::NOTES);
        Schema::dropIfExists(self::ITEMS);
        Schema::create(self::ITEMS, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('body');
        });
        Schema::create(self::NOTES, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            $table->string('label');
        });

        // Every item matches alike, so relevance ties throughout; names repeat in pairs, so an
        // orderBy(name) ties across items too.
        for ($i = 0; $i < 40; $i++) {
            DB::table(self::ITEMS)->insert(['name' => sprintf('alpha %03d', intdiv($i, 2)), 'body' => 'b' . ($i % 7)]);
        }

        // Two or three notes an item, written in an order that is neither the key's nor the label's.
        $notes = [];
        foreach (DB::table(self::ITEMS)->orderBy('id')->pluck('id') as $k => $id) {
            foreach ($k % 3 === 0 ? ['y', 'x', 'z'] : ($k % 2 ? ['x', 'y'] : ['y', 'x']) as $label) {
                $notes[] = ['item_id' => $id, 'label' => $label];
            }
        }
        usort($notes, fn (array $a, array $b) => crc32($a['item_id'] . $a['label']) <=> crc32($b['item_id'] . $b['label']));
        foreach (array_chunk($notes, 50) as $chunk) {
            DB::table(self::NOTES)->insert($chunk);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::NOTES);
        Schema::dropIfExists(self::ITEMS);
        parent::tearDown();
    }

    public function test_every_joined_row_is_served_once_across_the_pages(): void
    {
        $I = self::ITEMS;
        $N = self::NOTES;
        config(['fuzzy-search.max_candidates' => 7]);

        $join   = fn ($query) => $query->join($N, "{$N}.item_id", '=', "{$I}.id");
        $model  = fn () => $join(JoinedRowPagingItem::search('alpha'));
        $shapes = [
            // name => [the search, the end of its ORDER BY]
            'stableRanking()'       => [fn () => $model()->select("{$I}.*", "{$N}.label")->stableRanking(), "\"{$I}\".\"id\" asc, \"{$N}\".\"label\" asc"],
            'extended()'            => [fn () => $join(JoinedRowPagingItem::search('')->extended('alpha'))->select("{$I}.*", "{$N}.label")->stableRanking(), "\"{$I}\".\"id\" asc, \"{$N}\".\"label\" asc"],
            'orderBy(name)'         => [fn () => $model()->select("{$I}.*", "{$N}.label")->orderBy("{$I}.name")->stableRanking(), "order by \"{$I}\".\"name\" asc, \"{$I}\".\"id\" asc, \"{$N}\".\"label\" asc"],
            'select *'              => [fn () => $model()->stableRanking(), "\"{$I}\".\"id\" asc, \"{$N}\".\"id\" asc, \"{$N}\".\"item_id\" asc, \"{$N}\".\"label\" asc"],
            // the alias names the ordered column again: not twice (SQL Server rejects that, 169)
            'orderBy(notes.label)'  => [fn () => $model()->select("{$I}.*", "{$N}.label as note")->orderBy("{$N}.label")->stableRanking(), "order by \"{$N}\".\"label\" asc, \"{$I}\".\"id\" asc"],
            'query builder'         => [fn () => $join((new SearchBuilder(DB::table($I)->select("{$I}.*", "{$N}.label"), app(FuzzySearch::class)))
                ->search('alpha')->searchIn(['name' => 10, 'body' => 5]))->stableRanking(), "\"{$I}\".\"id\" asc, \"{$N}\".\"label\" asc"],
            'distinct()'            => [fn () => $model()->select("{$I}.*", "{$N}.label")->distinct(), "order by \"{$I}\".\"id\" asc, \"{$N}\".\"label\" asc"],
            'distinct() notes.*'    => [fn () => $model()->select("{$N}.*")->distinct(), "order by \"{$N}\".\"id\" asc, \"{$N}\".\"item_id\" asc, \"{$N}\".\"label\" asc"],
        ];

        $expected = DB::table($N)->get()->map(fn ($row) => $row->item_id . '/' . $row->label)->sort()->values()->all();
        $this->assertCount(94, $expected);

        foreach ($shapes as $label => [$make, $order]) {
            $this->assertStringEndsWith($order, str_replace(['`', '[', ']'], '"', $make()->toSql()), "{$label} toSql");

            $first  = $make()->paginate(5);
            $served = [];
            for ($page = 1; $page <= $first->lastPage(); $page++) {
                $served = [...$served, ...self::rows($make()->paginate(5, 'page', $page)->items())];
            }
            sort($served);
            $this->assertSame(94, $first->total(), "{$label} total");
            $this->assertSame($expected, $served, "{$label} paginate()");

            // simplePaginate() serves the window: its first max_candidates rows, each once.
            $served = [];
            for ($page = 1; $page <= 3; $page++) {
                $served = [...$served, ...self::rows($make()->simplePaginate(5, 'page', $page)->items())];
            }
            $this->assertCount(7, $served, "{$label} simplePaginate()");
            $this->assertSame($served, array_values(array_unique($served)), "{$label} simplePaginate()");
            $this->assertSame([], array_diff($served, $expected), "{$label} simplePaginate()");
        }
    }

    /** @return string[] each row as "item key/label" */
    private static function rows(array $items): array
    {
        return array_map(fn ($row) => ($row->item_id ?? $row->id) . '/' . ($row->label ?? $row->note), $items);
    }
}
