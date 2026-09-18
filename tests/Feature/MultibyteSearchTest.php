<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
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

    /*
    | Case-insensitive scoring and highlighting per character. Each fixture row also carries the
    | term in its exact case in `email`, so the candidate query finds it on every database
    | (SQLite's LIKE folds ASCII only) and `name` exercises the PHP side alone.
    */

    private function insertUser(string $name, string $email): void
    {
        $this->app['db']->table('users')->insert([
            'name' => $name, 'email' => $email, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_lower_case_cyrillic_term_scores_an_upper_case_value_as_a_prefix_match(): void
    {
        $this->insertUser('ПРИВЕТ мир', 'привет@example.com');

        $row = User::search('привет')->using('like')->debugScore()->get()->firstWhere('name', 'ПРИВЕТ мир');

        // prefix_match (80) x the name weight (10); byte strtolower() left the value upper-case
        // and it fell through to the fuzzy floor.
        $this->assertEqualsWithDelta(800.0, $row->_debug['column_scores']['name'], 0.001);
    }

    public function test_mixed_case_multibyte_values_are_highlighted_per_character(): void
    {
        $this->insertUser('ПРИВЕТ мир', 'привет@example.com');
        $this->insertUser('Größe ÜBER Maß', 'über@example.com');
        $this->insertUser('GÜNEŞ ışığı', 'güneş@example.com');
        // The Kelvin sign (3 bytes) is an upper-case k (1 byte): each match's own length counts.
        $this->insertUser("\u{212A}ELVIN scale", 'kelvin@example.com');

        $cases = [
            'привет' => ['ПРИВЕТ мир', '<mark>ПРИВЕТ</mark> мир', [[0, 11]]],
            'über'   => ['Größe ÜBER Maß', 'Größe <mark>ÜBER</mark> Maß', [[8, 12]]],
            'güneş'  => ['GÜNEŞ ışığı', '<mark>GÜNEŞ</mark> ışığı', [[0, 6]]],
            'kelvin' => ["\u{212A}ELVIN scale", "<mark>\u{212A}ELVIN</mark> scale", [[0, 7]]],
        ];

        foreach ($cases as $term => [$name, $expected, $indices]) {
            $row = User::search($term)->using('like')->highlight('mark')->get()->firstWhere('name', $name);

            $this->assertNotNull($row, $term);
            $this->assertSame($expected, $row->_highlighted['name'], $term);
            $this->assertSame($expected, SearchBuilder::renderHighlighted($row, 'name'), $term);

            // _matches carries byte offsets — the unit substr() and wrapWithTags() slice by.
            $match = collect($row->_matches)->firstWhere('column', 'name');
            $this->assertSame($indices, $match['indices'], $term);
        }
    }

    /**
     * A crafted `?q=john%C3` used to reach the bind parameters as-is: PostgreSQL (SQLSTATE 22021)
     * and SQL Server (IMSSP, "translating string ... to UCS-2") rejected it, a 500 on every
     * search. The invalid bytes are dropped where the term enters, so every database searches
     * the cleaned term.
     */
    public function test_an_invalid_utf8_term_searches_the_cleaned_term_on_every_database(): void
    {
        foreach (["john\xC3" => 'john', "jo\xC3hn" => 'john', "doe\xFF" => 'doe'] as $term => $clean) {
            foreach (['fuzzy', 'levenshtein'] as $algorithm) {
                $rows = User::search($term)->using($algorithm)->highlight('mark')->get();
                $this->assertNotEmpty($rows, bin2hex($term) . " / {$algorithm}");
                $this->assertArrayHasKey('name', $rows->first()->_highlighted);
                $this->assertSame(
                    User::search($clean)->using($algorithm)->get()->pluck('id')->all(),
                    $rows->pluck('id')->all(),
                    bin2hex($term) . " / {$algorithm}",
                );
            }

            $this->assertNotEmpty(User::search($term)->highlight('mark')->paginate(5)->items(), bin2hex($term) . ' / paginate');
        }
    }

    public function test_suggest_needs_two_characters_not_two_bytes(): void
    {
        $this->insertUser('কলম', 'pen@example.com');

        // One Bengali character is three bytes: it used to pass a two-byte minimum.
        $this->assertSame([], User::search('ক')->searchIn(['name'])->suggest());
        $this->assertContains('কলম', User::search('কল')->searchIn(['name'])->suggest());
    }
}
