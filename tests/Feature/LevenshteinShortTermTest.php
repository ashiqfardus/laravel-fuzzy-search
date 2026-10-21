<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/** The levenshtein driver builds no typo pattern for a short term, nor one that is a letter or two. */
class LevenshteinShortTermTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert([
            ['name' => 'Monica Geller', 'email' => 'mg@x.test', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Rachel Green', 'email' => 'rg@x.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function search(string $term)
    {
        return fn () => User::searchOn(User::query(), $term, ['name'])->using('levenshtein');
    }

    public function test_a_two_letter_term_matches_only_rows_containing_it(): void
    {
        $search = $this->search('jo');

        $names = $search()->get()->pluck('name')->sort()->values()->all();
        $this->assertSame(['Bob Johnson', 'John Doe', 'Johnny Bravo', 'Jon Snow'], $names);
        $this->assertNotContains('Monica Geller', $names);
        $this->assertNotContains('Rachel Green', $names);
        $this->assertSame(count($names), $search()->count());
        $this->assertSame(count($names), $search()->paginate(50)->total());
    }

    public function test_a_four_letter_term_does_not_match_rows_sharing_two_letters(): void
    {
        $search = $this->search('john');

        $this->assertNotContains('Monica Geller', $search()->get()->pluck('name')->all());
        $this->assertNotContains('Monica Geller', $search()->simplePaginate(50)->pluck('name')->all());
        $this->assertSame($search()->get()->count(), $search()->count());
    }

    public function test_one_edit_typos_still_match(): void
    {
        $this->assertContains('John Doe', $this->search('jonh')()->get()->pluck('name')->all());
        $this->assertContains('John Doe', $this->search('johm')()->get()->pluck('name')->all());
    }
}
