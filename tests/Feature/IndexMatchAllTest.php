<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MatchAllItem extends Model
{
    use Searchable;

    protected $table   = 'match_all_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'body' => 5]];
}

/**
 * TE-11. tokenize()->matchAll() ("all tokens must match") was read by the LIKE and fuzzy paths
 * only: useInvertedIndex() ranked, served and counted every row holding any of the words, so a
 * "red kettle" filter listed red mugs and blue kettles. On the index path a match now holds each of
 * the words, or one of its typo expansions or synonyms, in any column.
 */
class IndexMatchAllTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('match_all_items');
        Schema::create('match_all_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('body')->nullable();
            $table->integer('rank');
        });
        DB::table('match_all_items')->insert([
            ['name' => 'red kettle', 'body' => null, 'rank' => 1],
            ['name' => 'red mug', 'body' => null, 'rank' => 2],
            ['name' => 'blue kettle', 'body' => null, 'rank' => 3],
            ['name' => 'teapot', 'body' => 'red enamel kettle', 'rank' => 4],
            ['name' => 'plate', 'body' => 'white', 'rank' => 5],
            // "kettle" in at most half of the rows: a word in more is no typo expansion (ER-225a).
            ['name' => 'spoon', 'body' => null, 'rank' => 6],
        ]);
        app(IndexManager::class)->indexBatch(MatchAllItem::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('match_all_items');

        parent::tearDown();
    }

    /** @return array{list<string>, int, int, list<string>, ?string} names (sorted), count(), total, a page's names, first() */
    private function serve(\Closure $make): array
    {
        $names = fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all();

        return [$names($make()->get()), $make()->count(), $make()->paginate(10)->total(), $names($make()->simplePaginate(10)->items()), $make()->first()?->name];
    }

    public function test_match_all_on_the_index_path_serves_the_rows_holding_every_word(): void
    {
        $both = ['red kettle', 'teapot'];

        foreach ([
            'relevance'          => fn () => MatchAllItem::search('red kettle')->tokenize()->matchAll()->useInvertedIndex()->typoTolerance(0),
            'orderBy'            => fn () => MatchAllItem::search('red kettle')->tokenize()->matchAll()->useInvertedIndex()->typoTolerance(0)->orderBy('rank'),
            'a typo of a word'   => fn () => MatchAllItem::search('red kettla')->tokenize()->matchAll()->useInvertedIndex()->typoTolerance(1),
            'capped, orderBy'    => function () {
                config(['fuzzy-search.bm25.max_postings_per_term' => 1]);

                return MatchAllItem::search('red kettle')->tokenize()->matchAll()->useInvertedIndex()->typoTolerance(0)->orderBy('rank');
            },
        ] as $label => $make) {
            [$get, $count, $total, $page, $first] = $this->serve($make);
            config(['fuzzy-search.bm25.max_postings_per_term' => 50000]);

            $this->assertSame($both, $get, "{$label}: get");
            $this->assertSame(2, $count, "{$label}: count()");
            $this->assertSame(2, $total, "{$label}: total");
            $this->assertSame($both, $page, "{$label}: simplePaginate");
            $this->assertContains($first, $both, "{$label}: first");
        }

        // The LIKE path, for reference, and matchAny() on the index: every row with either word.
        $this->assertSame($both, $this->serve(fn () => MatchAllItem::search('red kettle')->tokenize()->matchAll()->using('simple'))[0]);
        $this->assertSame(['blue kettle', 'red kettle', 'red mug', 'teapot'], $this->serve(fn () => MatchAllItem::search('red kettle')->tokenize()->matchAny()->useInvertedIndex()->typoTolerance(0))[0]);
    }
}
