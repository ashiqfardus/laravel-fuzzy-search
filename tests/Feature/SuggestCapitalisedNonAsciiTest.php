<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * suggest()'s table scan compares in PHP what the database's LIKE found, folding case in every
 * script: "Émile" completes "émi". (SQLite's LIKE folds ASCII only, so it never finds the row.)
 */
class SuggestCapitalisedNonAsciiTest extends TestCase
{
    public function test_a_capitalised_non_ascii_word_completes_a_lower_case_prefix(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['sqlite'], true)) {
            $this->markTestSkipped('SQLite LIKE is case-sensitive for non-ASCII letters: the scan never finds the row. CI covers the other databases.');
        }
        DB::table('users')->insert(['name' => 'Émile Zola', 'email' => 'ez@x.test', 'created_at' => now(), 'updated_at' => now()]);

        $suggestions = User::searchOn(User::query(), 'émi', ['name'])->suggest();

        $this->assertContains('Émile', $suggestions);
        $this->assertContains('Émile Zola', $suggestions);
    }
}
