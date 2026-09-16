<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

/**
 * The builder's length guards (min_search_length, query.max_term_length) counted bytes.
 * A two-character Bengali term is six bytes, so it slipped past a three-character minimum,
 * and a long term was cut mid-character into invalid UTF-8 before reaching the drivers.
 */
class MultibyteSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['db']->table('users')->insert([
            ['name' => 'মোবাইলফোন', 'email' => 'bn1@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'ল্যাপটপ', 'email' => 'bn2@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_multibyte_term_finds_the_matching_row(): void
    {
        $results = User::search('মোবাইল')->using('fuzzy')->get();

        $this->assertTrue($results->contains('name', 'মোবাইলফোন'));
    }

    public function test_min_search_length_counts_characters_not_bytes(): void
    {
        config(['fuzzy-search.min_search_length' => 3]);

        // 'ফো' is two characters (six bytes) — below the minimum.
        $this->assertCount(0, User::search('ফো')->using('fuzzy')->get());

        // 'ফোন' is three characters — allowed.
        $this->assertTrue(User::search('ফোন')->using('fuzzy')->get()->contains('name', 'মোবাইলফোন'));
    }

    public function test_max_term_length_truncates_by_character(): void
    {
        config(['fuzzy-search.query.max_term_length' => 7]);

        // 11 characters in, 7 out — and still a valid prefix of the stored name.
        $results = User::search('মোবাইলফোনXX')->using('fuzzy')->debugScore()->get();

        $this->assertTrue($results->contains('name', 'মোবাইলফোন'));
        $this->assertSame('মোবাইলফ', $results->first()->_debug['term']);
    }
}
