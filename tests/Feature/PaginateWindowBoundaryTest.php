<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * paginate() ranks the first max_candidates rows and serves deeper pages in database order. A
 * page that straddled max_candidates was cut from the window alone, so it came back short, and the
 * next page began past the window: the rows between were never served (max_candidates 3, four
 * matches, two per page: "Bob Johnson" never showed, while total() said 4). With the defaults,
 * page 67 of 15 had 10 rows and rows 1000-1004 were unreachable.
 */
class PaginateWindowBoundaryTest extends TestCase
{
    public function test_every_match_is_served_exactly_once_across_the_max_candidates_boundary(): void
    {
        DB::table('users')->insert(['name' => 'John Newman', 'email' => 'newman@example.com']);

        $paths = [
            'like'     => fn () => User::search('john')->using('like')->stableRanking(),
            'extended' => fn () => User::search('')->extended('john')->stableRanking(),
        ];

        foreach ($paths as $path => $make) {
            $all = $make()->get()->pluck('name')->sort()->values()->all();
            $this->assertCount(4, $all, $path);

            foreach ([['max' => 3, 'perPage' => 2], ['max' => 3, 'perPage' => 3], ['max' => 2, 'perPage' => 2]] as $case) {
                config(['fuzzy-search.max_candidates' => $case['max']]);
                $label = "{$path}, max_candidates {$case['max']}, {$case['perPage']} per page";

                $served = [];
                foreach (range(1, 4) as $page) {
                    $paginator = $make()->paginate($case['perPage'], 'page', $page);
                    $this->assertSame(4, $paginator->total(), $label);
                    $this->assertCount(max(0, min($case['perPage'], 4 - ($page - 1) * $case['perPage'])), $paginator->items(), "{$label}, page {$page}");
                    $served = [...$served, ...collect($paginator->items())->pluck('name')->all()];
                }

                sort($served);
                $this->assertSame($all, $served, $label);
            }

            config(['fuzzy-search.max_candidates' => 1000]);
        }
    }
}
