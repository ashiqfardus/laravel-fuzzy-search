<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Where PHP keeps the SQL order (an explicit orderBy(), withRelevance(false)) the LIKE and extended
 * paths read only the rows up to the end of the page; a relevance-ranked get() reads the
 * max_candidates window it rescores.
 */
class LikeCandidateWindowTest extends TestCase
{
    /** @return list<int> the number of rows each read of the users table asked for */
    private function limits(\Closure $search): array
    {
        $limits = [];
        DB::listen(function (QueryExecuted $query) use (&$limits) {
            if (preg_match('/\b(?:limit|top|fetch next)\s+\(?(\d+)/i', $query->sql, $m) === 1) {
                $limits[] = (int) $m[1];
            }
        });
        $search();

        return $limits;
    }

    public function test_an_ordered_search_reads_the_page_not_the_window(): void
    {
        config(['fuzzy-search.max_candidates' => 50]);

        $rows = null;
        $this->assertSame([2], $this->limits(function () use (&$rows) {
            $rows = User::searchOn(User::query(), 'jo', ['name'])->using('simple')->orderBy('name')->take(2)->get();
        }));
        $this->assertSame(['Bob Johnson', 'John Doe'], $rows->pluck('name')->all());
    }

    public function test_an_unscored_search_reads_the_page_not_the_window(): void
    {
        config(['fuzzy-search.max_candidates' => 50]);

        $this->assertSame([2], $this->limits(fn () => User::searchOn(User::query(), 'jo', ['name'])->using('simple')->withRelevance(false)->take(2)->get()));
        $this->assertSame([3], $this->limits(fn () => User::searchOn(User::query(), 'jo', ['name'])->using('simple')->withRelevance(false)->skip(1)->take(2)->get()));
    }

    public function test_the_extended_path_reads_the_page_where_it_keeps_the_sql_order(): void
    {
        config(['fuzzy-search.max_candidates' => 50]);

        $this->assertSame([2], $this->limits(fn () => User::searchOn(User::query(), '', ['name'])->extended('jo')->orderBy('name')->take(2)->get()));
        $this->assertSame([50], $this->limits(fn () => User::searchOn(User::query(), '', ['name'])->extended('jo')->take(2)->get()));
    }

    public function test_a_relevance_ranked_search_still_reads_the_window(): void
    {
        config(['fuzzy-search.max_candidates' => 50]);

        $this->assertSame([50], $this->limits(fn () => User::searchOn(User::query(), 'jo', ['name'])->using('simple')->take(2)->get()));
        $this->assertSame([50], $this->limits(fn () => User::searchOn(User::query(), 'jo', ['name'])->using('simple')->first()));
    }

    public function test_the_ordered_page_is_the_same_as_before(): void
    {
        $page = fn (int $max) => (function () use ($max) {
            config(['fuzzy-search.max_candidates' => $max]);

            return User::searchOn(User::query(), 'o', ['name'])->using('simple')->orderBy('name')->skip(1)->take(3)->get()->pluck('name')->all();
        })();

        $this->assertSame($page(1000), $page(4));
    }
}
