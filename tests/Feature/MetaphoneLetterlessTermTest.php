<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LetterlessPerson extends Model
{
    use Searchable;

    protected $table   = 'letterless_people';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10], 'algorithm' => 'metaphone'];
}

/**
 * SF-1. PHP's metaphone() skips every byte that is not an ASCII letter, so "99", "Иван" and "東京"
 * all encode as '' — the code the observer writes for every value without an ASCII letter, and
 * for an empty one. `where name_metaphone = ''` then returned every such row. A term with no
 * ASCII letter now takes a contains LIKE on the column itself, as soundex does (RB-1).
 */
class MetaphoneLetterlessTermTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('letterless_people');
        Schema::create('letterless_people', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_metaphone')->nullable();
            $table->timestamps();
        });
        SearchableObserver::resetColumnCache();

        foreach (['Stephen', 'Steven', 'Müller', '99', 'Order 99', '2024', '', '123-456', 'Иван', 'Пётр', '東京', null] as $name) {
            LetterlessPerson::create(['name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('letterless_people');
        parent::tearDown();
    }

    public function test_a_term_without_an_ascii_letter_finds_only_the_rows_that_contain_it(): void
    {
        // The shadow codes the bug relied on: '' for every value without an ASCII letter.
        $this->assertSame(7, DB::table('letterless_people')->where('name_metaphone', '')->count());

        $cases = [
            '99'      => ['99', 'Order 99'],
            '2024'    => ['2024'],
            'Иван'    => ['Иван'],
            '東京'    => ['東京'],
            '!!!'     => [],
            // Controls: a term with an ASCII letter still matches by sound.
            'steffen' => ['Stephen', 'Steven'],
            'muller'  => ['Müller'],
        ];

        foreach ($cases as $term => $expected) {
            $label = json_encode($term, JSON_UNESCAPED_UNICODE);
            $make  = fn () => LetterlessPerson::search((string) $term);

            $this->assertSame($expected, $make()->get()->pluck('name')->sort()->values()->all(), "{$label} get");
            $this->assertSame(count($expected), $make()->count(), "{$label} count");
            $this->assertSame(count($expected), $make()->paginate(20)->total(), "{$label} paginate");
            $this->assertSame(count($expected), count($make()->simplePaginate(20)->items()), "{$label} simplePaginate");
            $first = $make()->first();
            $expected === [] ? $this->assertNull($first, "{$label} first") : $this->assertContains($first?->name, $expected, "{$label} first");
            $this->assertSame(
                $expected,
                LetterlessPerson::query()->whereFuzzy('name', (string) $term, 'metaphone')->pluck('name')->sort()->values()->all(),
                "{$label} whereFuzzy"
            );
        }
    }
}
