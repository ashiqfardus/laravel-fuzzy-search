<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/** An empty column earns nothing for a short term: it is not "within two edits" of it. */
class EmptyColumnScoreTest extends TestCase
{
    public function test_a_row_with_data_in_the_other_column_does_not_rank_below_an_empty_one(): void
    {
        DB::table('users')->delete();
        DB::table('users')->insert([
            ['name' => 'Zed Alpha', 'email' => '', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Zed Alpha', 'email' => 'nothing here', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $rows = User::searchOn(User::query(), 'ze', ['name', 'email'])->get();

        $this->assertCount(2, $rows);
        $empty = $rows->firstWhere('email', '');
        $full  = $rows->firstWhere('email', 'nothing here');
        $this->assertLessThanOrEqual($full->_raw_score, $empty->_raw_score);
        $this->assertSame('nothing here', $rows->first()->email);
    }
}
