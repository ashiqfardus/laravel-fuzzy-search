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

class CappedReadItem extends Model
{
    use Searchable, SoftDeletes;

    protected $table   = 'capped_read_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

class CappedReadScoutItem extends Model
{
    use \Laravel\Scout\Searchable, Searchable {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'capped_read_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

class CappedReadCode extends Model
{
    use Searchable;

    protected $table      = 'capped_read_codes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * S3 (scale ladder). An orderBy() index search whose ranking is capped at
 * bm25.max_postings_per_term is restricted to the matches through the postings, since rank() left
 * matches out. On MySQL that read was an EXISTS probe of the postings per row of the model table
 * (SEMIJOIN(FIRSTMATCH)), MariaDB's plan read the table whole too, so the ordered page and its COUNT
 * cost the table, not the matches: 8.7–11 s at 6M rows for a word in 1% of them. On MySQL and
 * MariaDB the read is now driven from the postings: the distinct ids of the matched terms, joined
 * to the key, one key lookup per match. Every match is served as before: SoftDeletes, a column of
 * the model named like the postings' own, keys that differ only by case.
 */
class CappedOrderedReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('capped_read_items');
        Schema::create('capped_read_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('model_id')->default(0); // a morph-style column the postings have too
            $table->decimal('price', 10, 2);
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('capped_read_items');
        Schema::dropIfExists('capped_read_codes');

        parent::tearDown();
    }

    /** $rows items; every $every-th is a "beta" item, the rest "alpha"; the betas are indexed, as each of $modelClasses. */
    private function seedItems(int $rows, int $every, string ...$modelClasses): void
    {
        foreach (array_chunk(range(0, $rows - 1), 1000) as $chunk) {
            DB::table('capped_read_items')->insert(array_map(fn ($i) => [
                'title'    => sprintf('%s %05d', $i % $every === 0 ? 'beta' : 'alpha', $i),
                'model_id' => $i % 3,
                'price'    => ($i * 7919) % 1000,
            ], $chunk));
        }

        foreach ($modelClasses ?: [CappedReadItem::class] as $modelClass) {
            $modelClass::query()->where('title', 'like', 'beta%')->chunkById(500, fn ($items) => app(IndexManager::class)->indexBatch($items));
        }
    }

    public function test_a_capped_ordered_read_serves_what_the_listed_read_serves(): void
    {
        $this->seedItems(240, 4); // 60 betas
        DB::table('capped_read_items')->where('title', 'like', 'beta%')->whereRaw('id % 5 = 0')->update(['deleted_at' => '2020-01-01 00:00:00']);

        $expected = fn (\Closure $scope) => $scope(CappedReadItem::query())->where('title', 'like', 'beta%')
            ->orderBy('price')->orderBy('id')->pluck('title')->all();
        $shapes = [
            'live rows'          => fn ($query) => $query,
            'withTrashed()'      => fn ($query) => $query->withTrashed(),
            'onlyTrashed()'      => fn ($query) => $query->onlyTrashed(),
            'where on model_id'  => fn ($query) => $query->where('model_id', 1), // unqualified, as the model names it
        ];

        // A ranking that holds every match is listed by key; a capped one is read through the postings.
        foreach (['whole ranking' => 50000, 'capped ranking' => 7] as $ranking => $cap) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);

            foreach ($shapes as $shape => $scope) {
                $at     = "{$ranking}, {$shape}";
                $titles = $expected($scope);
                $make   = fn () => CappedReadItem::searchOn($scope(CappedReadItem::query()), 'beta')->typoTolerance(0)->useInvertedIndex()->orderBy('price');

                $this->assertNotEmpty($titles, $at);
                $this->assertSame($titles, $make()->take(100)->get()->pluck('title')->all(), "{$at}: get");
                $this->assertSame($titles[0], $make()->first()?->title, "{$at}: first");
                $this->assertSame(count($titles), $make()->count(), "{$at}: count");

                $served = [];
                for ($page = 1; $page <= intdiv(count($titles) + 6, 7); $page++) {
                    $paginator = $make()->paginate(7, 'page', $page);
                    $this->assertSame(count($titles), $paginator->total(), "{$at}: total on page {$page}");
                    $served = [...$served, ...$paginator->pluck('title')->all()];
                }
                $this->assertSame($titles, $served, "{$at}: the pages serve every match once, in order");
                $this->assertSame(array_slice($titles, 7, 7), $make()->simplePaginate(7, 'page', 2)->pluck('title')->all(), "{$at}: simplePaginate");
            }
        }
    }

