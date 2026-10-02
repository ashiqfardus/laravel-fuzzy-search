<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RankHookFreeUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * SF-7 (ruling ER-149). customScore() and boostRecent() were applied only by the LIKE and extended
 * rescoring: on useInvertedIndex() they did nothing, silently, and rows came back in plain BM25
 * order. They now apply on the index path too, after getSearchScore(), and re-rank the same window
 * that hook does (the first max_candidates matches); deeper pages keep the BM25 order, so every
 * match is on exactly one page and total() is unchanged.
 */
class IndexCustomScoreRecencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('users')->delete();
    }

    /** "Zed Alpha" (inserted first: it wins BM25 and LIKE ties) is 200 days old, "Zed Beta" new. */
    private function seedTwo(): void
    {
        DB::table('users')->insert(['name' => 'Zed Alpha', 'email' => 'a@example.com', 'created_at' => now()->subDays(200), 'updated_at' => now()]);
        DB::table('users')->insert(['name' => 'Zed Beta', 'email' => 'b@example.com', 'created_at' => now(), 'updated_at' => now()]);
        app(IndexManager::class)->indexBatch(RankHookFreeUser::all());
    }

    public function test_each_hook_reorders_every_path_and_terminal(): void
    {
        $this->seedTwo();

        $paths = [
            'like'     => fn () => RankHookFreeUser::search('zed'),
            'extended' => fn () => RankHookFreeUser::search('')->extended('zed'),
            'index'    => fn () => RankHookFreeUser::search('zed')->useInvertedIndex(),
        ];
        $hooks = [
            // [the hook, Zed Alpha's _score once Zed Beta leads]
            'boostRecent' => [fn (SearchBuilder $b) => $b->boostRecent(5.0), 0.2],
            'customScore' => [fn (SearchBuilder $b) => $b->customScore(fn ($row, $score) => $row->name === 'Zed Beta' ? $score * 10 : $score), 0.1],
        ];

        foreach ($paths as $path => $make) {
            $this->assertSame(['Zed Alpha', 'Zed Beta'], $make()->get()->pluck('name')->all(), "{$path} plain");

            foreach ($hooks as $hook => [$apply, $alphaScore]) {
                $label = "{$path} {$hook}";
                $rows  = [
                    'get'            => $apply($make())->get()->all(),
                    'paginate'       => $apply($make())->paginate(5)->items(),
                    'simplePaginate' => $apply($make())->simplePaginate(5)->items(),
                ];

                foreach ($rows as $terminal => $page) {
                    $this->assertSame(['Zed Beta', 'Zed Alpha'], array_map(fn ($r) => $r->name, $page), "{$label} {$terminal}");
                    $this->assertEqualsWithDelta(1.0, $page[0]->_score, 0.0001, "{$label} {$terminal}");
                    $this->assertEqualsWithDelta($alphaScore, $page[1]->_score, 0.0001, "{$label} {$terminal}");
                }
                $this->assertSame('Zed Beta', $apply($make())->first()?->name, "{$label} first");
                $this->assertSame(2, $apply($make())->count(), "{$label} count");
            }
        }
    }

    public function test_the_index_re_ranks_the_first_max_candidates_and_pages_past_them_keep_bm25_order(): void
    {
        foreach (range(1, 12) as $i) {
            DB::table('users')->insert([
                'name'       => sprintf('Saga %02d', $i),
                'email'      => "saga{$i}@example.com",
                'created_at' => in_array($i, [4, 12], true) ? now() : now()->subDays(200),
            ]);
        }
        app(IndexManager::class)->indexBatch(RankHookFreeUser::all());
        config(['fuzzy-search.max_candidates' => 5, 'fuzzy-search.bm25.candidate_chunk' => 5]);

        // Equal BM25 scores rank by key. Each hook lifts Saga 04 and Saga 12, but re-ranks only the
        // first five: Saga 04 leads; Saga 12 is past the window and keeps its BM25 place.
        $expected = ['Saga 04', 'Saga 01', 'Saga 02', 'Saga 03', 'Saga 05', 'Saga 06', 'Saga 07', 'Saga 08', 'Saga 09', 'Saga 10', 'Saga 11', 'Saga 12'];
        $hooks    = [
            'boostRecent' => fn () => RankHookFreeUser::search('saga')->useInvertedIndex()->boostRecent(1000.0),
            'customScore' => fn () => RankHookFreeUser::search('saga')->useInvertedIndex()
                ->customScore(fn ($row, $score) => in_array($row->name, ['Saga 04', 'Saga 12'], true) ? $score * 1000 : $score),
        ];

        foreach ($hooks as $hook => $make) {
            $pages = [
                'paginate'       => fn (int $page) => $make()->paginate(5, 'page', $page)->items(),
                'simplePaginate' => fn (int $page) => $make()->simplePaginate(5, 'page', $page)->items(),
                'skip'           => fn (int $page) => $make()->skip(($page - 1) * 5)->take(5)->get()->all(),
            ];

            foreach ($pages as $terminal => $page) {
                $names = collect([...$page(1), ...$page(2), ...$page(3)])->pluck('name')->all();
                $this->assertSame($expected, $names, "{$hook} {$terminal}");
            }

            foreach ([1, 2, 3] as $page) {
                $this->assertSame(12, $make()->paginate(5, 'page', $page)->total(), "{$hook} total, page {$page}");
            }
            $this->assertSame(12, $make()->count(), "{$hook} count");
        }
    }

    /**
     * TE-4 (ruling ER-171). With a hook, _score was scaled against the best hooked score among the
     * rows a terminal read, the window and the page: once a row past the window outscored the
     * window, get() scored the top result far below 1 and a page past the window scored its own
     * first row 1, so one row had a different _score on each terminal. It is now scaled against the
     * window on every terminal and page, and a row past the window scores no more than the window's
     * last.
     */
    public function test_with_a_hook_every_terminal_scales_score_against_the_window(): void
    {
        foreach (range(1, 12) as $i) {
            DB::table('users')->insert([
                'name'       => sprintf('Saga %02d', $i),
                'email'      => "saga{$i}@example.com",
                'created_at' => $i > 5 ? now() : now()->subDays(200),
            ]);
        }
        app(IndexManager::class)->indexBatch(RankHookFreeUser::all());
        config(['fuzzy-search.max_candidates' => 5, 'fuzzy-search.bm25.candidate_chunk' => 5]);

        // Equal BM25 scores rank by key: Saga 01 to 05 are the window. Past it, Saga 06 to 12 outscore it a thousandfold.
        $hooks = [
            'customScore' => [fn () => RankHookFreeUser::search('saga')->useInvertedIndex()
                ->customScore(fn ($row, $score) => $score * match (true) {
                    $row->name === 'Saga 04'   => 2,
                    $row->name > 'Saga 05'     => 1000,
                    default                    => 1,
                }), ['Saga 04' => 1.0, 'Saga 01' => 0.5, 'Saga 02' => 0.5, 'Saga 03' => 0.5, 'Saga 05' => 0.5]],
            'boostRecent' => [fn () => RankHookFreeUser::search('saga')->useInvertedIndex()->boostRecent(1000.0),
                ['Saga 01' => 1.0, 'Saga 02' => 1.0, 'Saga 03' => 1.0, 'Saga 04' => 1.0, 'Saga 05' => 1.0]],
        ];

        foreach ($hooks as $hook => [$make, $window]) {
            $last     = end($window);
            $expected = $window + array_fill_keys(array_map(fn ($i) => sprintf('Saga %02d', $i), range(6, 12)), $last);
            $scores   = fn ($rows) => collect($rows)->mapWithKeys(fn ($row) => [$row->name => (float) $row->_score])->all();

            $this->assertEqualsWithDelta($expected, $scores($make()->take(1000)->get()), 0.000001, "{$hook}: get()");
            $this->assertSame(array_keys($expected), array_keys($scores($make()->take(1000)->get())), "{$hook}: the order");
            $this->assertEqualsWithDelta(1.0, (float) $make()->first()?->_score, 0.000001, "{$hook}: first()");

            $paged = [];
            foreach ([1, 2, 3] as $page) {
                $paged += $scores($make()->paginate(5, 'page', $page)->items());
                $this->assertEqualsWithDelta(array_slice($expected, ($page - 1) * 4, 4), $scores($make()->simplePaginate(4, 'page', $page)->items()), 0.000001, "{$hook}: simplePaginate page {$page}");
                $this->assertEqualsWithDelta(array_slice($expected, ($page - 1) * 5, 5), $scores($make()->skip(($page - 1) * 5)->take(5)->get()), 0.000001, "{$hook}: skip()->take() page {$page}");
            }
            $this->assertEqualsWithDelta($expected, $paged, 0.000001, "{$hook}: paginate()");
        }
    }
}
