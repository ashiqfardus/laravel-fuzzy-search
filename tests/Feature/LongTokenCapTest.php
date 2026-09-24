<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * Index terms are capped at 191 characters, at index time and on every query-side lookup:
 * the BM25 terms, the prefix and typo expansions, didYouMean() and the index-path suggest()
 * (ruling ER-48). MySQL keys fuzzy_index_terms on term(191), so two longer tokens that shared
 * their first 191 characters collided there, and the second one's postings were dropped.
 */
class LongTokenCapTest extends TestCase
{
    private string $a;
    private string $b;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fuzzy-search.query.max_term_length' => 300]); // let the whole token reach the query side

        // 250 characters each, equal up to character 200.
        $this->a = str_repeat('a', 200) . 'x' . str_repeat('b', 49);
        $this->b = str_repeat('a', 200) . 'y' . str_repeat('b', 49);

        $this->userA = User::create(['name' => $this->a, 'email' => 'long-a@example.com']);
        $this->userB = User::create(['name' => $this->b, 'email' => 'long-b@example.com']);
        app(IndexManager::class)->indexModel($this->userA);
        app(IndexManager::class)->indexModel($this->userB);
    }

    private function found(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder $search): array
    {
        return $search->get()->map(fn ($m) => $m->getKey())->all();
    }

    public function test_two_long_tokens_that_differ_after_character_200_both_index_and_both_find_their_rows(): void
    {
        foreach ([[$this->a, $this->userA], [$this->b, $this->userB]] as [$token, $user]) {
            $this->assertContains($user->getKey(), $this->found(User::search($token)->useInvertedIndex()->typoTolerance(0)));
        }

        $long = DB::table('fuzzy_index_terms')->where('term', 'like', 'aaaa%')->pluck('term')->all();
        $this->assertSame([191], array_values(array_unique(array_map('mb_strlen', $long))));
    }

    public function test_a_250_character_token_is_found_verbatim_through_bm25_the_prefix_and_the_typo_expansion(): void
    {
        $id = $this->userA->getKey();

        // Plain BM25.
        $this->assertContains($id, $this->found(User::search($this->a)->useInvertedIndex()->typoTolerance(0)));

        // Prefix expansion (as-you-type): the whole token, a prefix longer than the cap, and one
        // shorter than it, which only the prefix expansion can reach.
        foreach ([250, 220, 150] as $length) {
            $typed = mb_substr($this->a, 0, $length);
            $this->assertContains($id, $this->found(User::search($typed)->useInvertedIndex()->typoTolerance(0)->asYouType()), "{$length} characters");
        }

        // Typo expansion: 250 characters with one wrong character inside the capped part.
        $typo = substr_replace($this->a, 'z', 50, 1);
        $this->assertContains($id, $this->found(User::search($typo)->useInvertedIndex()->typoTolerance(1)));
        $this->assertNotContains($id, $this->found(User::search($typo)->useInvertedIndex()->typoTolerance(0)));
    }

    public function test_did_you_mean_and_suggest_look_up_the_capped_term(): void
    {
        $capped = mb_substr($this->a, 0, 191);
        $typo   = substr_replace($this->a, 'z', 50, 1);

        $this->assertContains($capped, array_column(User::search($typo)->didYouMean(), 'term'));
        $this->assertContains($capped, User::search(mb_substr($this->a, 0, 150))->useInvertedIndex()->suggestFrom('index')->suggest());
    }

    public function test_the_pipeline_caps_every_token_at_191_characters(): void
    {
        $tokens = app(IndexManager::class)->processTerms(str_repeat('é', 300) . ' short');

        $this->assertSame(191, mb_strlen($tokens[0]));
        $this->assertSame('short', $tokens[1]);
    }
}
