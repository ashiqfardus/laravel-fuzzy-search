<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/** The users table under Scout's builder, with the package's index behind it. */
class ConstrainedScoutUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * M2 (round 8). Any constraint on an index search — a tenant where(), a SoftDeletes model — counted
 * the ranking 500 ids per COUNT and walked it bm25.candidate_chunk ids per query: 354 queries for one
 * page of a small tenant among 50k matches. The index's own connection now reads the matches the
 * constraint accepts in one query, through the postings subquery, whatever the ranking's size. The
 * pages serve what total() counts, and a constrained search serves the unconstrained search's rows
 * that the constraint accepts, in the same order.
 */
class ConstrainedIndexCostTest extends TestCase
{
    private const TENANT = '%@tenant-a.test';

    /** Zebra rows $from to $to - 1 (every third one tenant-a's among the first 15), indexed as each model class. */
    private function seedZebras(int $from, int $to): void
    {
        $rows = array_map(fn ($i) => [
            'name'  => sprintf('Zebra %04d', $i),
            'email' => "z{$i}@tenant-" . ($i < 15 && $i % 3 === 0 ? 'a' : 'b') . '.test',
        ], range($from, $to - 1));

        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        foreach ([User::class, SoftDeletedUser::class, ConstrainedScoutUser::class] as $class) {
            app(IndexManager::class)->indexBatch($class::withoutGlobalScopes()->where('name', '>=', sprintf('Zebra %04d', $from))->where('name', 'like', 'Zebra%')->get());
        }
    }

    /** @return int the number of statements $call runs */
    private function queries(\Closure $call): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string, int> the statements each constrained index terminal runs */
    private function costs(): array
    {
        $tenant  = fn () => User::search('zebra')->typoTolerance(0)->useInvertedIndex()->where('email', 'like', self::TENANT);
        $trashed = fn () => SoftDeletedUser::search('zebra')->typoTolerance(0)->useInvertedIndex();
        $scout   = fn () => (new \Laravel\Scout\Builder(new ConstrainedScoutUser, 'zebra'))->query(fn ($q) => $q->where('email', 'like', self::TENANT));

        return [
            'tenant paginate'  => $this->queries(fn () => $this->assertSame(5, $tenant()->paginate(15)->total())),
            'tenant count'     => $this->queries(fn () => $this->assertSame(5, $tenant()->count())),
            'tenant get'       => $this->queries(fn () => $this->assertCount(5, $tenant()->get())),
            'trashed paginate' => $this->queries(fn () => $this->assertCount(15, $trashed()->paginate(15)->items())),
            'trashed count'    => $this->queries(fn () => $trashed()->count()),
            'trashed get'      => $this->queries(fn () => $this->assertCount(15, $trashed()->get())),
            'scout paginate'   => $this->queries(fn () => $this->assertSame(5, $scout()->paginate(15)->total())),
        ];
    }

    public function test_a_constrained_index_search_costs_the_same_queries_however_many_rows_match(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'fuzzy-search.bm25.candidate_chunk' => 20]);
        $this->seedZebras(0, 150);
        DB::table('users')->whereIn('name', ['Zebra 0001', 'Zebra 0002', 'Zebra 0004'])->update(['deleted_at' => now()]); // trashed, still indexed
        $small = $this->costs();

        $this->seedZebras(150, 1100);
        $this->assertSame(1097, SoftDeletedUser::search('zebra')->typoTolerance(0)->useInvertedIndex()->count());

        $this->assertSame($small, $this->costs());
    }

    /** @return string[] the names every page of $make serves, and each page's total */
    private function served(\Closure $make, int $perPage, ?array &$totals = null): array
    {
        $names  = [];
        $totals = [];

        for ($page = 1; $page === 1 || count($names) === ($page - 1) * $perPage; $page++) {
            $paginator = $make()->paginate($perPage, 'page', $page);
            $totals[]  = $paginator->total();
            $names     = [...$names, ...collect($paginator->items())->pluck('name')->all()];
        }

        return $names;
    }

    public function test_the_pages_serve_the_total_and_a_constraint_filters_the_unconstrained_rows(): void
    {
        $this->seedZebras(0, 300);
        DB::table('users')->where('name', 'like', 'Zebra%')->where('id', '>', DB::table('users')->where('name', 'Zebra 0150')->value('id'))
            ->update(['email' => DB::raw("REPLACE(email, 'tenant-b', 'tenant-a')")]); // tenant-a: 5 of the first 15, and every row past 150
        $tenantNames = array_flip(User::query()->where('email', 'like', self::TENANT)->pluck('name')->all());
        $isTenant    = fn (string $name) => isset($tenantNames[$name]);

        foreach (['under the posting cap' => 50000, 'past the posting cap' => 40] as $label => $cap) {
            config(['fuzzy-search.bm25.max_postings_per_term' => $cap, 'fuzzy-search.bm25.candidate_chunk' => 20]);

            foreach (['rank order' => fn ($b) => $b, 'orderBy' => fn ($b) => $b->orderBy('name')] as $order => $ordered) {
                $all    = $this->served(fn () => $ordered(User::search('zebra')->typoTolerance(0)->useInvertedIndex()), 15, $totals);
                $this->assertSame([count($all)], array_values(array_unique($totals)), "{$label}, {$order}: total() is what the pages serve");
                $this->assertSame(count($all), $ordered(User::search('zebra')->typoTolerance(0)->useInvertedIndex())->count(), "{$label}, {$order}: count()");

                $tenant = $this->served(fn () => $ordered(User::search('zebra')->typoTolerance(0)->useInvertedIndex()->where('email', 'like', self::TENANT)), 15, $totals);
                $this->assertSame(array_values(array_filter($all, $isTenant)), $tenant, "{$label}, {$order}: the constrained pages");
                $this->assertSame([count($tenant)], array_values(array_unique($totals)), "{$label}, {$order}: the constrained total() is what its pages serve");
                $this->assertSame(count($tenant), $ordered(User::search('zebra')->typoTolerance(0)->useInvertedIndex()->where('email', 'like', self::TENANT))->count(), "{$label}, {$order}: constrained count()");
            }
        }
    }
}
