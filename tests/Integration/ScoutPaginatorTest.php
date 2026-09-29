<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Laravel\Scout\Searchable;

/** The docs/integrations.md recipe on a string (UUID) key, with a tenant column to constrain on. */
class ScoutTenantDoc extends Model
{
    use HasUuids, Searchable, FuzzySearchable {
        FuzzySearchable::search insteadof Searchable;
        Searchable::search as scoutSearch;
        FuzzySearchable::bootSearchable insteadof Searchable;
        Searchable::bootSearchable as bootScoutSearchable;
    }

    protected $table   = 'scout_tenant_docs';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10]];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

/**
 * M1 (round 9): Scout's paginate() re-counts a query() callback's matches itself: it re-reads
 * the key of every match, then binds them all in one whereIn() (Builder::getTotalCount()). The
 * engine already applies query(), so the count was waste, and on a string key it passed SQL
 * Server's 2,100 bindings (a 500 on every page for a tenant with that many matches). The engine
 * paginates itself (Scout's PaginatesEloquentModelsUsingDatabase, Scout 10.1+), with the total it
 * has already counted, and builds the paginator exactly as Scout's Builder does.
 */
class ScoutPaginatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!interface_exists(PaginatesEloquentModelsUsingDatabase::class)) {
            // Scout 10.0 (the lowest-deps job) has no contract that passes the page name, so Scout
            // paginates there and re-counts as before; the docs recipe uses where() instead.
            $this->markTestSkipped('Scout 10.0 has no PaginatesEloquentModelsUsingDatabase (added in 10.1).');
        }

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);

        Schema::dropIfExists('scout_tenant_docs');
        Schema::create('scout_tenant_docs', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('tenant_id');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scout_tenant_docs');
        parent::tearDown();
    }

    /** $tenant1 matches of "zed" for tenant 1, then 3 for tenant 2, every one indexed through Scout. */
    private function seedDocs(int $tenant1): void
    {
        $rows = [];
        for ($i = 0; $i < $tenant1; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'name' => sprintf('zed item %05d', $i), 'tenant_id' => 1];
        }
        for ($i = 0; $i < 3; $i++) {
            $rows[] = ['id' => (string) Str::uuid(), 'name' => "zed other {$i}", 'tenant_id' => 2];
        }

        // 3 bindings a row: 500 rows stay under SQL Server's 2,100.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('scout_tenant_docs')->insert($chunk);
        }

        ScoutTenantDoc::query()->chunkById(500, fn ($docs) => $docs->searchable());
    }

    private function where(): \Laravel\Scout\Builder
    {
        return ScoutTenantDoc::scoutSearch('zed')->where('tenant_id', 1);
    }

    private function queryCallback(): \Laravel\Scout\Builder
    {
        return ScoutTenantDoc::scoutSearch('zed')->query(fn ($query) => $query->where('tenant_id', 1));
    }

    /** @return array{0: mixed, 1: int} the result, and the most bindings any one of its queries sent */
    private function withMaxBindings(\Closure $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $result = $run();
            $max    = (int) collect(DB::getQueryLog())->max(fn ($query) => count($query['bindings']));
        } finally {
            DB::disableQueryLog();
        }

        return [$result, $max];
    }

    private function ids(PaginatorContract $paginator): array
    {
        return collect($paginator->items())->map(fn ($doc) => $doc->getKey())->all();
    }

    private function onRequest(string $uri): void
    {
        $this->app->instance('request', Request::create($uri));
    }

    public function test_a_query_callback_page_binds_one_page_of_keys_not_every_match(): void
    {
        // Past SQL Server's 2,100 bindings, and past max(candidate_chunk, max_candidates), so the
        // engine checks the ranking with its subquery, which binds no key.
        $this->seedDocs(2200);

        $pages = [
            'paginate'          => fn ($search) => $search->paginate(10, 'page', 2),
            'ordered paginate'  => fn ($search) => $search->orderBy('name')->paginate(10, 'page', 2),
            'last page'         => fn ($search) => $search->orderBy('name')->paginate(10, 'page', 220),
            'simplePaginate'    => fn ($search) => $search->simplePaginate(10, 'page', 219),
            'last simple page'  => fn ($search) => $search->orderBy('name')->simplePaginate(10, 'page', 220),
        ];

        foreach ($pages as $label => $page) {
            $expected = $page($this->where());

            [$paginator, $maxBindings] = $this->withMaxBindings(fn () => $page($this->queryCallback()));

            // A page's 10 keys, plus the few the ranking and the callback bind; every match's key
            // would be 2,200 (SQL Server throws at 2,101).
            $this->assertLessThanOrEqual(20, $maxBindings, "{$label}: a query bound every matching key");
            $this->assertSame($this->ids($expected), $this->ids($paginator), "{$label}: items");
            $this->assertCount(10, $paginator->items(), "{$label}: page size");
            $this->assertSame([1], collect($paginator->items())->pluck('tenant_id')->map(fn ($id) => (int) $id)->unique()->values()->all(), "{$label}: tenant");

            if ($paginator instanceof LengthAwarePaginator) {
                $this->assertSame(2200, $paginator->total(), "{$label}: total");
                $this->assertSame(220, $paginator->lastPage(), "{$label}: lastPage");
                $this->assertSame($expected->total(), $paginator->total(), "{$label}: total against where()");
            } else {
                $this->assertSame($paginator->currentPage() < 220, $paginator->hasMorePages(), "{$label}: hasMorePages");
                $this->assertSame($expected->hasMorePages(), $paginator->hasMorePages(), "{$label}: hasMorePages against where()");
            }
        }

        $this->assertSame(
            ['zed item 00019', 'zed item 00018'],
            collect($this->queryCallback()->orderBy('name', 'desc')->paginate(2, 'page', 1091)->items())->pluck('name')->all(),
            'a deep ordered page'
        );
    }

    /**
     * The values Scout's own paginator gave at d80e264, on the where() path, which Scout
     * paginated itself: the page name, the page read from the request, the path, the query string.
     */
    public function test_the_paginators_behave_as_scouts_own(): void
    {
        $this->seedDocs(25);

        foreach (['where()' => fn () => $this->where(), 'query()' => fn () => $this->queryCallback()] as $label => $search) {
            $this->onRequest('http://localhost/docs?p=2&sort=name');

            $page = $search()->orderBy('name')->paginate(10, 'p');
            $this->assertInstanceOf(LengthAwarePaginator::class, $page, $label);
            $this->assertSame(array_map(fn ($i) => sprintf('zed item %05d', $i), range(10, 19)), collect($page->items())->pluck('name')->all(), "{$label}: ?p=2 is page 2");
            $this->assertSame([2, 10, 25, 3, 11, 20], [$page->currentPage(), $page->perPage(), $page->total(), $page->lastPage(), $page->firstItem(), $page->lastItem()], $label);
            $this->assertSame('p', $page->getPageName(), $label);
            $this->assertSame('http://localhost/docs', $page->path(), $label);
            $this->assertSame('http://localhost/docs?query=zed&p=3', $page->url(3), $label);
            $this->assertSame('http://localhost/docs?query=zed&p=3', $page->nextPageUrl(), $label);
            $this->assertSame('http://localhost/docs?query=zed&p=1', $page->previousPageUrl(), $label);
            $this->assertSame('http://localhost/docs?query=zed&tab=b&p=3', $page->appends('tab', 'b')->url(3), $label);
            $this->assertSame('http://localhost/docs?query=zed&tab=b&sort=name&p=3', $page->withQueryString()->url(3), $label);

            $simple = $search()->orderBy('name')->simplePaginate(10, 'p');
            $this->assertInstanceOf(Paginator::class, $simple, $label);
            $this->assertSame($this->ids($page), $this->ids($simple), "{$label}: simplePaginate ?p=2");
            $this->assertSame([2, 10, true, 'p'], [$simple->currentPage(), $simple->perPage(), $simple->hasMorePages(), $simple->getPageName()], $label);
            $this->assertSame('http://localhost/docs?query=zed&p=3', $simple->nextPageUrl(), $label);
            $this->assertSame('http://localhost/docs?query=zed&sort=name&p=3', $simple->withQueryString()->nextPageUrl(), $label);
            $this->assertFalse($search()->orderBy('name')->simplePaginate(10, 'p', 3)->hasMorePages(), "{$label}: simplePaginate last page");

            // No page size and no page: the model's getPerPage() and ?page from the request.
            $this->onRequest('http://localhost/docs?page=2');
            $default = $search()->orderBy('name')->paginate();
            $this->assertSame([2, 15, 25, 2, 'page'], [$default->currentPage(), $default->perPage(), $default->total(), $default->lastPage(), $default->getPageName()], $label);
            $this->assertSame(['zed item 00015', 'zed item 00024'], [$default->items()[0]->name, $default->items()[9]->name], $label);
        }
    }

    /**
     * Scout's Builder runs a withRawResults() callback on the raw page before map() only on the
     * pages it builds itself; the pages the engine builds must run it too, once, on the raw
     * ['results', 'total'] page, with the total taken from the page as it was before the callback.
     */
    public function test_the_with_raw_results_callback_sees_each_raw_page_once(): void
    {
        if (!method_exists(\Laravel\Scout\Builder::class, 'withRawResults')) {
            $this->markTestSkipped('Scout < 10.13 has no withRawResults().');
        }

        $this->seedDocs(25);
        $this->onRequest('http://localhost/docs?p=1');

        // Scout 10.13 to 10.14.0 ran the callback and ignored what it returned; 10.14.1 serves its return.
        $served = (new \Laravel\Scout\Builder(new ScoutTenantDoc, 'zed'))->withRawResults(fn () => ['served'])->applyAfterRawSearchCallback(['raw']) === ['served']
            ? range(1, 9)
            : range(0, 9);

        foreach (['paginate' => fn ($search) => $search->paginate(10, 'p'), 'simplePaginate' => fn ($search) => $search->simplePaginate(10, 'p')] as $label => $page) {
            $raw       = [];
            $paginator = $page($this->queryCallback()->orderBy('name')->withRawResults(function (array $results) use (&$raw) {
                $raw[] = $results;

                // Drop the first match, and claim a total the paginator must not take.
                return ['results' => $results['results']->slice(1)->values(), 'total' => 99];
            }));

            $this->assertCount(1, $raw, "{$label}: the callback ran once");
            $this->assertSame(['results', 'total'], array_keys($raw[0]), $label);
            $this->assertSame(25, $raw[0]['total'], $label);
            $this->assertCount(10, $raw[0]['results'], $label);
            $this->assertSame(array_map(fn ($i) => sprintf('zed item %05d', $i), $served), collect($paginator->items())->pluck('name')->all(), "{$label}: the callback's page");

            if ($paginator instanceof LengthAwarePaginator) {
                $this->assertSame([25, 3], [$paginator->total(), $paginator->lastPage()], "{$label}: the total before the callback");
            } else {
                $this->assertTrue($paginator->hasMorePages(), "{$label}: hasMorePages from the total before the callback");
            }
        }
    }
}
