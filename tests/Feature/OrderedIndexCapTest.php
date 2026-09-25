<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/** The users table under Scout's builder, with the package's index behind it. */
class CapScoutUser extends \Illuminate\Database\Eloquent\Model
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
 * H1 (round 8). rank() stops at bm25.max_postings_per_term, in an arbitrary order among equal
 * scores, but the postings subquery lets every match through the ordered query. The ordered walk
 * then kept only the ranked rows, so page 1 was not the first rows in the order, total() counted
 * matches no page served, and the walk re-ran the ordered query for every 1,000 rows it passed.
 * Every match is now served in the order, one page per query, and total() counts what the pages
 * serve.
 */
class OrderedIndexCapTest extends TestCase
{
    /** $n "Zebra NNNN" rows, inserted shuffled so the key order is not the name order, indexed as $modelClass. */
    private function seedZebras(int $n, string $modelClass = User::class): void
    {
        $numbers = range(0, $n - 1);
        mt_srand(8);
        shuffle($numbers);

        foreach (array_chunk($numbers, 250) as $chunk) {
            DB::table('users')->insert(array_map(fn ($i) => ['name' => sprintf('Zebra %04d', $i), 'email' => "n{$i}@example.test"], $chunk));
        }

        app(IndexManager::class)->indexBatch($modelClass::query()->where('name', 'like', 'Zebra%')->get());
    }

    /** @return string[] the names $n zebras sorted by name, from $from */
    private function zebras(int $from, int $count): array
    {
        return array_map(fn ($i) => sprintf('Zebra %04d', $i), range($from, $from + $count - 1));
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

    /** @return array<string, int> candidate_chunk: the ranking past one chunk (postings subquery), and within one */
    private function chunks(): array
    {
        return ['past one chunk' => 10, 'within one chunk' => 200];
    }

    public function test_the_ordered_index_path_serves_every_match_past_the_posting_cap(): void
    {
        $this->seedZebras(300);

        foreach ($this->chunks() as $label => $chunk) {
            config(['fuzzy-search.bm25.max_postings_per_term' => 40, 'fuzzy-search.bm25.candidate_chunk' => $chunk]);
            $make = fn () => User::search('zebra')->typoTolerance(0)->useInvertedIndex()->orderBy('name');

            $this->assertCount(40, app(Bm25Scorer::class)->rank(['zebra'], User::class), 'the ranking stops at the cap');
            $this->assertSame($this->zebras(0, 15), $make()->take(15)->get()->pluck('name')->all(), "{$label}: get");
            $this->assertSame('Zebra 0000', $make()->first()?->name, "{$label}: first");
            $this->assertSame(300, $make()->count(), "{$label}: count");

            $served = [];
            for ($page = 1; $page <= 20; $page++) {
                $paginator = $make()->paginate(15, 'page', $page);
                $this->assertSame(300, $paginator->total(), "{$label}: total on page {$page}");
                $this->assertCount(15, $paginator->items(), "{$label}: page {$page} is full");
                $served = [...$served, ...$paginator->items()];
            }

            $this->assertSame($this->zebras(0, 300), array_column(array_map(fn ($m) => $m->toArray(), $served), 'name'), "{$label}: the pages serve every match once, in order");
            $this->assertSame([], $make()->paginate(15, 'page', 21)->items(), "{$label}: the page past the last is empty");
            $this->assertSame($this->zebras(285, 15), $make()->simplePaginate(15, 'page', 20)->pluck('name')->all(), "{$label}: simplePaginate's last page");
            $this->assertFalse($make()->simplePaginate(15, 'page', 20)->hasMorePages(), "{$label}: nothing after the last page");

            // Ruling ER-96: a match rank() stopped before has no BM25 score, so its raw score is 0.
            $this->assertCount(40, array_filter($served, fn ($model) => $model->_raw_score > 0), "{$label}: only the ranked matches carry a score");
        }
    }

    public function test_scout_order_by_serves_every_match_past_the_posting_cap(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedZebras(300, CapScoutUser::class);
        config(['scout.driver' => 'fuzzy-search']);

        foreach ($this->chunks() as $label => $chunk) {
            config(['fuzzy-search.bm25.max_postings_per_term' => 40, 'fuzzy-search.bm25.candidate_chunk' => $chunk]);
            $make = fn () => (new \Laravel\Scout\Builder(new CapScoutUser, 'zebra'))->orderBy('name');

            $this->assertSame($this->zebras(0, 15), $make()->take(15)->get()->pluck('name')->all(), "{$label}: get");

            $served = [];
            for ($page = 1; $page <= 20; $page++) {
                $paginator = $make()->paginate(15, 'page', $page);
                $this->assertSame(300, $paginator->total(), "{$label}: total on page {$page}");
                $this->assertCount(15, $paginator->items(), "{$label}: page {$page} is full");
                $served = [...$served, ...collect($paginator->items())->pluck('name')->all()];
            }

            $this->assertSame($this->zebras(0, 300), $served, "{$label}: the pages serve every match once, in order");
            $this->assertSame([], $make()->paginate(15, 'page', 21)->items(), "{$label}: the page past the last is empty");
        }
    }

    /** Past 1,000 matches the walk took one more ordered query per 1,000 rows it passed. */
    public function test_a_deep_ordered_page_takes_as_many_queries_as_page_one(): void
    {
        $this->seedZebras(1100);
        config(['fuzzy-search.bm25.candidate_chunk' => 10]);
        $make = fn () => User::search('zebra')->typoTolerance(0)->useInvertedIndex()->orderBy('name');

        $first = $this->queries(fn () => $this->assertSame($this->zebras(0, 15), $make()->paginate(15, 'page', 1)->pluck('name')->all()));
        $deep  = $this->queries(fn () => $this->assertSame($this->zebras(1080, 15), $make()->paginate(15, 'page', 73)->pluck('name')->all()));
        $this->assertSame($first, $deep, 'paginate(): page 73 against page 1');

        $first = $this->queries(fn () => $make()->take(15)->get());
        $deep  = $this->queries(fn () => $this->assertSame($this->zebras(1080, 15), $make()->skip(1080)->take(15)->get()->pluck('name')->all()));
        $this->assertSame($first, $deep, 'get(): skip(1080) against skip(0)');
    }

    public function test_a_deep_scout_ordered_page_takes_as_many_queries_as_page_one(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedZebras(1100, CapScoutUser::class);
        config(['scout.driver' => 'fuzzy-search', 'fuzzy-search.bm25.candidate_chunk' => 10]);
        $make = fn () => (new \Laravel\Scout\Builder(new CapScoutUser, 'zebra'))->orderBy('name');

        $first = $this->queries(fn () => $this->assertSame($this->zebras(0, 15), collect($make()->paginate(15, 'page', 1)->items())->pluck('name')->all()));
        $deep  = $this->queries(fn () => $this->assertSame($this->zebras(1080, 15), collect($make()->paginate(15, 'page', 73)->items())->pluck('name')->all()));

        $this->assertSame($first, $deep, 'page 73 against page 1');
    }
}
