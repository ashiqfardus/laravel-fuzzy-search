<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** getSearchScore() puts "Zeta 0062", 21st by BM25, far ahead of every other row. */
class DeepPageBoostedUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10]];

    public function getSearchScore($baseScore): float
    {
        return str_ends_with($this->name, 'Zeta 0062') ? $baseScore * 1000 : $baseScore;
    }
}

/**
 * H1 (round 9). An index page in rank order loaded every model from rank 1 to the end of the page,
 * 200 ids per query, and cut the page from them: page 2500 of 20 took 254 queries and hydrated 50,000
 * models, so one ?page= request past the clampPerPage() limit could exhaust a worker. A page now reads
 * only its own rows, as the Scout engine does; only getSearchScore()'s window of the first
 * max_candidates matches is still read from rank 1, and it is bounded. _score keeps its scale on every
 * page: it is normalised against the first row the query accepts.
 */
class IndexDeepPageCostTest extends TestCase
{
    private const PER_PAGE = 10;

    private int $hydrated = 0;

    /** 300 "zeta" rows with 2 to 4 zetas each (and so different scores), indexed as each model class. */
    private function seedZetas(): void
    {
        DB::table('users')->delete();

        $rows = array_map(fn ($i) => [
            'name'  => str_repeat('zeta ', 1 + $i % 3) . sprintf('Zeta %04d', $i),
            'email' => "z{$i}@" . ($i % 2 === 0 ? 'even' : 'odd') . '.test',
        ], range(0, 299));

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        foreach ([User::class, SoftDeletedUser::class, DeepPageBoostedUser::class] as $class) {
            app(IndexManager::class)->indexBatch($class::all());
            Event::listen('eloquent.retrieved: ' . $class, fn () => $this->hydrated++);
        }
    }

    /** @return array{int, int, array} the statements $page runs, the models it hydrates, and what it serves */
    private function cost(\Closure $page): array
    {
        $this->hydrated = 0;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = collect($page());
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$queries, $this->hydrated, $rows->map(fn ($row) => [$row->name, $row->_raw_score, $row->_score])->all()];
    }

    /** @return array<string, \Closure(\Closure(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder, int): iterable> each terminal's page $page */
    private static function terminals(): array
    {
        return [
            'paginate'       => fn (\Closure $make, int $page) => $make()->paginate(self::PER_PAGE, 'page', $page)->items(),
            'simplePaginate' => fn (\Closure $make, int $page) => $make()->simplePaginate(self::PER_PAGE, 'page', $page)->items(),
            'skip/take/get'  => fn (\Closure $make, int $page) => $make()->skip(($page - 1) * self::PER_PAGE)->take(self::PER_PAGE)->get(),
        ];
    }

    /** @return array<string, array{\Closure(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder, int}> each search and its total */
    private static function searches(): array
    {
        return [
            'no constraint'             => [fn () => User::search('zeta')->typoTolerance(0)->useInvertedIndex(), 300],
            'where()'                   => [fn () => User::search('zeta')->typoTolerance(0)->useInvertedIndex()->where('email', 'like', '%@even.test'), 150],
            'SoftDeletes'               => [fn () => SoftDeletedUser::search('zeta')->typoTolerance(0)->useInvertedIndex(), 300],
            'SoftDeletes, where()'      => [fn () => SoftDeletedUser::search('zeta')->typoTolerance(0)->useInvertedIndex()->where('email', 'like', '%@odd.test'), 150],
        ];
    }

    public function test_an_unordered_deep_page_costs_what_page_1_costs(): void
    {
        $this->seedZetas();

        // 1000 (the default) and 50: on SQL Server, the constraint checks the 300 ranked ids by key,
        // then through the postings subquery; elsewhere, by key in one read either way.
        foreach ([1000, 50] as $maxCandidates) {
            config(['fuzzy-search.max_candidates' => $maxCandidates]);

            foreach (self::searches() as $search => [$make, $total]) {
                $last = (int) ceil($total / self::PER_PAGE);

                foreach (self::terminals() as $terminal => $page) {
                    $at = "{$search}, {$terminal}, max_candidates {$maxCandidates}";
                    $page($make, 1); // warms the once-per-process reads, such as MySQL's model_id collation

                    $queries = $this->cost(fn () => $page($make, 1))[0];
                    $ahead   = $terminal === 'simplePaginate' ? 1 : 0; // simplePaginate() reads one row ahead, except on the last page
                    $pages   = [1, intdiv($last, 2), $last];

                    $this->assertSame(
                        array_map(fn ($p) => [$p, $queries, self::PER_PAGE + ($p === $last ? 0 : $ahead)], $pages),
                        array_map(fn ($p) => [$p, ...array_slice($this->cost(fn () => $page($make, $p)), 0, 2)], $pages),
                        "{$at}: [page, queries, models hydrated]"
                    );
                }
            }
        }
    }

    /**
     * _score on every page is the row's BM25 score over the first accepted row's, as it was on
     * page 1 when every page was cut from rank 1: the same row scores the same on its page as in one
     * read of every row.
     */
    public function test_scores_keep_their_scale_on_every_page(): void
    {
        $this->seedZetas();

        foreach (self::searches() as $search => [$make, $total]) {
            $all = $this->cost(fn () => $make()->take($total)->get())[2];

            $this->assertCount($total, $all);
            $this->assertSame(array_map(fn ($row) => [$row[0], $row[1], round($row[1] / $all[0][1], 6)], $all), $all, "{$search}: _score is the BM25 score over the first accepted row's");

            foreach (self::terminals() as $terminal => $page) {
                $served = [];
                for ($p = 1; $p <= (int) ceil($total / self::PER_PAGE); $p++) {
                    $served = [...$served, ...array_slice($this->cost(fn () => $page($make, $p))[2], 0, self::PER_PAGE)];
                }

                $this->assertSame($all, $served, "{$search}, {$terminal}: the pages serve every row once, scored as one read of them all");
            }
        }
    }

    /**
     * getSearchScore() re-ranks the first max_candidates matches, so a page inside that window is cut
     * from all of it. A page past it reads the window (for the scale) and its own rows: bounded by
     * max_candidates, however deep.
     */
    public function test_a_score_hook_reads_its_window_and_the_page(): void
    {
        $this->seedZetas();
        config(['fuzzy-search.max_candidates' => 25]);

        $make = fn () => DeepPageBoostedUser::search('zeta')->typoTolerance(0)->useInvertedIndex();
        $all  = $this->cost(fn () => $make()->take(300)->get())[2];

        $this->assertSame('zeta zeta zeta Zeta 0062', $all[0][0]);
        $this->assertSame(1.0, $all[0][2]);

        $served = [];
        for ($p = 1; $p <= 30; $p++) {
            [$queries, $hydrated, $rows] = $this->cost(fn () => $make()->paginate(self::PER_PAGE, 'page', $p)->items());
            $served = [...$served, ...$rows];

            $this->assertLessThanOrEqual(25 + self::PER_PAGE, $hydrated, "page {$p}");
        }

        $this->assertSame($all, $served);
    }
}
