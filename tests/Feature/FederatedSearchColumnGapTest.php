<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

// Load shared models (User: Searchable, columns ['name' => 10, 'email' => 5] on "users").
require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;

/** Plain "users"-backed model with no Searchable trait, for the non-Searchable side of L10. */
class ColumnGapPlainUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
}

/**
 * L10: FederatedSearch::searchIn() naming columns a model's table lacks. A Searchable model
 * used to fall back to its own configured columns instead of contributing nothing, unlike a
 * plain model, which already contributed nothing. Both must now contribute nothing, and a
 * Searchable model that has some of the named columns must search only those.
 *
 * Every seeded user's email ends "@example.com" (see TestCase::setUpDatabase()), so a search
 * for "example" only matches through the (wrongly reinstated) default `email` column —
 * proving whether that column was actually searched.
 */
class FederatedSearchColumnGapTest extends TestCase
{
    public function test_searchable_model_with_none_of_the_named_columns_contributes_nothing(): void
    {
        $results = FederatedSearch::across([User::class])->search('example')->searchIn(['title'])->get();
        $this->assertCount(0, $results, "'title' is not a users column: User must contribute nothing, not fall back to name/email");

        $counts = FederatedSearch::across([User::class])->search('example')->searchIn(['title'])->getCounts();
        $this->assertSame([], $counts, 'a model with no searchable column here is left out of getCounts() entirely');
    }

    public function test_plain_model_with_none_of_the_named_columns_contributes_nothing(): void
    {
        $results = FederatedSearch::across([ColumnGapPlainUser::class])->search('example')->searchIn(['title'])->get();
        $this->assertCount(0, $results);
    }

    public function test_searchable_model_with_some_of_the_named_columns_searches_only_those(): void
    {
        // 'title' does not exist on users; only 'name' may be searched. If 'email' leaked
        // back in (the bug), every seeded row would match "example".
        $onlyExisting = FederatedSearch::across([User::class])->search('example')->searchIn(['name', 'title'])->get();
        $this->assertCount(0, $onlyExisting, 'name holds no "example"; email must not be searched because it was not named');

        $matchesByName = FederatedSearch::across([User::class])->search('john')->searchIn(['name', 'title'])->get();
        $this->assertGreaterThan(0, $matchesByName->count(), 'name is searched and "john" matches it');
    }
}
