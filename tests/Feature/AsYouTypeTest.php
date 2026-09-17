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
        // 'doe' must match exactly; 'joh' is the prefix → John Doe, not Jane Doe alone.
        $names = User::search('doe joh')->useInvertedIndex()->asYouType()->get()->pluck('name')->all();

        $this->assertContains('John Doe', $names);
    }

    public function test_the_model_can_default_to_as_you_type(): void
    {
        app(IndexManager::class)->indexBatch(AsYouTypeUser::all());

        $this->assertContains('Johnny Bravo', AsYouTypeUser::search('joh')->useInvertedIndex()->get()->pluck('name')->all());
        $this->assertTrue(AsYouTypeUser::search('joh')->getDebugInfo()['as_you_type']);
    }

    public function test_prefix_expansions_are_capped_by_config(): void
    {
        config(['fuzzy-search.bm25.prefix.max_expansions' => 1]);

        $builder = User::search('jo')->useInvertedIndex()->asYouType();
        $builder->get();

        // 'jo' itself plus exactly one prefix expansion (the most common 'jo…' term).
        $this->assertCount(2, $builder->getDebugInfo()['index_terms']);
    }
}
