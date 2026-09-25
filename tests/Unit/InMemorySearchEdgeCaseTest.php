<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/** Plain "users"-backed model: its default `created_at`/`updated_at` casts are Carbon (Stringable). */
class InMemoryDateUser extends Model
{
    protected $table = 'users';
    protected $guarded = [];
}

/** L12 (ruling ER-99): InMemory edge cases — a non-scalar column value, and an oversized LazyCollection. */
class InMemorySearchEdgeCaseTest extends TestCase
{
    public function test_an_array_or_json_cast_column_is_skipped_without_a_warning_or_a_match(): void
    {
        $items = [
            ['tags' => ['php', 'laravel'], 'name' => 'PHP Framework'],
            ['tags' => ['ruby'],           'name' => 'Ruby Gem'],
        ];

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings) {
            $warnings[] = $errstr;
            return true;
        }, E_WARNING);

        try {
            $results = FuzzySearch::on($items)->search('php')->searchIn(['tags', 'name'])->get();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'reading a non-scalar ("Array to string conversion") column must not warn');
        $this->assertCount(1, $results, 'only the row that matches through the scalar "name" column comes back');
        $this->assertSame('PHP Framework', $results->first()['name']);
    }

    public function test_a_lazy_collection_over_max_items_throws_without_being_fully_materialised(): void
    {
        config(['fuzzy-search.in_memory.max_items' => 5]);

        // Large enough to prove the source is not fully drained (the old code exhausts memory
        // pulling all of it — see the RED file), small enough that a bug here fails fast.
        $yielded = 0;
        $source = LazyCollection::make(function () use (&$yielded) {
            for ($i = 0; $i < 2000; $i++) {
                $yielded++;
                yield ['name' => "item{$i}"];
            }
        });

        try {
            FuzzySearch::on($source);
            $this->fail('expected InvalidArgumentException for an oversized LazyCollection');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        $this->assertLessThanOrEqual(6, $yielded, 'the constructor must stop pulling at max_items + 1, not drain the whole source');
        $this->assertGreaterThan(0, $yielded);
    }

    public function test_a_lazy_collection_within_max_items_still_searches_normally(): void
    {
        config(['fuzzy-search.in_memory.max_items' => 10]);

        $source = LazyCollection::make(function () {
            yield ['name' => 'John Doe'];
            yield ['name' => 'Jane Smith'];
        });

        $results = FuzzySearch::on($source)->search('john')->searchIn(['name'])->get();

        $this->assertCount(1, $results);
        $this->assertSame('John Doe', $results->first()['name']);
    }

    /**
     * Fix round 1 (task review HIGH): a date/datetime-cast column — Carbon implements
     * __toString()/\Stringable and must not be treated like an array/JSON-cast value. Every
     * seeded user shares the same created_at, so searching its date substring must match all of them.
     */
    public function test_a_datetime_cast_column_still_matches_its_formatted_date_substring(): void
    {
        $users = InMemoryDateUser::all();
        $dateSubstring = $users->first()->created_at->format('Y-m-d');

        $results = FuzzySearch::on($users)->search($dateSubstring)->searchIn(['created_at'])->get();

        $this->assertCount($users->count(), $results, 'a Carbon-cast created_at must still be searched via (string), not skipped');
    }

    /** A genuinely non-Stringable object (no __toString()) is skipped like an array — no fatal, no match. */
    public function test_a_non_stringable_object_column_is_skipped_without_throwing(): void
    {
        $notStringable = new class {};

        $items = [
            ['thing' => $notStringable, 'name' => 'Widget'],
            ['thing' => 'plain',        'name' => 'Other'],
        ];

        $results = FuzzySearch::on($items)->search('widget')->searchIn(['thing', 'name'])->get();

        $this->assertCount(1, $results, 'the non-Stringable object column is skipped; the match comes through "name"');
        $this->assertSame('Widget', $results->first()['name']);
    }
}
