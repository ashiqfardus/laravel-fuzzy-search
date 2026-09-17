<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class Bm25WeightedTermsTest extends TestCase
{
    private User $exact;
    private User $typo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exact = User::create(['name' => 'john smith', 'email' => 'js1@example.com']);
        $this->typo  = User::create(['name' => 'jonh smith', 'email' => 'js2@example.com']);
        $third       = User::create(['name' => 'year 2024', 'email' => 'y@example.com']);
        app(IndexManager::class)->indexBatch(collect([$this->exact, $this->typo, $third]));
    }

    public function test_a_plain_list_scores_every_term_equally(): void
    {
        $ranked = app(Bm25Scorer::class)->rank(['john', 'jonh'], User::class);

        $this->assertEqualsWithDelta($ranked[$this->exact->id], $ranked[$this->typo->id], 0.000001);
    }

    public function test_weights_scale_each_terms_contribution(): void
    {
        $ranked = app(Bm25Scorer::class)->rank(['john' => 1.0, 'jonh' => 0.5], User::class);

        $this->assertSame($this->exact->id, array_key_first($ranked));
        $this->assertEqualsWithDelta($ranked[$this->exact->id] * 0.5, $ranked[$this->typo->id], 0.000001);
    }

    public function test_count_and_search_accept_the_weighted_shape(): void
    {
        $scorer = app(Bm25Scorer::class);

        $this->assertSame(2, $scorer->count(['john' => 1.0, 'jonh' => 0.5], User::class));
        $this->assertSame(2, $scorer->count(['john', 'jonh'], User::class));
        $this->assertCount(1, $scorer->search(['john' => 1.0, 'jonh' => 0.5], User::class, 1));
    }

    public function test_numeric_terms_are_bound_as_strings(): void
    {
        $scorer = app(Bm25Scorer::class);

        $this->assertSame(1, $scorer->count(['2024' => 1.0], User::class));
        $this->assertSame(1, $scorer->count(['2024'], User::class));
        $this->assertArrayHasKey(User::where('email', 'y@example.com')->value('id'), $scorer->rank(['2024' => 1.0], User::class));
    }
}
