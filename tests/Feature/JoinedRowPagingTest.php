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

    /**
     * ER-189. No ORDER BY takes PostgreSQL's json, so the tiebreak leaves a joined json column out:
     * which it can tell only from the column types. Where they cannot be read (Laravel 10 before
     * Schema::getColumns(), simulated through typesOn()'s memo as ShadowWriteGuardTest does),
     * PostgreSQL and SQL Server keep the key alone, as before VC-7, so the join still reads; the
     * other databases order every type. A SELECT DISTINCT compares every column it selects anyway,
     * so its order needs no types.
     */
    public function test_a_column_no_order_by_takes_is_left_out_and_unreadable_types_keep_the_key_alone(): void
    {
        $I = self::ITEMS;
        $N = self::NOTES;
        Schema::table($N, fn (Blueprint $table) => $table->json('meta')->nullable());
        DB::table($N)->update(['meta' => '{"a":1}']);

        $stable   = fn () => JoinedRowPagingItem::search('alpha')->join($N, "{$N}.item_id", '=', "{$I}.id")->stableRanking();
        $distinct = fn () => JoinedRowPagingItem::search('alpha')->join($N, "{$N}.item_id", '=', "{$I}.id")->select("{$I}.*", "{$N}.label")->distinct();
        $sql      = fn ($search) => str_replace(['`', '[', ']'], '"', $search->toSql());
        $typed    = in_array($this->dbDriver, ['pgsql', 'sqlsrv'], true); // json() is nvarchar(max) on SQL Server, json on PostgreSQL

        try {
            foreach (['readable', 'unreadable'] as $types) {
                \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::reset();
                if ($types === 'unreadable') {
                    $key = \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::connectionKey(DB::connection());
                    (new \ReflectionProperty(\Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::class, 'types'))
                        ->setValue(null, ["{$key}|{$I}" => [], "{$key}|{$N}" => []]);
                    $this->assertSame([], \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::typesOn(DB::connection(), $N));
                }

                $this->assertStringEndsWith(match (true) {
                    $typed && $types === 'unreadable'          => "\"{$I}\".\"id\" asc",
                    $this->dbDriver === 'pgsql'                => "\"{$I}\".\"id\" asc, \"{$N}\".\"id\" asc, \"{$N}\".\"item_id\" asc, \"{$N}\".\"label\" asc",
                    default                                    => "\"{$I}\".\"id\" asc, \"{$N}\".\"id\" asc, \"{$N}\".\"item_id\" asc, \"{$N}\".\"label\" asc, \"{$N}\".\"meta\" asc",
                }, $sql($stable()), "{$types} types");
                $this->assertCount(5, $stable()->paginate(5, 'page', 3)->items(), "{$types} types");
                $this->assertNotEmpty($stable()->get(), "{$types} types");

                $this->assertStringEndsWith("order by \"{$I}\".\"id\" asc, \"{$N}\".\"label\" asc", $sql($distinct()), "{$types} types, distinct()");
                $this->assertCount(5, $distinct()->paginate(5, 'page', 3)->items(), "{$types} types, distinct()");
            }
        } finally {
            \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::reset();
        }
    }

    /**
     * R13-L6 (ER-195). PostgreSQL's geometric types have no default btree operator class, nor does an
     * array of one (or of json), so no ORDER BY takes them either: such a joined column threw 42883
     * ("could not identify an ordering operator for type point") under stableRanking(). They are left
     * out as json is, and so is SQL Server's geometry; MySQL's and MariaDB's POINT orders, and stays.
     */
    public function test_a_joined_geometric_column_no_order_by_takes_is_left_out(): void
    {
        $I       = self::ITEMS;
        $N       = self::NOTES;
        $columns = match ($this->dbDriver) {
            'pgsql' => [
                'g_point'   => ['point', "point '(1,2)'"],
                'g_line'    => ['line', "line '{1,-1,0}'"],
                'g_lseg'    => ['lseg', "lseg '[(0,0),(1,1)]'"],
                'g_box'     => ['box', "box '((0,0),(1,1))'"],
                'g_path'    => ['path', "path '[(0,0),(1,1)]'"],
                'g_polygon' => ['polygon', "polygon '((0,0),(1,1),(1,0))'"],
                'g_circle'  => ['circle', "circle '<(0,0),1>'"],
                'g_points'  => ['point[]', "array[point '(1,2)']"],
                'g_jsons'   => ['json[]', "array['{\"a\":1}'::json]"],
            ],
            'mysql', 'mariadb' => ['g_point' => ['point', "ST_GeomFromText('POINT(1 2)')"]],
            'sqlsrv' => ['g_shape' => ['geometry', "geometry::STGeomFromText('POINT(1 2)', 0)"]],
            default  => [],
        };
        if ($columns === []) {
            $this->markTestSkipped('SQLite has no geometric column type.');
        }
        if (!method_exists(DB::connection()->getSchemaBuilder(), 'getColumns')) {
            $this->markTestSkipped('Laravel 10 before 10.30 reads no column types: the key alone, as the json test above asserts.');
        }

        $wrapped = DB::connection()->getQueryGrammar()->wrapTable($N);
        foreach ($columns as $name => [$type, $value]) {
            DB::statement("alter table {$wrapped} add " . ($this->dbDriver === 'sqlsrv' ? '' : 'column ') . "{$name} {$type} null");
            DB::statement("update {$wrapped} set {$name} = {$value}");
        }
        \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::reset();

        $join   = fn ($search) => $search->join($N, "{$N}.item_id", '=', "{$I}.id")->stableRanking();
        $kept   = in_array($this->dbDriver, ['mysql', 'mariadb'], true) ? ", \"{$N}\".\"g_point\" asc" : '';
        $shapes = [
            'stableRanking()' => fn () => $join(JoinedRowPagingItem::search('alpha')),
            'extended()'      => fn () => $join(JoinedRowPagingItem::search('')->extended('alpha')),
        ];

        foreach ($shapes as $label => $make) {
            $this->assertStringEndsWith("\"{$I}\".\"id\" asc, \"{$N}\".\"id\" asc, \"{$N}\".\"item_id\" asc, \"{$N}\".\"label\" asc{$kept}", str_replace(['`', '[', ']'], '"', $make()->toSql()), $label);

            $first  = $make()->paginate(5);
            $second = self::rows($make()->paginate(5, 'page', 2)->items());
            $this->assertSame(94, $first->total(), $label);
            $this->assertCount(5, $first->items(), $label);
            $this->assertCount(5, $second, "{$label} page 2");
            $this->assertSame([], array_intersect(self::rows($first->items()), $second), "{$label} page 2");
            $this->assertCount(94, $make()->limit(100)->get(), "{$label} get()");
        }
    }

    /**
     * ER-189 (UF-6). An unordered distinct() without the key is read by its selected columns, an
     * aliased one by its alias: an alias with the name of another selected column made that ORDER BY
     * ambiguous, and PostgreSQL rejected it (42702). Such an alias is read by its column.
     */
    public function test_a_distinct_alias_named_like_another_selected_column_is_read_by_its_column(): void
    {
        $I = self::ITEMS;
        $N = self::NOTES;
        config(['fuzzy-search.max_candidates' => 7]);

        $join   = fn () => JoinedRowPagingItem::search('alpha')->join($N, "{$N}.item_id", '=', "{$I}.id")->distinct();
        $shapes = [
            'a column'  => [fn () => $join()->select("{$I}.name", "{$N}.label as name"), "order by \"{$I}\".\"name\" asc, \"{$N}\".\"label\" asc"],
            'table.*'   => [fn () => $join()->select("{$N}.*", "{$I}.name as label"), "order by \"{$N}\".\"id\" asc, \"{$N}\".\"item_id\" asc, \"{$N}\".\"label\" asc, \"{$I}\".\"name\" asc"],
        ];

        foreach ($shapes as $label => [$make, $order]) {
            $this->assertStringEndsWith($order, str_replace(['`', '[', ']'], '"', $make()->toSql()), $label);
            $this->assertNotEmpty($make()->get(), $label);
            $this->assertCount(5, $make()->paginate(5, 'page', 3)->items(), "{$label}, past max_candidates");
        }
    }

    /** @return string[] each row as "item key/label" */
    private static function rows(array $items): array
    {
        return array_map(fn ($row) => ($row->item_id ?? $row->id) . '/' . ($row->label ?? $row->note), $items);
    }
}
