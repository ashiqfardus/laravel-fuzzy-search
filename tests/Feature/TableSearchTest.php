<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

class TableSearchTest extends TestCase
{
    /** Filament evaluates the closure inside a where group with (query, search). */
    private function apply(\Closure $closure, string $search): array
    {
        return User::query()->where(fn ($q) => $closure($q, $search))->orderBy('id')->pluck('name')->all();
    }

    public function test_the_closure_applies_a_fuzzy_match_on_the_named_columns(): void
    {
        $this->assertContains('John Doe', $this->apply(FuzzySearch::tableSearch(['name']), 'jonh'));
        $this->assertNotContains('Bob Johnson', $this->apply(FuzzySearch::tableSearch(['email']), 'zzzz'));
    }

    public function test_a_single_column_string_and_the_model_defaults_both_work(): void
    {
        $this->assertContains('John Doe', $this->apply(FuzzySearch::tableSearch('name'), 'jonh'));
        // null → the model's $searchable columns (User declares name + email)
        $this->assertContains('Bob Johnson', $this->apply(FuzzySearch::tableSearch(), 'bob@'));
    }

    public function test_algorithm_and_options_are_forwarded(): void
    {
        // 'like' is the registered exact-substring driver (src/FuzzySearch.php $registry);
        // there is no 'exact' entry, so this test uses 'like' to get exact-match semantics.
        $exact = $this->apply(FuzzySearch::tableSearch(['name'], 'like'), 'jonh');
        $fuzzy = $this->apply(FuzzySearch::tableSearch(['name'], 'fuzzy', ['max_distance' => 2]), 'jonh');

        $this->assertNotContains('John Doe', $exact);
        $this->assertContains('John Doe', $fuzzy);
    }

    public function test_the_closure_returns_the_builder_and_composes_with_other_constraints(): void
    {
        $closure = FuzzySearch::tableSearch(['name']);
        $names   = User::query()->where('email', 'like', '%example.com')->where(fn ($q) => $closure($q, 'jonh'))->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $closure(User::query(), 'x'));
    }
}
