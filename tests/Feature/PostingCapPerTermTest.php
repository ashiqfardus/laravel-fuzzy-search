<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PostingCapArticle extends Model
{
    use Searchable;

    protected $table   = 'posting_cap_articles';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

/**
 * ER-225b. bm25.max_postings_per_term was one cap over the (document, term) rows of every term of
 * the query together, best weighted frequency first: a common term's rows filled it and evicted a
 * rare term's, the best match (both terms) included. With a cap of 5, "common zygote" served five
 * "common" rows and neither "zygote" row. Each term is now capped on its own, and a term in no more
 * documents than the cap is read whole; the ranking reports a cut when one term reached the cap.
 *
 * "common" is in 21 of the 22 rows, twice in the row that also holds "zygote" (its best row);
 * "zygote" is in two rows of the same length.
 */
class PostingCapPerTermTest extends TestCase
{
    private int $both;
    private int $alone;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('posting_cap_articles');
        Schema::create('posting_cap_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
        DB::table('posting_cap_articles')->insert(array_map(fn ($i) => ['title' => "common item {$i}"], range(1, 20)));
        DB::table('posting_cap_articles')->insert(['title' => 'zygote stands alone']);
        DB::table('posting_cap_articles')->insert(['title' => 'common common zygote']);
        $this->alone = (int) DB::table('posting_cap_articles')->where('title', 'zygote stands alone')->value('id');
        $this->both  = (int) DB::table('posting_cap_articles')->where('title', 'common common zygote')->value('id');

        app(IndexManager::class)->indexBatch(PostingCapArticle::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('posting_cap_articles');

        parent::tearDown();
    }

    private static function search(string $term): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder
    {
        return PostingCapArticle::search($term)->useInvertedIndex()->typoTolerance(0);
    }

    /** @return list<int> */
    private static function ids(iterable $rows): array
    {
        return array_map(fn ($row) => (int) $row->id, is_array($rows) ? $rows : $rows->all());
    }

    /** @return array{list<int|string>, bool} rank()'s documents and its cut */
    private static function rank(array $terms): array
    {
        $ranked = app(Bm25Scorer::class)->rank($terms, PostingCapArticle::class, ['title' => 10], $read);

        return [array_keys($ranked), $read['cut']];
    }

    public function test_a_common_terms_cap_keeps_a_rare_terms_rows(): void
    {
        config(['fuzzy-search.bm25.max_postings_per_term' => 5]);

        // "common": its best row, then four of the rest; "zygote": both of its rows.
        $ids = self::ids(self::search('common zygote')->get());
        $this->assertCount(6, $ids, 'get');
        $this->assertSame([$this->both, $this->alone], array_slice($ids, 0, 2), 'the row holding both terms first, then the rare term\'s other row');

        $this->assertSame($this->both, (int) self::search('common zygote')->first()->id, 'first');
        $this->assertSame(6, self::search('common zygote')->count(), 'count');
        $this->assertSame(6, self::search('common zygote')->paginate(4)->total(), 'paginate');
        $this->assertSame(array_slice($ids, 4), self::ids(self::search('common zygote')->paginate(4, 'page', 2)->items()), 'paginate, page 2');
        $this->assertSame(array_slice($ids, 4), self::ids(self::search('common zygote')->simplePaginate(4, 'page', 2)->items()), 'simplePaginate');

        // A filtered relevance search sees the rare term's rows the cap no longer evicts.
        $this->assertEqualsCanonicalizing([$this->both, $this->alone], self::ids(self::search('common zygote')->where('title', 'like', '%zygote%')->get()), 'where()');

        // One common term still stops at the cap.
        $this->assertCount(5, self::search('common')->get(), 'one common term');
    }

    public function test_a_term_under_the_cap_is_read_whole_and_the_cut_says_whether_a_term_reached_it(): void
    {
        foreach ([25 => [22, false], 21 => [22, false], 20 => [21, true], 2 => [3, true]] as $cap => [$documents, $cut]) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);
            [$ranked, $wasCut] = self::rank(['common', 'zygote']);

            $this->assertCount($documents, $ranked, "cap {$cap}");
            $this->assertSame($cut, $wasCut, "cap {$cap}: cut");
            $this->assertContains($this->alone, $ranked, "cap {$cap}: zygote read whole");
            $this->assertSame($this->both, $ranked[0], "cap {$cap}: the row holding both terms first");
        }

        config(['fuzzy-search.bm25.max_postings_per_term' => 1]);
        [$ranked, $wasCut] = self::rank(['zygote']);
        $this->assertCount(1, $ranked);
        $this->assertTrue($wasCut, 'a rare term over the cap');

        // Every term over the cap: each one's best row ("common" twice, then the lower key of the two "zygote" rows).
        [$ranked, $wasCut] = self::rank(['common', 'zygote']);
        $this->assertEqualsCanonicalizing([$this->both, $this->alone], $ranked);
        $this->assertTrue($wasCut);
        $this->assertEqualsCanonicalizing([$this->both, $this->alone], self::ids(self::search('common zygote')->get()));
    }

    /** orderBy() serves and counts every match, through the postings once the ranking is cut, and through its ids otherwise. */
    public function test_an_ordered_total_reads_every_match_whether_the_ranking_was_cut_or_not(): void
    {
        foreach ([5, 21, 25] as $cap) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);
            $make = fn () => self::search('common zygote')->orderBy('id');

            $this->assertSame(22, $make()->paginate(10)->total(), "cap {$cap}: paginate");
            $this->assertSame(22, $make()->count(), "cap {$cap}: count");
            $this->assertCount(2, $make()->paginate(10, 'page', 3)->items(), "cap {$cap}: the last page");
        }
    }

    /**
     * ER-225b-perf. rank() streamed its rows through the query builder's cursor(), a LazyCollection
     * that wraps every row in a Collection and two closures: about 0.45 µs a row, 20 ms of PHP for a
     * common word's 50,000 rows, 15 to 20% of a mid-term search. It reads them from the
     * connection's own cursor, as cheap per row as get(), and still holds a score per document, not
     * the rows. The caller of the connection's cursor() tells the two apart; the read is the same
     * on every driver, so SQLite pins it.
     */
    public function test_the_ranking_streams_its_rows_from_the_connections_own_cursor(): void
    {
        if ($this->dbDriver !== 'sqlite') {
            $this->markTestSkipped('The read shape is the same on every driver; the SQLite jobs pin it.');
        }

        $connection = DB::connection();
        $pdo        = $connection->getPdo(); // held: the in-memory database lives with it
        $recording  = new class ($pdo, $connection->getDatabaseName(), $connection->getTablePrefix(), $connection->getConfig()) extends \Illuminate\Database\SQLiteConnection {
            /** @var list<string|null> the class of each cursor() caller */
            public array $callers = [];

            public function cursor($query, $bindings = [], $useReadPdo = true)
            {
                $this->callers[] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null;

                return parent::cursor($query, $bindings, $useReadPdo);
            }
        };
        $name = $connection->getName();
        DB::purge($name);
        DB::extend($name, fn () => $recording);

        config(['fuzzy-search.bm25.max_postings_per_term' => 5]);
        foreach ([['zygote'], ['common'], ['common', 'zygote']] as $terms) { // whole, capped, both
            $recording->callers = [];
            $this->assertNotSame([], self::rank($terms)[0]);
            $this->assertSame([Bm25Scorer::class], $recording->callers, json_encode($terms));
        }
    }
}