    public function test_scout_reads_a_capped_ordered_page_as_the_listed_one(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedItems(240, 4, CappedReadScoutItem::class);
        config(['scout.driver' => 'fuzzy-search']);

        // Scout breaks a tie in the order by key, descending.
        $titles = CappedReadScoutItem::query()->where('title', 'like', 'beta%')->where('model_id', '>', 0)->orderBy('price')->orderByDesc('id')->pluck('title')->all();

        foreach (['whole ranking' => 50000, 'capped ranking' => 7] as $ranking => $cap) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);
            $make = fn () => (new \Laravel\Scout\Builder(new CappedReadScoutItem, 'beta'))->orderBy('price')->query(fn ($query) => $query->where('model_id', '>', 0));

            $served = [];
            for ($page = 1; $page <= intdiv(count($titles) + 6, 7); $page++) {
                $paginator = $make()->paginate(7, 'page', $page);
                $this->assertSame(count($titles), $paginator->total(), "{$ranking}: total on page {$page}");
                $served = [...$served, ...collect($paginator->items())->pluck('title')->all()];
            }
            $this->assertSame($titles, $served, "{$ranking}: the pages serve every match once, in order");
        }
    }

    /** Keys that differ only by case are two models, each served once (CaseSensitiveModelIdTest's key column). */
    public function test_a_capped_ordered_read_keeps_keys_that_differ_only_by_case_apart(): void
    {
        if ($this->dbDriver === 'sqlsrv') {
            $this->markTestSkipped('SQL Server keeps model_id case-insensitive (a documented limit): keys that differ only by case cannot both be indexed there.');
        }

        $this->createCodes(match ($this->dbDriver) {
            'pgsql'            => 'C',
            'mysql', 'mariadb' => 'utf8mb4_bin',
            default            => 'BINARY',
        });
        $rows = [['code' => 'aBc', 'name' => 'zebra apple'], ['code' => 'AbC', 'name' => 'zebra banana']];
        foreach (range(1, 10) as $i) {
            $rows[] = ['code' => sprintf('k%02d', $i), 'name' => sprintf('zebra cherry %02d', $i)];
        }
        CappedReadCode::insert($rows);
        app(IndexManager::class)->indexBatch(CappedReadCode::all());

        $names = array_column($rows, 'name');
        sort($names);

        foreach (['whole ranking' => 50000, 'capped ranking' => 3] as $ranking => $cap) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);
            $make = fn () => CappedReadCode::search('zebra')->typoTolerance(0)->useInvertedIndex()->orderBy('name');

            $this->assertSame($names, $make()->take(100)->get()->pluck('name')->all(), "{$ranking}: get");
            $this->assertSame(['zebra apple', 'zebra banana'], $make()->paginate(5)->take(2)->pluck('name')->all(), "{$ranking}: page 1");
            $this->assertSame(['aBc', 'AbC'], $make()->paginate(5)->take(2)->pluck('code')->all(), "{$ranking}: each key's own row");
            $this->assertSame(12, $make()->paginate(5)->total(), "{$ranking}: total");
        }
    }

    /**
     * MySQL and MariaDB: a case-insensitive key column, whose key aBc the postings also hold as ABC
     * (a row deleted without its model events, then created again under another case). The read
     * looks the key up under the key's own collation, which matches both, and keeps the byte-wise
     * comparison the postings subquery made, so the row is one match, served once.
     */
    public function test_a_capped_ordered_read_serves_a_row_once_when_the_postings_hold_its_key_in_two_cases(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('A case-insensitive key column is MySQL\'s and MariaDB\'s default; the CI MySQL and MariaDB jobs run this.');
        }

        $this->createCodes('utf8mb4_unicode_ci');
        CappedReadCode::insert(['code' => 'ABC', 'name' => 'zebra old']);
        app(IndexManager::class)->indexBatch(CappedReadCode::all());
        DB::table('capped_read_codes')->delete();

        $rows = [['code' => 'aBc', 'name' => 'zebra apple']];
        foreach (range(1, 6) as $i) {
            $rows[] = ['code' => sprintf('k%02d', $i), 'name' => sprintf('zebra cherry %02d', $i)];
        }
        CappedReadCode::insert($rows);
        app(IndexManager::class)->indexBatch(CappedReadCode::all());

        config(['fuzzy-search.bm25.max_postings_per_term' => 3]);
        $make = fn () => CappedReadCode::search('zebra')->typoTolerance(0)->useInvertedIndex()->orderBy('name');

        $this->assertSame(array_column($rows, 'name'), $make()->take(100)->get()->pluck('name')->all());
        $this->assertSame(7, $make()->paginate(3)->total());
        $this->assertSame(['zebra apple', 'zebra cherry 01', 'zebra cherry 02'], $make()->paginate(3)->pluck('name')->all());
        $this->assertSame(['zebra cherry 03', 'zebra cherry 04', 'zebra cherry 05'], $make()->paginate(3, 'page', 2)->pluck('name')->all());
    }

    /**
     * MySQL and MariaDB: the rows a capped ordered search reads (InnoDB's Handler_read_* counters,
     * which count every row a statement reads, index entries included) are bounded by its matches,
     * not by the model table: 400 matches in 20,000 rows. Through the postings subquery the COUNT
     * and the page each read the table whole: more than 40,000 rows.
     */
    public function test_a_capped_ordered_read_reads_the_matches_not_the_table(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL\'s and MariaDB\'s plan (PostgreSQL and SQL Server read the table in tens of milliseconds at 1M rows, and keep the subquery); the CI MySQL and MariaDB jobs run this.');
        }

        $scout = class_exists(\Laravel\Scout\EngineManager::class);
        $this->seedItems(20000, 50, ...($scout ? [CappedReadItem::class, CappedReadScoutItem::class] : [CappedReadItem::class]));
        DB::statement('ANALYZE TABLE capped_read_items, fuzzy_index_postings, fuzzy_index_terms, fuzzy_index_documents');
        config(['fuzzy-search.bm25.max_postings_per_term' => 100, 'scout.driver' => 'fuzzy-search']);

        $titles = CappedReadItem::query()->where('title', 'like', 'beta%')->orderBy('price')->orderBy('id')->pluck('title')->all();
        $this->assertCount(400, $titles);

        $make = fn () => CappedReadItem::search('beta')->typoTolerance(0)->useInvertedIndex()->orderBy('price');
        $make()->count(); // warms the once-per-process reads, such as model_id's collation

        $reads = $this->rowsRead(function () use ($make, $titles) {
            $page = $make()->paginate(10, 'page', 3);
            $this->assertSame(400, $page->total());
            $this->assertSame(array_slice($titles, 20, 10), $page->pluck('title')->all());
        });
        $this->assertLessThan(10000, $reads, "paginate(): {$reads} rows read for 400 matches in 20,000 rows");

        $reads = $this->rowsRead(fn () => $this->assertSame(400, $make()->count()));
        $this->assertLessThan(10000, $reads, "count(): {$reads} rows read");

        if ($scout) {
            $reads = $this->rowsRead(function () {
                $page = (new \Laravel\Scout\Builder(new CappedReadScoutItem, 'beta'))->orderBy('price')->paginate(10, 'page', 3);
                $this->assertSame(400, $page->total());
            });
            $this->assertLessThan(10000, $reads, "Scout paginate(): {$reads} rows read");
        }
    }

    /** The rows InnoDB read while $call ran, every table and index together. */
    private function rowsRead(\Closure $call): int
    {
        $counters = fn () => array_sum(array_map(fn ($row) => (int) $row->Value, DB::select("SHOW SESSION STATUS LIKE 'Handler_read%'")));

        $before = $counters();
        $call();

        return $counters() - $before;
    }

    private function createCodes(string $collation): void
    {
        Schema::dropIfExists('capped_read_codes');
        Schema::create('capped_read_codes', function (Blueprint $table) use ($collation) {
            $table->string('code', 40)->collation($collation)->primary();
            $table->string('name');
        });
    }
}
