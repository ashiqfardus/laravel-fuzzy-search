<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WalkItem extends Model
{
    use Searchable, SoftDeletes;

    protected $table   = 'walk_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

class WalkLargeItem extends Model
{
    use Searchable;

    protected $table   = 'walk_large_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/**
 * ER-223, ER-224. An orderBy() index search whose ranking is capped at bm25.max_postings_per_term
 * reads its matches through the postings, as an EXISTS per row of the model table
 * (Bm25Scorer::whereRanked()).
 *  - PostgreSQL pulled that EXISTS up into a hash semi-join: the ordered page read the whole table
 *    (3.3 s warm, 23 to 86 s cold at 24M rows for a word in 1.3% of them) where a walk of the order
 *    column's index stops at the page (12 ms). An OFFSET 0 inside the EXISTS keeps it a correlated
 *    probe; the page read gets it while the walk to the page's end stays short.
 *  - SQL Server estimated the subquery's terms at its average (804 postings for 13,305 actual at 1M
 *    rows), and past the first page read the table whole through a hash semi-join (2 s for 12 ms on
 *    page 1). It now reads the terms' ids, inlined, and estimates each from its own histogram.
 * Every page serves the rows the listed read serves, on every database.
 */
class OrderedPageWalkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('walk_items');
        Schema::create('walk_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('tenant')->default(0)->index();
            $table->decimal('price', 10, 2)->index();
            $table->softDeletes();
        });

        // 400 rows: "gamma" in every 4th (a quarter of them), "delta" in every 40th; prices repeat, so
        // the key breaks the ties.
        foreach (array_chunk(range(0, 399), 200) as $chunk) {
            DB::table('walk_items')->insert(array_map(fn ($i) => [
                'title'  => trim(($i % 4 === 0 ? 'gamma ' : '') . ($i % 40 === 0 ? 'delta ' : '') . sprintf('item %03d', $i)),
                'tenant' => $i % 5,
                'price'  => ($i * 37) % 50,
            ], $chunk));
        }
        WalkItem::query()->chunkById(200, fn ($items) => app(IndexManager::class)->indexBatch($items));
        DB::table('walk_items')->whereRaw('id % 7 = 0')->update(['deleted_at' => '2020-01-01 00:00:00']);

        // A ranking capped at a few postings: every ordered read goes through the postings.
        config(['fuzzy-search.bm25.max_postings_per_term' => 5]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('walk_items');
        Schema::dropIfExists('walk_large_items');

        parent::tearDown();
    }

    /** @return array<int, string> the SQL of the reads $call makes through the postings (with $bindings, [sql, bindings]) */
    private function walks(\Closure $call, string $table = 'walk_items', bool $bindings = false): array
    {
        $reads = [];
        DB::listen(function ($query) use (&$reads, $table, $bindings) {
            if (str_contains($query->sql, $table) && str_contains($query->sql, 'fuzzy_index_postings')) {
                $reads[] = $bindings ? [$query->sql, $query->bindings] : $query->sql;
            }
        });

        $call();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $reads;
    }

    private function search(string $word)
    {
        return WalkItem::search($word)->typoTolerance(0)->useInvertedIndex()->orderBy('price');
    }

    public function test_postgres_fences_the_page_walk_under_the_bound_and_never_the_count(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('The fence is PostgreSQL\'s.');
        }

        $fenced = '/exists \(select .* offset 0\)/i';

        [$count, $page] = $this->walks(fn () => $this->search('gamma')->paginate(10, 'page', 2));
        $this->assertDoesNotMatchRegularExpression($fenced, $count, 'the COUNT');
        $this->assertMatchesRegularExpression($fenced, $page, 'page 2 of a word in a quarter of the rows');

        $take = $this->walks(fn () => $this->search('gamma')->take(5)->get());
        $this->assertDoesNotMatchRegularExpression($fenced, $take[0], 'take(5): its COUNT');
        $this->assertMatchesRegularExpression($fenced, end($take), 'take(5)');

        // Past the bound, a walk to the page's end reads more rows than the table read: no fence.
        // offset 59,990 + 10 for a word in a quarter of the rows is 240,000 rows walked.
        $deep = $this->walks(fn () => $this->search('gamma')->paginate(10, 'page', 6000));
        $this->assertDoesNotMatchRegularExpression($fenced, end($deep), 'page 6,000');
    }

    public function test_postgres_walks_the_order_columns_index_for_a_page_at_200k_rows(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('The plan is PostgreSQL\'s.');
        }

        Schema::create('walk_large_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->decimal('price', 10, 2)->index();
        });

        // 2,000 rows indexed as an app indexes them: "kappa item" in every 64th (1.6% of the rows,
        // as the word that read 24M rows whole), "plain item" in the rest.
        foreach (array_chunk(range(1, 2000), 500) as $chunk) {
            DB::table('walk_large_items')->insert(array_map(fn ($id) => [
                'id' => $id, 'title' => $id % 64 === 0 ? 'kappa item' : 'plain item', 'price' => ($id * 37) % 997,
            ], $chunk));
        }
        WalkLargeItem::query()->chunkById(500, fn ($items) => app(IndexManager::class)->indexBatch($items));

        // 198,000 more of the same two texts, their postings and documents copied from an indexed
        // row of each (64, 1), and the counts recomputed from them.
        $type = IndexManager::modelTypeId(WalkLargeItem::class);
        $doc  = DB::table('fuzzy_index_documents')->where('model_id', '1')->value('model_type');
        DB::statement("insert into walk_large_items (id, title, price) select g, case when g % 64 = 0 then 'kappa item' else 'plain item' end, (g * 37) % 997 from generate_series(2001, 200000) g");
        DB::statement("select setval(pg_get_serial_sequence('walk_large_items', 'id'), 200000)");
        DB::statement("insert into fuzzy_index_postings (term_id, model_type_id, model_id, column_id, frequency, doc_length)
            select p.term_id, p.model_type_id, g::text, p.column_id, p.frequency, p.doc_length from generate_series(2001, 200000) g
            join fuzzy_index_postings p on p.model_type_id = ? and p.model_id = case when g % 64 = 0 then '64' else '1' end", [$type]);
        DB::statement("insert into fuzzy_index_documents (model_type, model_id, doc_length)
            select d.model_type, g::text, d.doc_length from generate_series(2001, 200000) g
            join fuzzy_index_documents d on d.model_type = ? and d.model_id = case when g % 64 = 0 then '64' else '1' end", [$doc]);
        DB::statement('update fuzzy_index_model_terms mt set doc_count = (select count(distinct model_id) from fuzzy_index_postings p where p.term_id = mt.term_id and p.model_type_id = mt.model_type_id) where mt.model_type_id = ?', [$type]);
        DB::statement('update fuzzy_index_terms t set doc_count = (select coalesce(sum(doc_count), 0) from fuzzy_index_model_terms mt where mt.term_id = t.id) where t.id in (select term_id from fuzzy_index_model_terms where model_type_id = ?)', [$type]);
        DB::statement('update fuzzy_index_meta set total_docs = d.n, total_tokens = d.tokens, avg_doc_length = d.tokens::numeric / d.n
            from (select count(*) as n, sum(doc_length) as tokens from fuzzy_index_documents where model_type = ?) d where model_type = ?', [$doc, $doc]);
        DB::statement('analyze walk_large_items, fuzzy_index_postings, fuzzy_index_terms, fuzzy_index_documents, fuzzy_index_model_terms, fuzzy_index_meta');

        $page  = fn () => WalkLargeItem::search('kappa')->typoTolerance(0)->useInvertedIndex()->orderBy('price')->take(20)->get();
        $reads = $this->walks($page, 'walk_large_items', true);
        [$sql, $bindings] = end($reads);
        $plan = json_encode(DB::select('explain (format json) ' . $sql, $bindings));

        $this->assertStringContainsString('offset 0)', $sql);
        $this->assertStringContainsString('walk_large_items_price_index', $plan, 'a walk of the order column\'s index');
        $this->assertStringContainsString('SubPlan', $plan, 'a probe of the postings per row');
        $this->assertStringNotContainsString('Hash Semi Join', $plan);
        $this->assertSame(
            DB::table('walk_large_items')->where('title', 'kappa item')->orderBy('price')->orderBy('id')->limit(20)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $page()->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_sqlserver_reads_the_matches_by_the_terms_ids(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('The inlined term ids are SQL Server\'s.');
        }

        foreach ($this->walks(fn () => $this->search('gamma')->paginate(10, 'page', 3)) as $sql) {
            $this->assertMatchesRegularExpression('/\[fzr\]\.\[term_id\] in \(\d+\)/', $sql, $sql);
            $this->assertStringNotContainsString('from [fuzzy_index_terms]', $sql, $sql);
        }

        $this->assertSame(0, $this->search('nosuchword')->paginate(10)->total());
    }

    public function test_every_page_serves_what_the_listed_read_serves(): void
    {
        $listed = fn (string $word, \Closure $scope) => $scope(WalkItem::query())->where('title', 'like', "%{$word}%")
            ->orderBy('price')->orderBy('id')->pluck('title')->all();
        $shapes = [
            'live rows'     => fn ($query) => $query,
            'withTrashed()' => fn ($query) => $query->withTrashed(),
            'where tenant'  => fn ($query) => $query->where('tenant', 0), // every delta row is tenant 0
        ];

        foreach (['gamma', 'delta'] as $word) {
            foreach ($shapes as $shape => $scope) {
                $at     = "{$word}, {$shape}";
                $titles = $listed($word, $scope);
                $make   = fn () => WalkItem::searchOn($scope(WalkItem::query()), $word)->typoTolerance(0)->useInvertedIndex()->orderBy('price');

                $this->assertNotEmpty($titles, $at);
                $this->assertSame(array_slice($titles, 0, 5), $make()->take(5)->get()->pluck('title')->all(), "{$at}: take(5)");

                $served = [];
                for ($page = 1; $page <= (int) ceil(count($titles) / 7); $page++) {
                    $paginator = $make()->paginate(7, 'page', $page);
                    $this->assertSame(count($titles), $paginator->total(), "{$at}: total on page {$page}");
                    array_push($served, ...$paginator->pluck('title')->all());
                }
                $this->assertSame($titles, $served, "{$at}: the pages serve every match once, in order");
                $this->assertSame([], $make()->paginate(7, 'page', 6000)->pluck('title')->all(), "{$at}: a page past the end");
            }
        }
    }
}
