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

/** An app DTO: declared properties only, so a property written onto it is a dynamic one. */
class InMemoryContactDto
{
    public function __construct(public string $name) {}
}

/** An object that refuses a property it does not declare, as a `readonly class` does (PHP 8.2+). */
class InMemoryLockedDto
{
    public function __construct(public string $name) {}

    public function __set(string $key, mixed $value): void
    {
        throw new \Error("Cannot create dynamic property " . self::class . "::\${$key}");
    }
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

    /**
     * Round 10 deep review, RD-1: the working score was written onto every matched object as
     * `_raw_score_tmp` before withRelevance() was read, a dynamic property on an object with
     * declared ones: a deprecation from src/ per matched item (PHP 8.2+), and an Error on an object
     * that takes none, so the search could not run. It is kept beside the items, never on them.
     */
    public function test_objects_with_declared_properties_are_searched_without_a_property_written_onto_them(): void
    {
        $items = [new InMemoryContactDto('Jane Roe'), new InMemoryContactDto('John Doe'), new InMemoryContactDto('Johnny')];

        $results = (new FuzzySearch(config('fuzzy-search')))->on($items)->search('john')->searchIn(['name'])->withRelevance(false)->get();

        $this->assertSame(['John Doe', 'Johnny'], $results->map(fn ($dto) => $dto->name)->all());
        foreach ($items as $dto) {
            $this->assertSame(['name'], array_keys(get_object_vars($dto)));
        }
    }

    public function test_an_object_that_takes_no_dynamic_property_is_searched_with_relevance_off(): void
    {
        $items = [new InMemoryLockedDto('Big John'), new InMemoryLockedDto('Jane Roe'), new InMemoryLockedDto('John')];

        $results = (new FuzzySearch(config('fuzzy-search')))->on($items)->search('john')->searchIn(['name'])->withRelevance(false)->get();

        $this->assertSame(['John', 'Big John'], $results->map(fn ($dto) => $dto->name)->all(), 'ranked: the exact match first');
    }
}
