<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * soundex matches by sound on every database: MySQL, MariaDB and SQL Server through SOUNDEX(),
 * SQLite through the package's fuzzy_soundex(), PostgreSQL through fuzzystrmatch when it is there.
 * Each compares the term's first and last word with the column's first and last word, so the same
 * rows come back everywhere.
 */
class SoundexEveryDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'pgsql') {
            config(['fuzzy-search.use_native_functions' => true]);
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
            } catch (\Throwable $e) {
                $this->markTestSkipped('PostgreSQL without fuzzystrmatch takes the pattern fallback: ' . $e->getMessage());
            }
        }

        DB::table('users')->insert([
            ['name' => 'Robert Plant', 'email' => 'rp@x.test', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Rupert Smyth', 'email' => 'rs@x.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function names(string $term): array
    {
        return User::searchOn(User::query(), $term, ['name'])->using('soundex')->get()->pluck('name')->sort()->values()->all();
    }

    public function test_jon_finds_every_name_that_sounds_like_it(): void
    {
        $this->assertSame(['Jane Doe', 'John Doe', 'Johnny Bravo', 'Jon Snow'], $this->names('jon'));
    }

    public function test_robert_finds_rupert(): void
    {
        $this->assertSame(['Robert Plant', 'Rupert Smyth'], $this->names('robert'));
    }

    public function test_smith_finds_smyth(): void
    {
        $this->assertSame(['Alice Smith', 'Rupert Smyth'], $this->names('smith'));
    }

    public function test_the_other_terminals_agree(): void
    {
        $search = fn () => User::searchOn(User::query(), 'smith', ['name'])->using('soundex');

        $this->assertSame(2, $search()->count());
        $this->assertSame(2, $search()->paginate(10)->total());
        $this->assertNotNull($search()->first());
    }

    public function test_a_term_without_a_letter_matches_no_letterless_rows(): void
    {
        $this->assertSame([], $this->names('99'));
    }
}
