<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ZeroWeightItem extends Model
{
    use Searchable;

    protected $table   = 'zero_weight_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'body' => 5]];
}

/** The same table without the package's trait: FederatedSearch searches it with LIKE on the searchIn() columns. */
class ZeroWeightPlainItem extends Model
{
    protected $table   = 'zero_weight_items';
    protected $guarded = [];
    public $timestamps = false;
}

/**
 * TE-8 (pass 3): a column weighted 0 left the index search (Bm25Scorer::excludedColumns()) but not the
 * LIKE and extended searches, which still matched, served and counted a row that held the term only
 * there: searchIn(['email' => 0]) kept addresses searchable everywhere but on useInvertedIndex(). A
 * weight of 0 now takes the column out of the search on every path; with every column at 0 the search
 * matches nothing, as the index does.
 */
class ZeroWeightColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('zero_weight_items');
        Schema::create('zero_weight_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('body');
            $table->integer('cat');
        });
        // "teapot" holds "kettle" in its body only.
        DB::table('zero_weight_items')->insert([
            ['name' => 'kettle red', 'body' => 'steel', 'cat' => 1],
            ['name' => 'teapot', 'body' => 'kettle kelvin', 'cat' => 2],
            ['name' => 'mug', 'body' => 'plain', 'cat' => 2],
        ]);
        app(IndexManager::class)->indexBatch(ZeroWeightItem::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('zero_weight_items');
        parent::tearDown();
    }

    /** @return array<string, \Closure> path => the search, given the term and the searchIn() weights */
    private function paths(): array
    {
        return [
            'like'     => fn (string $term, array $weights) => ZeroWeightItem::search($term)->searchIn($weights)->using('simple'),
            'fuzzy'    => fn (string $term, array $weights) => ZeroWeightItem::search($term)->searchIn($weights),
            'orderBy'  => fn (string $term, array $weights) => ZeroWeightItem::search($term)->searchIn($weights)->orderBy('name'),
            'extended' => fn (string $term, array $weights) => ZeroWeightItem::search($term)->searchIn($weights)->extended(),
            'index'    => fn (string $term, array $weights) => ZeroWeightItem::search($term)->searchIn($weights)->useInvertedIndex()->typoTolerance(0),
        ];
    }

    public function test_a_zero_weight_column_is_out_of_the_search_on_every_path(): void
    {
        $names = fn ($rows) => collect($rows)->pluck('name')->all();

        foreach ($this->paths() as $path => $make) {
            $search = fn () => $make('kettle', ['name' => 10, 'body' => 0]);

            $this->assertSame(['kettle red'], $names($search()->get()), "{$path}: get");
            $this->assertSame('kettle red', $search()->first()?->name, "{$path}: first");
            $this->assertSame(1, $search()->count(), "{$path}: count");
            $page = $search()->paginate(10);
            $this->assertSame([1, ['kettle red']], [$page->total(), $names($page->items())], "{$path}: paginate");
            $this->assertSame(['kettle red'], $names($search()->simplePaginate(10)->items()), "{$path}: simplePaginate");

            // The weight is the builder's: the model's own weights still search both columns.
            $this->assertSame(['kettle red', 'teapot'], collect($make('kettle', ['name' => 10])->get())->pluck('name')->sort()->values()->all(), "{$path}: body weighted 5");

            // Every column weighted 0: nothing, as the index matches nothing.
            $none = fn () => $make('kettle', ['name' => 0, 'body' => 0]);
            $this->assertSame([[], 0, 0], [$names($none()->get()), $none()->count(), $none()->paginate(10)->total()], "{$path}: every column 0");
        }

        $facets = ZeroWeightItem::search('kettle')->searchIn(['name' => 10, 'body' => 0])->facet('cat')->getFacets()['cat'];
        $this->assertSame([1], array_map('intval', array_keys($facets)), 'getFacets');
    }

    public function test_extended_neither_matches_nor_scopes_a_zero_weight_column(): void
    {
        $search = fn (string $query) => ZeroWeightItem::search('')->extended($query)->searchIn(['name' => 10, 'body' => 0]);

        // A NOT judges the searched columns only: "teapot" holds "kettle" in its body alone.
        $this->assertSame(['mug', 'teapot'], $search('!kettle')->get()->pluck('name')->sort()->values()->all(), 'NOT');
        $this->assertSame(['kettle red'], $search('name:kettle')->get()->pluck('name')->all(), 'name:kettle');

        // A field scope on it is an unknown field, as on a column the builder does not search.
        try {
            $search('body:kettle')->get();
            $this->fail('body:kettle searched a column weighted 0');
        } catch (QuerySyntaxException $e) {
            $this->assertSame('Unknown search field "body". Searchable fields: name.', $e->getMessage());
        }
    }

    public function test_federated_search_leaves_a_zero_weight_column_out_with_the_trait_or_without(): void
    {
        foreach ([ZeroWeightItem::class, ZeroWeightPlainItem::class] as $model) {
            $search = fn (array $weights) => \Ashiqfardus\LaravelFuzzySearch\FederatedSearch::across([$model])->search('kettle')->using('simple')->searchIn($weights);

            $this->assertSame(['kettle red'], $search(['name' => 10, 'body' => 0])->get()->pluck('name')->all(), $model);
            $this->assertSame(['kettle red', 'teapot'], $search(['name' => 10, 'body' => 5])->get()->pluck('name')->sort()->values()->all(), "{$model}: body weighted 5");
            $this->assertSame([], $search(['name' => 0, 'body' => 0])->get()->all(), "{$model}: every column 0");
        }
    }

    public function test_suggest_table_scan_offers_no_word_from_a_zero_weight_column(): void
    {
        $suggest = fn (array $weights) => ZeroWeightItem::search('ke')->searchIn($weights)->suggestFrom('table')->suggest(10);

        $this->assertSame(['kettle', 'kettle red'], $suggest(['name' => 10, 'body' => 0]));
        $this->assertContains('kelvin', $suggest(['name' => 10]), 'body weighted 5');
        $this->assertSame([], $suggest(['name' => 0, 'body' => 0]), 'every column 0');
    }
}
