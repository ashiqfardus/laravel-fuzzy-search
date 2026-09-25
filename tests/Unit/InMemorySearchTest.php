<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;

class InMemorySearchTest extends TestCase
{
    public function test_search_on_array_of_arrays(): void
    {
        $items = [
            ['name' => 'John Doe',     'email' => 'john@x.com'],
            ['name' => 'Jane Smith',   'email' => 'jane@x.com'],
            ['name' => 'Johnny Bravo', 'email' => 'johnny@x.com'],
        ];

        $results = FuzzySearch::on($items)->search('john')->searchIn(['name'])->get();

        $this->assertCount(2, $results);
        $names = array_column($results->all(), 'name');
        $this->assertContains('John Doe', $names);
        $this->assertContains('Johnny Bravo', $names);
    }

    public function test_max_items_ceiling_throws(): void
    {
        config(['fuzzy-search.in_memory.max_items' => 5]);
        $items = array_fill(0, 10, ['name' => 'x']);

        $this->expectException(\InvalidArgumentException::class);
        FuzzySearch::on($items);
    }

    public function test_returns_collection(): void
    {
        $results = FuzzySearch::on([['name' => 'John']])->search('john')->searchIn(['name'])->get();
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    public function test_score_normalization_applied(): void
    {
        $items = [
            ['name' => 'John'],
            ['name' => 'John Doe'],
            ['name' => 'Johnny'],
        ];
        $results = FuzzySearch::on($items)->search('john')->searchIn(['name'])->get();
        $this->assertGreaterThan(0, $results->count());
        foreach ($results as $row) {
            $this->assertGreaterThanOrEqual(0.0, $row['_score']);
            $this->assertLessThanOrEqual(1.0, $row['_score']);
        }
    }

    public function test_take_and_skip(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['name' => 'john' . $i];
        }
        $page1 = FuzzySearch::on($items)->search('john')->searchIn(['name'])->take(3)->get();
        $this->assertCount(3, $page1);

        $page2 = FuzzySearch::on($items)->search('john')->searchIn(['name'])->skip(3)->take(3)->get();
        $this->assertCount(3, $page2);
    }

    // -------------------------------------------------------------------------
    // Case folding covers every script, as the SQL path's PHP scoring does
    // -------------------------------------------------------------------------

    /** The row whose $column is $value, from an in-memory search for $term. */
    private function rowFor(string $term, string $value, array $values): ?array
    {
        return FuzzySearch::on(array_map(fn ($v) => ['name' => $v], $values))
            ->search($term)->searchIn(['name'])->get()
            ->firstWhere('name', $value);
    }

    public function test_upper_case_accented_latin_matches_its_lower_case_form_exactly(): void
    {
        $row = $this->rowFor('ÉCOLE', 'école', ['école', 'lycée']);

        $this->assertNotNull($row);
        $this->assertSame(100, $row['_raw_score'], 'ÉCOLE must be an exact match for école, not a similar_text near-miss');

        $this->assertSame(100, $this->rowFor('école', 'ÉCOLE', ['ÉCOLE', 'LYCÉE'])['_raw_score'] ?? null);
    }

    public function test_cyrillic_and_greek_case_pairs_match(): void
    {
        foreach ([['МОСКВА', 'москва'], ['москва', 'МОСКВА'], ['ΑΘΗΝΑ', 'αθηνα'], ['αθηνα', 'ΑΘΗΝΑ']] as [$term, $value]) {
            $this->assertSame(100, $this->rowFor($term, $value, [$value, 'other'])['_raw_score'] ?? null, "{$term} vs {$value}: exact");
        }

        $prefix = $this->rowFor('МОСК', 'москва', ['москва']);
        $this->assertSame(60, $prefix['_raw_score'] ?? null, 'a Cyrillic prefix in the other case');

        $contains = $this->rowFor('ΘΗΝ', 'αθηνα', ['αθηνα']);
        $this->assertSame(30, $contains['_raw_score'] ?? null, 'a Greek substring in the other case');
    }

    // -------------------------------------------------------------------------
    // query.max_term_length
    // -------------------------------------------------------------------------

