<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * The SQL exact tier compares with the case rule of the search's own predicate. On SQLite and
 * PostgreSQL a plain "=" is case-sensitive, so "John" for "john" scored as a prefix hit like every
 * "johnson N" and, past max_candidates, fell out of the candidate window.
 */
class ExactTierCaseTest extends TestCase
{
    public function test_the_row_that_is_the_term_stays_in_the_candidate_window(): void
    {
        config(['fuzzy-search.max_candidates' => 20]);
        DB::table('users')->delete();

        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = ['name' => "johnson {$i}", 'email' => "j{$i}@x.test", 'created_at' => now(), 'updated_at' => now()];
        }
        $rows[] = ['name' => 'John', 'email' => 'john@x.test', 'created_at' => now(), 'updated_at' => now()];
        DB::table('users')->insert($rows);

        $search = fn () => User::searchOn(User::query(), 'john', ['name'])->stableRanking();

        $this->assertSame('John', $search()->first()?->name);
        $this->assertSame('John', $search()->get()->first()->name);
        $this->assertSame('John', $search()->paginate(5)->items()[0]->name);
        $this->assertSame('John', $search()->simplePaginate(5)->items()[0]->name);
    }
}
