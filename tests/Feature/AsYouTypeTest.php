<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;

class AsYouTypeUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'     => ['name' => 10],
        'as_you_type' => true,
    ];
}

class AsYouTypeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(IndexManager::class)->indexBatch(User::all());
    }

    public function test_the_last_token_is_expanded_by_prefix(): void
    {
        $names = User::search('joh')->useInvertedIndex()->asYouType()->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertContains('Johnny Bravo', $names);
    }

    public function test_without_as_you_type_a_prefix_matches_nothing(): void
    {
        $this->assertCount(0, User::search('joh')->useInvertedIndex()->get());
    }

    public function test_only_the_last_token_is_a_prefix(): void
    {
        // 'joh' is not last, so it is not prefix-expanded (and at 3 chars it is below
        // min_word_length, so no typo expansion either): Johnny Bravo must NOT appear.
        $names = User::search('joh doe')->useInvertedIndex()->asYouType()->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertContains('Jane Doe', $names);
        $this->assertNotContains('Johnny Bravo', $names);
    }

    public function test_a_prefix_hit_that_is_also_a_typo_expansion_keeps_the_higher_weight(): void
    {
        // 'john' → 'johnny' is a distance-2 typo expansion (weight 0.5) AND a prefix hit (1.0).
        $builder = User::search('john')->useInvertedIndex()->asYouType();
        $builder->get();

        $terms = $builder->getDebugInfo()['index_terms'];

        $this->assertSame(1.0, $terms['john']);
        $this->assertSame(1.0, $terms['johnny']);
    }

    public function test_a_repeated_last_token_is_still_the_prefix_source(): void
    {
        // processTerms() de-duplicates to ['doe', 'john']; the prefix source must still be
        // the raw last word 'doe', so 'john' is not prefix-expanded to Johnny Bravo.
        $names = User::search('doe john doe')->useInvertedIndex()->typoTolerance(0)->asYouType()
            ->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertNotContains('Johnny Bravo', $names);
    }

    public function test_a_trailing_stop_word_is_not_prefix_expanded_and_does_not_shift_the_prefix(): void
    {
        $names = User::search('john the')->useInvertedIndex()->typoTolerance(0)->asYouType()
            ->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertNotContains('Johnny Bravo', $names);
    }

    public function test_the_model_can_default_to_as_you_type(): void
    {
        app(IndexManager::class)->indexBatch(AsYouTypeUser::all());

        $this->assertContains('Johnny Bravo', AsYouTypeUser::search('joh')->useInvertedIndex()->get()->pluck('name')->all());
        $this->assertTrue(AsYouTypeUser::search('joh')->getDebugInfo()['as_you_type']);
    }

    public function test_trailing_punctuation_does_not_hide_the_prefix_source(): void
    {
        // The tokenizer splits on punctuation, so the raw-last-word split must too.
        $names = User::search('joh.')->useInvertedIndex()->asYouType()->get()->pluck('name')->all();

        $this->assertContains('Johnny Bravo', $names);
    }

    public function test_as_you_type_is_part_of_the_cache_key(): void
    {
        $this->assertCount(0, User::search('joh')->useInvertedIndex()->cache(10)->get());

        $names = User::search('joh')->useInvertedIndex()->asYouType()->cache(10)->get()->pluck('name')->all();

        $this->assertContains('Johnny Bravo', $names);
    }

    public function test_prefix_expansions_are_capped_by_config(): void
    {
        config(['fuzzy-search.bm25.prefix.max_expansions' => 1]);

        $builder = User::search('jo')->useInvertedIndex()->asYouType();
        $builder->get();

        // Exactly one prefix expansion (the most common 'jo…' term). 'jo' itself ran too, but the
        // debug copy lists only words posted under a visible column (ruling ER-87), and no row holds 'jo'.
        $this->assertCount(1, $builder->getDebugInfo()['index_terms']);
    }
}
