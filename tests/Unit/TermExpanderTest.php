<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class TermExpanderTest extends TestCase
{
    private function seedTerm(string $term, int $docCount): void
    {
        DB::table('fuzzy_index_terms')->insert([
            'term' => $term, 'doc_count' => $docCount, 'term_length' => mb_strlen($term),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTerm('john', 50);
        $this->seedTerm('jon', 20);
        $this->seedTerm('joan', 10);
        $this->seedTerm('johnny', 5);
        $this->seedTerm('jane', 40);
        $this->seedTerm('2024', 1);
        // No accent-only pairs (café/cafe) in the dictionary: MySQL's utf8mb4 *_ci collations
        // treat them as equal and the unique key on `term` would reject the second insert.
    }

    public function test_candidates_are_within_distance_and_ordered_by_doc_count(): void
    {
        $candidates = (new TermExpander)->candidates('jonh', 2, 500);

        // jane (o→a, h→e) and joan (n→a, h→n) are two edits away too; ordered by doc_count.
        $this->assertSame(['john', 'jane', 'jon', 'joan'], array_column($candidates, 'term'));
        $this->assertSame(2, $candidates[0]['distance']); // jonh → john is a transposition: two edits
        $this->assertSame(50, $candidates[0]['doc_count']);
    }

    public function test_candidates_exclude_the_term_itself_and_far_terms(): void
    {
        $terms = array_column((new TermExpander)->candidates('john', 1, 500), 'term');

        $this->assertNotContains('john', $terms);
        $this->assertContains('jon', $terms);
        $this->assertNotContains('jane', $terms); // distance 2
        $this->assertNotContains('johnny', $terms); // distance 2
    }

    public function test_expand_keeps_exact_terms_at_full_weight_and_damps_expansions(): void
    {
        $weights = (new TermExpander)->expand(['jonh', 'jane'], 2, 4, 5, 500, true);

        $this->assertSame(1.0, $weights['jonh']);
        $this->assertSame(1.0, $weights['jane']);  // an exact term is never downgraded by being jonh's neighbour
        $this->assertSame(0.5, $weights['john']);  // 1 - 2/4
        $this->assertSame(0.75, $weights['jon']);  // 1 - 1/4
        $this->assertSame(0.5, $weights['joan']);  // 1 - 2/4
        $this->assertArrayNotHasKey('johnny', $weights); // distance 3 from jonh
    }

    public function test_expand_respects_min_word_length_max_expansions_and_zero_distance(): void
    {
        $expander = new TermExpander;

        $this->assertSame(['jon' => 1.0], $expander->expand(['jon'], 2, 4, 5, 500, true));
        $this->assertCount(2, $expander->expand(['jonh'], 2, 4, 1, 500, true)); // jonh + one expansion
        $this->assertSame(['jonh' => 1.0], $expander->expand(['jonh'], 0, 4, 5, 500, true));
        $this->assertSame(1.0, $expander->expand(['jonh'], 2, 4, 5, 500, false)['john']);
    }

    public function test_expand_keeps_the_higher_weight_when_a_term_is_reached_twice(): void
    {
        $weights = (new TermExpander)->expand(['john', 'jonh'], 2, 4, 5, 500, true);

        $this->assertSame(1.0, $weights['john']);
    }

    public function test_the_expansion_cap_keeps_the_closest_terms(): void
    {
        // john is the most common candidate but two edits away; jon is one edit away and wins the single slot.
        $weights = (new TermExpander)->expand(['jonh'], 2, 4, 1, 500, true);

        $this->assertSame(0.75, $weights['jon']);
        $this->assertArrayNotHasKey('john', $weights);
    }

    public function test_expansions_at_or_beyond_the_term_length_are_dropped(): void
    {
        $this->seedTerm('zzzz', 1); // four edits from a four-character term: weight 0

        $weights = (new TermExpander)->expand(['john'], 4, 4, 5, 500, true);

        foreach ($weights as $term => $weight) {
            $this->assertGreaterThan(0, $weight, "weight for {$term}");
        }

        $this->assertArrayNotHasKey('zzzz', $weights);
    }

    public function test_prefix_finds_an_accented_term(): void
    {
        $this->seedTerm('café', 3);

        $this->assertSame(['café' => 1.0], (new TermExpander)->prefix('caf', 10));
    }

    public function test_prefix_works_when_the_prefix_ends_at_the_top_of_its_range(): void
    {
        // '{' (after 'z') and ':' (after '9') sort BELOW letters and digits under a UCA
        // collation, so a naive range upper bound returns nothing on PostgreSQL/SQL Server.
        $this->seedTerm('cruz', 9);
        $this->seedTerm('cruzeiro', 4);
        $this->seedTerm('199', 8);
        $this->seedTerm('1999', 3);

        $this->assertArrayHasKey('cruzeiro', (new TermExpander)->prefix('cruz', 10));
        $this->assertArrayHasKey('1999', (new TermExpander)->prefix('199', 10));
    }

    public function test_prefix_returns_the_most_common_terms_starting_with_the_prefix(): void
    {
        $this->assertSame(['john' => 1.0, 'jon' => 1.0, 'joan' => 1.0, 'johnny' => 1.0], (new TermExpander)->prefix('jo', 10));
        $this->assertSame(['john' => 1.0, 'jon' => 1.0], (new TermExpander)->prefix('jo', 2));
        $this->assertSame(['johnny' => 1.0], (new TermExpander)->prefix('john', 10)); // the exact term is excluded
        $this->assertSame([], (new TermExpander)->prefix('', 10));
    }

    public function test_prefix_can_be_scoped_to_a_model(): void
    {
        $johnId = DB::table('fuzzy_index_terms')->where('term', 'john')->value('id');
        DB::table('fuzzy_index_postings')->insert([
            'term_id' => $johnId, 'model_type' => 'App\\Models\\User', 'model_id' => '1', 'frequency' => 1, 'column_name' => 'name',
        ]);
        $jonId = DB::table('fuzzy_index_terms')->where('term', 'jon')->value('id');
        DB::table('fuzzy_index_postings')->insert([
            'term_id' => $jonId, 'model_type' => 'App\\Models\\Product', 'model_id' => '1', 'frequency' => 1, 'column_name' => 'name',
        ]);

        $scoped = (new TermExpander)->prefix('jo', 10, 'App\\Models\\User');

        $this->assertArrayHasKey('john', $scoped);
        $this->assertArrayNotHasKey('jon', $scoped); // posted only under App\Models\Product
        $this->assertArrayNotHasKey('joan', $scoped); // never posted under any model
        $this->assertArrayHasKey('jon', (new TermExpander)->prefix('jo', 10)); // unscoped still sees it
    }

    public function test_distance_counts_characters_not_bytes(): void
    {
        $this->assertSame(1, TermExpander::distance('café', 'cafe'));
        $this->assertSame(2, TermExpander::distance('jonh', 'john'));
        $this->assertSame(0, TermExpander::distance('', ''));
        $this->assertSame(3, TermExpander::distance('', 'abc'));
    }

    public function test_numeric_terms_survive_as_strings(): void
    {
        $this->assertSame(['2024' => 1.0], (new TermExpander)->prefix('202', 10));
        $this->assertSame([], (new TermExpander)->candidates('2024', 0, 500));
    }
}