    public function test_a_term_longer_than_max_term_length_is_searched_on_its_first_max_term_length_characters(): void
    {
        config(['fuzzy-search.query.max_term_length' => 128]);

        $events = [];
        \Illuminate\Support\Facades\Event::listen(\Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted::class, function ($event) use (&$events) {
            $events[] = $event;
        });

        // 5,000 characters: the first 128 are a prefix of the 200-character value.
        $results = FuzzySearch::on([['name' => str_repeat('é', 200)], ['name' => 'other']])
            ->search(str_repeat('é', 5000))->searchIn(['name'])->get();

        $this->assertCount(1, $results);
        $this->assertSame(60, $results->first()['_raw_score'], 'the capped term is a prefix of the value');
        $this->assertCount(1, $events);
        $this->assertSame(128, mb_strlen($events[0]->searchTerm), 'the event reports the term that was searched');
    }

    // -------------------------------------------------------------------------
    // No searchable column: matches nothing (owner decision Q12)
    // -------------------------------------------------------------------------

    public function test_a_search_without_search_in_columns_matches_nothing(): void
    {
        $items = [['name' => 'John Doe'], ['name' => 'Jane']];

        $events = [];
        \Illuminate\Support\Facades\Event::listen(\Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted::class, function ($event) use (&$events) {
            $events[] = $event;
        });

        $this->assertCount(0, FuzzySearch::on($items)->search('john')->get());
        $this->assertCount(0, FuzzySearch::on($items)->search('john')->searchIn([])->get());
        $this->assertSame([], $events, 'nothing was searched, so nothing is reported');

        // '' is not a search: it still lists the items, with or without columns.
        $this->assertCount(2, FuzzySearch::on($items)->search('')->get());
        $this->assertCount(2, FuzzySearch::on($items)->search('')->searchIn(['name'])->get());
    }

    // -------------------------------------------------------------------------
    // similar_text() compares at most 255 characters of each side (as SearchBuilder::similarity())
    // -------------------------------------------------------------------------

    public function test_similar_text_compares_only_the_first_255_characters_of_a_value(): void
    {
        // 127 shared characters: against the first 255 of the value that is a 66% match, against
        // the whole 20,127-character value it was about 1%, and every such value cost O(term × 20KB).
        $term  = 'q' . str_repeat('a', 127);
        $value = str_repeat('a', 127) . str_repeat('z', 20000);

        $row = FuzzySearch::on([['name' => $value]])->search($term)->searchIn(['name'])->get()->first();
        $cut = FuzzySearch::on([['name' => mb_substr($value, 0, 255)]])->search($term)->searchIn(['name'])->get()->first();

        $this->assertNotNull($cut);
        $this->assertSame(66, $cut['_raw_score']);
        $this->assertSame($cut['_raw_score'], $row['_raw_score'] ?? null, 'a long value is scored on its first 255 characters');

        // Characters, never bytes: 300 two-byte letters compare as 255 letters.
        $score = fn (int $letters) => FuzzySearch::on([['name' => str_repeat('é', $letters)]])
            ->search('b' . str_repeat('é', 127))->searchIn(['name'])->get()->first()['_raw_score'] ?? null;

        $this->assertSame(66, $score(255));
        $this->assertSame(66, $score(300));
    }

    /** _raw_score before the cap: values of 255 characters or fewer score exactly as they did. */
    public function test_values_within_255_characters_score_exactly_as_before(): void
    {
        $items = [
            ['name' => 'John Doe'], ['name' => 'Jonathan'], ['name' => 'Johnny Bravo'], ['name' => 'Jon Snow'],
            ['name' => str_repeat('a', 127) . str_repeat('z', 73)], // 200 characters
            ['name' => 'Joh' . str_repeat('n', 252)],   // exactly 255
        ];

        $scores = fn (string $term) => FuzzySearch::on($items)->search($term)->searchIn(['name'])->get()
            ->mapWithKeys(fn ($row) => [mb_substr($row['name'], 0, 12) => $row['_raw_score']])->all();

        $this->assertSame(['Jonathan' => 66], $scores('jonh'));
        $this->assertSame([str_repeat('a', 12) => 77], $scores('q' . str_repeat('a', 127)));
        $this->assertSame(['Joh' . str_repeat('n', 9) => 64], $scores('Joh' . str_repeat('n', 120) . 'x'));
    }

    public function test_300_items_of_20kb_score_in_bounded_time(): void
    {
        $value = substr(str_repeat('the quick brown fox jumps over the lazy dog ', 460), 0, 20000);
        $items = array_map(fn (int $i) => ['name' => $value . $i], range(1, 300));
        $term  = substr(str_repeat('quackbrawnfaxjumpt', 8), 0, 127);

        $started = microtime(true);
        FuzzySearch::on($items)->search($term)->searchIn(['name'])->get();

        $this->assertLessThan(3.0, microtime(true) - $started);
    }
}

