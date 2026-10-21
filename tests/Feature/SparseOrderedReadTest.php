<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SparseReadItem extends Model
{
    use Searchable, SoftDeletes;

    protected $table   = 'sparse_read_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

class SparseReadScoutItem extends Model
{
    use \Laravel\Scout\Searchable, Searchable {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }
    use SoftDeletes;

    protected $table   = 'sparse_read_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

class SparsePlanItem extends Model
{
    use Searchable;

    protected $table   = 'sparse_plan_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

class SparseReadUuid extends Model
{
    use HasUuids, Searchable;

    protected $table   = 'sparse_read_uuids';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

class SparseReadCode extends Model
{
    use Searchable;

    protected $table      = 'sparse_read_codes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/**
 * ER-229. SQL Server lists no ranking longer than bm25.candidate_chunk, so an orderBy() index
 * search of a word with more matches is restricted through the postings (Bm25Scorer::whereRanked()).
 * That EXISTS compared model_id with a cast of the integer key, so nothing could look a model up
 * from its postings: the plan read the whole table, a cast and a hash probe per row (6.6 s at 3M
 * rows for a word in 395 of them; 23 ms at 1M, where its 130 matches were listed). The EXISTS now
 * also compares model_id with the key in the key's type (an integer or a uniqueidentifier beside
 * the cast, an nvarchar key in model_id's collation as it is), so the optimizer can look the
 * matches up by key, or still probe a row's postings by it. A uuid key on PostgreSQL read its whole table the same way (its cast
 * to varchar keeps the key's index out); a sparse word's string ranking is bound there now, as on
 * MySQL. Every page and total is the listed read's, on every database.
 */
class SparseOrderedReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('sparse_read_items');
        Schema::create('sparse_read_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedInteger('tenant')->default(0)->index();
            $table->decimal('price', 10, 2)->index();
            $table->softDeletes();
        });

        // 600 rows: "kappa" in every 20th (5% of them), "omega" in every 12th (8%), "gamma" in every
        // 3rd (a third: a dense word). Prices repeat, so the key breaks the ties.
        foreach (array_chunk(range(0, 599), 200) as $chunk) {
            DB::table('sparse_read_items')->insert(array_map(fn ($i) => [
                'title'  => trim(($i % 20 === 0 ? 'kappa ' : '') . ($i % 12 === 0 ? 'omega ' : '') . ($i % 3 === 0 ? 'gamma ' : '') . sprintf('item %03d', $i)),
                'tenant' => $i % 5,
                'price'  => ($i * 37) % 50,
            ], $chunk));
        }
        SparseReadItem::query()->chunkById(200, fn ($items) => app(IndexManager::class)->indexBatch($items));
        DB::table('sparse_read_items')->whereRaw('id % 7 = 0')->update(['deleted_at' => '2020-01-01 00:00:00']);

        // Every word's ranking is longer than one chunk, and holds every match.
        config(['fuzzy-search.bm25.candidate_chunk' => 10]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('sparse_read_items');
        Schema::dropIfExists('sparse_read_uuids');
        Schema::dropIfExists('sparse_read_codes');
        Schema::dropIfExists('sparse_plan_items');

        parent::tearDown();
    }

    /** @return array<int, string> the SQL of the reads $call makes of $table through the postings (with $bindings, [sql, bindings]) */
    private function postingsReads(\Closure $call, string $table = 'sparse_read_items', bool $bindings = false): array
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
        return SparseReadItem::search($word)->typoTolerance(0)->useInvertedIndex()->orderBy('price');
    }

    public function test_sqlserver_compares_model_id_in_the_keys_own_type(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('Only SQL Server restricts a whole ranking past one chunk through the postings for an integer key; the CI SQL Server job runs this.');
        }

        $key   = '[sparse_read_items].[id]';
        $calls = [
            'take(20)'      => fn () => $this->search('kappa')->take(20)->get(),
            'paginate p3'   => fn () => $this->search('omega')->paginate(7, 'page', 3),
            'where tenant'  => fn () => SparseReadItem::searchOn(SparseReadItem::query()->where('tenant', 0), 'omega')->typoTolerance(0)->useInvertedIndex()->orderBy('price')->paginate(7, 'page', 2),
            'a dense word'  => fn () => $this->search('gamma')->paginate(7, 'page', 2),
            'in rank order' => function () {
                config(['fuzzy-search.max_candidates' => 10]); // a constrained ranking past it is checked through the postings

                return SparseReadItem::searchOn(SparseReadItem::query()->where('tenant', 0), 'omega')->typoTolerance(0)->useInvertedIndex()->paginate(7);
            },
        ];

        foreach ($calls as $label => $call) {
            $reads = $this->postingsReads($call);

            $this->assertNotEmpty($reads, "{$label}: read through the postings");
            foreach ($reads as $sql) {
                $this->assertStringContainsString("TRY_CAST([fzr].[model_id] AS BIGINT) = {$key}", $sql, "{$label}: model_id in the key's type, so a matched posting is looked up by key\n{$sql}");
                $this->assertStringContainsString("[fzr].[model_id] = CAST({$key} AS NVARCHAR(191))", $sql, "{$label}: the key's cast, so a row's postings are probed by it\n{$sql}");
            }
        }

        // A sparse word: a native uuid in its type beside the cast, an nvarchar key in model_id's
        // collation as it is. A denser word keeps a string key's cast alone.
        $forms = [
            SparseReadUuid::class => ['TRY_CAST([fzr].[model_id] AS UNIQUEIDENTIFIER) = [sparse_read_uuids].[id]', 'CAST([sparse_read_uuids].[id] AS NVARCHAR(191))'],
            SparseReadCode::class => ['[fzr].[model_id] = [sparse_read_codes].[code])', null],
        ];
        foreach ($forms as $model => [$form, $cast]) {
            $this->seedKeyed($model);
            $table = (new $model())->getTable();
            $reads = $this->postingsReads(fn () => $model::search('kappa')->typoTolerance(0)->useInvertedIndex()->orderBy('price')->take(20)->get(), $table);

            $this->assertNotEmpty($reads, "{$model}: read through the postings");
            foreach ($reads as $sql) {
                $this->assertStringContainsString($form, $sql, $sql);
                $cast === null ? $this->assertStringNotContainsString('CAST(', $sql, $sql) : $this->assertStringContainsString($cast, $sql, $sql);
            }

            $reads = $this->postingsReads(fn () => $model::search('gamma')->typoTolerance(0)->useInvertedIndex()->orderBy('price')->paginate(7, 'page', 2), $table);
            $this->assertNotEmpty($reads, "{$model}, a dense word: read through the postings");
            foreach ($reads as $sql) {
                $this->assertStringNotContainsString($form, $sql, $sql);
                $this->assertMatchesRegularExpression('/\\[fzr\\]\\.\\[model_id\\] = CAST\\(\\[' . $table . '\\]\\.\\[\\w+\\] AS NVARCHAR\\(191\\)\\) COLLATE DATABASE_DEFAULT\\)/', $sql, $sql);
            }
        }
    }

    /**
     * The plan SQL Server runs for an ordered page and its COUNT of a word in 0.1% of 200,000
     * rows: the matched postings looked up by key, never the table read whole (a clustered index
     * scan, or a scan of a narrower index, hashed against the postings).
     */
    public function test_sqlserver_looks_a_rare_words_matches_up_by_key_at_200k_rows(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('The plan is SQL Server\'s; the CI SQL Server job runs this.');
        }

        Schema::dropIfExists('sparse_plan_items');
        Schema::create('sparse_plan_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->decimal('price', 10, 2)->index();
        });

        // 2,000 rows indexed as an app indexes them: "kappa item" in every 1,000th, "plain item" in
        // the rest. Then 198,000 more of the two, their postings copied from an indexed row of each
        // (1000, 1).
        foreach (array_chunk(range(1, 2000), 500) as $chunk) {
            DB::table('sparse_plan_items')->insert(array_map(fn ($i) => ['title' => $i % 1000 === 0 ? 'kappa item' : 'plain item', 'price' => ($i * 37) % 997], $chunk));
        }
        SparsePlanItem::query()->chunkById(500, fn ($items) => app(IndexManager::class)->indexBatch($items));

        $numbers = 'with n as (select top (198000) row_number() over (order by (select null)) + 2000 as g from sys.all_objects a cross join sys.all_objects b)';
        DB::statement("{$numbers} insert into sparse_plan_items (title, price) select case when g % 1000 = 0 then 'kappa item' else 'plain item' end, (g * 37) % 997 from n order by g");
        DB::statement("{$numbers} insert into fuzzy_index_postings (term_id, model_type_id, model_id, column_id, frequency, doc_length)
            select p.term_id, p.model_type_id, cast(n.g as nvarchar(191)), p.column_id, p.frequency, p.doc_length from n
            join fuzzy_index_postings p on p.model_type_id = ? and p.model_id = case when n.g % 1000 = 0 then '1000' else '1' end", [IndexManager::modelTypeId(SparsePlanItem::class)]);
        DB::statement('update statistics sparse_plan_items');
        DB::statement('update statistics fuzzy_index_postings');

        $page  = fn () => SparsePlanItem::search('kappa')->typoTolerance(0)->useInvertedIndex()->orderBy('price')->paginate(20);
        $reads = $this->postingsReads($page, 'sparse_plan_items', true);

        $this->assertCount(2, $reads, 'the COUNT and the page');
        foreach ($reads as [$sql, $bindings]) {
            $plan  = new \DOMXPath($this->planOf($sql, $bindings));
            $plan->registerNamespace('p', 'http://schemas.microsoft.com/sqlserver/2004/07/showplan');
            $count = fn (string $op) => $plan->query("//p:RelOp[@PhysicalOp='{$op}'][(p:IndexScan|p:TableScan)/p:Object[@Table='[sparse_plan_items]']]")->length;

            $this->assertGreaterThan(0, $count('Clustered Index Seek'), "the matches looked up by key\n{$sql}");
            $this->assertSame(0, $count('Clustered Index Scan') + $count('Index Scan') + $count('Table Scan'), "the table read whole\n{$sql}");
        }

        $this->assertSame(
            DB::table('sparse_plan_items')->where('title', 'kappa item')->orderBy('price')->orderBy('id')->limit(20)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            $page()->pluck('id')->map(fn ($id) => (int) $id)->all()
        );
    }

    /** SQL Server: the plan $sql runs with, $bindings bound as Laravel binds them (SET STATISTICS XML; pdo_sqlsrv returns no SHOWPLAN_XML). */
    private function planOf(string $sql, array $bindings): \DOMDocument
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET STATISTICS XML ON');
        $xml = '';

        try {
            $statement = $pdo->prepare($sql);
            foreach (array_values($bindings) as $i => $value) {
                $statement->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $statement->execute();

            // The rows, then the plan as a result set of its own.
            do {
                $rows = $statement->columnCount() > 0 ? $statement->fetchAll(\PDO::FETCH_NUM) : [];
                if (str_starts_with((string) ($rows[0][0] ?? ''), '<ShowPlanXML')) {
                    $xml = (string) $rows[0][0];
                }
            } while ($statement->nextRowset());
        } finally {
            $pdo->exec('SET STATISTICS XML OFF');
        }

        $document = new \DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    public function test_every_page_and_total_is_the_listed_reads(): void
    {
        $listed = fn (string $word, \Closure $scope) => $scope(SparseReadItem::query())->where('title', 'like', "%{$word}%")
            ->orderBy('price')->orderBy('id')->pluck('title')->all();
        $shapes = [
            'live rows'    => fn ($query) => $query,
            'where tenant' => fn ($query) => $query->where('tenant', 0), // every kappa row is tenant 0
        ];

        foreach (['kappa', 'omega', 'gamma'] as $word) {
            foreach ($shapes as $shape => $scope) {
                $at     = "{$word}, {$shape}";
                $titles = $listed($word, $scope);
                $make   = fn () => SparseReadItem::searchOn($scope(SparseReadItem::query()), $word)->typoTolerance(0)->useInvertedIndex()->orderBy('price');

                $this->assertGreaterThan(10, count($listed($word, fn ($query) => $query)), "{$word}: past one chunk");
                $this->assertSame(array_slice($titles, 0, 20), $make()->take(20)->get()->pluck('title')->all(), "{$at}: take(20)");
                $this->assertSame($titles[0], $make()->first()?->title, "{$at}: first");
                $this->assertSame(count($titles), $make()->count(), "{$at}: count");
                $this->assertSame(array_slice($titles, 7, 7), $make()->simplePaginate(7, 'page', 2)->pluck('title')->all(), "{$at}: simplePaginate");

                $served = [];
                for ($page = 1; $page <= (int) ceil(count($titles) / 7); $page++) {
                    $paginator = $make()->paginate(7, 'page', $page);
                    $this->assertSame(count($titles), $paginator->total(), "{$at}: total on page {$page}");
                    array_push($served, ...$paginator->pluck('title')->all());
                }
                $this->assertSame($titles, $served, "{$at}: the pages serve every match once, in order");
            }
        }
    }

    public function test_scout_serves_the_listed_reads_pages(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search']);
        SparseReadScoutItem::withTrashed()->chunkById(200, fn ($items) => app(IndexManager::class)->indexBatch($items));

        foreach (['kappa', 'omega'] as $word) {
            // Scout breaks a tie in the order by key, descending.
            $titles = SparseReadScoutItem::query()->where('title', 'like', "%{$word}%")->orderBy('price')->orderByDesc('id')->pluck('title')->all();

            $served = [];
            for ($page = 1; $page <= (int) ceil(count($titles) / 7); $page++) {
                $paginator = (new \Laravel\Scout\Builder(new SparseReadScoutItem, $word))->orderBy('price')->paginate(7, 'page', $page);
                $this->assertSame(count($titles), $paginator->total(), "{$word}: total on page {$page}");
                array_push($served, ...collect($paginator->items())->pluck('title')->all());
            }
            $this->assertSame($titles, $served, "{$word}: the pages serve every match once, in order");
        }
    }

    /** The words of setUp() in 600 rows of $model, a string key: a native uuid (PostgreSQL's uuid, SQL Server's uniqueidentifier) or a code. */
    private function seedKeyed(string $model): void
    {
        $table = (new $model())->getTable();
        $uuid  = $model === SparseReadUuid::class;

        Schema::dropIfExists($table);
        Schema::create($table, function (Blueprint $table) use ($uuid) {
            $uuid ? $table->uuid('id')->primary() : $table->string('code', 40)->primary();
            $table->string('title');
            $table->decimal('price', 10, 2)->index();
        });

        foreach (array_chunk(range(0, 599), 200) as $chunk) {
            DB::table($table)->insert(array_map(fn ($i) => [
                ($uuid ? 'id' : 'code') => $uuid ? (string) \Illuminate\Support\Str::uuid() : sprintf('c%04d', $i),
                'title'                 => trim(($i % 20 === 0 ? 'kappa ' : '') . ($i % 12 === 0 ? 'omega ' : '') . ($i % 3 === 0 ? 'gamma ' : '') . sprintf('item %03d', $i)),
                'price'                 => ($i * 37) % 50,
            ], $chunk));
        }
        $model::query()->chunkById(200, fn ($items) => app(IndexManager::class)->indexBatch($items));
    }

    public function test_postgres_binds_a_sparse_words_string_ranking(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('The bound ranking of a string key past one chunk is PostgreSQL\'s here (MySQL, MariaDB and SQLite bind it for every word); the CI PostgreSQL job runs this.');
        }

        foreach ([SparseReadUuid::class, SparseReadCode::class] as $model) {
            $this->seedKeyed($model);
            $table = (new $model())->getTable();
            $make  = fn (string $word) => $model::search($word)->typoTolerance(0)->useInvertedIndex()->orderBy('price');

            $this->assertSame([], $this->postingsReads(fn () => $make('kappa')->take(20)->get(), $table), "{$table}: a sparse word: its ranking, bound");
            $this->assertSame([], $this->postingsReads(fn () => $make('omega')->paginate(7, 'page', 3), $table), "{$table}: a sparse word: its ranking, bound");
            $this->assertNotEmpty($this->postingsReads(fn () => $make('gamma')->paginate(7, 'page', 2), $table), "{$table}: a dense word: through the postings");
        }
    }

    public function test_a_string_keys_pages_and_total_are_the_listed_reads(): void
    {
        foreach ([SparseReadUuid::class, SparseReadCode::class] as $model) {
            $this->seedKeyed($model);
            $key = (new $model())->getKeyName();

            foreach (['kappa', 'omega', 'gamma'] as $word) {
                $at     = "{$model}, {$word}";
                $titles = $model::query()->where('title', 'like', "%{$word}%")->orderBy('price')->orderBy($key)->pluck('title')->all();
                $make   = fn () => $model::search($word)->typoTolerance(0)->useInvertedIndex()->orderBy('price');

                $this->assertGreaterThan(10, count($titles), "{$at}: past one chunk");
                $this->assertSame(array_slice($titles, 0, 20), $make()->take(20)->get()->pluck('title')->all(), "{$at}: take(20)");
                $this->assertSame(count($titles), $make()->count(), "{$at}: count");

                $served = [];
                for ($page = 1; $page <= (int) ceil(count($titles) / 7); $page++) {
                    $paginator = $make()->paginate(7, 'page', $page);
                    $this->assertSame(count($titles), $paginator->total(), "{$at}: total on page {$page}");
                    array_push($served, ...$paginator->pluck('title')->all());
                }
                $this->assertSame($titles, $served, "{$at}: the pages serve every match once, in order");
            }
        }
    }
}
