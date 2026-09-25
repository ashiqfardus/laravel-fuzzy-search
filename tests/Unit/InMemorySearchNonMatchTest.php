<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;

/** Plain "users"-backed model, no Searchable trait: InMemorySearch works on any collection. */
class InMemoryNonMatchUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
}

/**
 * M6 (ruling ER-98): FuzzySearch::on() must never decorate an item that did not match, and
 * every item — matched or not — must lose its internal _raw_score_tmp. A non-matching
 * Eloquent model or stdClass is the same object the caller already holds elsewhere, so
 * decorating it corrupts that object too (and, being a real attribute, breaks Model::save()).
 */
class InMemorySearchNonMatchTest extends TestCase
{
    public function test_non_matching_model_is_unchanged_and_matching_model_carries_no_raw_score_tmp(): void
    {
        $users = InMemoryNonMatchUser::all();
        $aliceBefore = $users->firstWhere('name', 'Alice Smith')->getAttributes();

        $results = FuzzySearch::on($users)->search('john')->searchIn(['name'])->get();
        $this->assertGreaterThan(0, $results->count(), 'sanity: the seeded data has rows matching "john"');

        $alice = $users->firstWhere('name', 'Alice Smith');
        $this->assertSame(
            $aliceBefore,
            $alice->getAttributes(),
            'a model that did not match must carry no _score, _raw_score or _raw_score_tmp'
        );

        foreach ($results as $row) {
            $this->assertArrayNotHasKey('_raw_score_tmp', $row->getAttributes());
        }
    }

    public function test_a_non_matching_models_sibling_can_still_be_saved(): void
    {
        $users = InMemoryNonMatchUser::all();
        FuzzySearch::on($users)->search('john')->searchIn(['name'])->get();

        $alice = $users->firstWhere('name', 'Alice Smith');
        $alice->email = 'alice.updated@example.test';
        $alice->save();

        $this->assertSame(
            'alice.updated@example.test',
            InMemoryNonMatchUser::find($alice->id)->email
        );
    }

    public function test_non_matching_stdclass_is_unchanged_and_matching_stdclass_carries_no_raw_score_tmp(): void
    {
        $alice = (object) ['name' => 'Alice'];
        $john  = (object) ['name' => 'John Doe'];

        $results = FuzzySearch::on([$alice, $john])->search('john')->searchIn(['name'])->get();

        $this->assertSame(['name' => 'Alice'], get_object_vars($alice), 'the non-matching stdClass must be untouched');
        $this->assertCount(1, $results);
        $this->assertArrayNotHasKey('_raw_score_tmp', get_object_vars($results->first()));
    }

    public function test_matching_array_item_carries_no_raw_score_tmp(): void
    {
        $items = [['name' => 'Alice'], ['name' => 'John Doe']];

        $results = FuzzySearch::on($items)->search('john')->searchIn(['name'])->get();

        $this->assertCount(1, $results);
        $this->assertArrayNotHasKey('_raw_score_tmp', $results->first());
    }
}
