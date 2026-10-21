<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TypoCommonItem extends Model
{
    use Searchable;

    protected $table   = 'typo_common_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

/**
 * ER-225a. A typo expansion took a model's most common words of a similar length first, so "smith"
 * (no word of the index) expanded at distance 2 into "with", a word in 999,967 of 1M products, and
 * the ranking read grouped and sorted every one of its postings (2.0 to 2.5 s at 1M rows) for rows
 * that score about 0 and never reach the page. In an index of more documents than
 * bm25.max_postings_per_term, an expansion is no longer a word in more than half of them (its
 * Robertson–Spärck Jones idf is ≤ 0: a stop word there, no signal for a misspelling). A smaller
 * index keeps every expansion (ER-225a-rev: on 10 rows, "phnoe" must still find "phone" in 6), and
 * a word in no more than half the documents is expanded at any size ("jhon" still finds "john" in
 * more rows than the cap). The words typed, prefix completions and synonyms match at any frequency.
 *
 * "with" is in 11 of the 12 rows here; "sith" (distance 1, 2 rows) and "switch" (distance 2, 1
 * row) are the rare neighbours of "smith". A cap of 10 makes the 12 rows a large index.
 */
class TypoExpansionCommonWordTest extends TestCase
{
    /** @var array{lord: int, sith: int, switch: int} the rows the rare neighbours hold */
    private array $rare;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('typo_common_items');
        Schema::create('typo_common_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });

        DB::table('typo_common_items')->insert(['title' => 'sith lord with cape']);
        DB::table('typo_common_items')->insert(['title' => 'sith with']);
        DB::table('typo_common_items')->insert(['title' => 'switch box']);
        foreach (range(1, 9) as $i) {
            DB::table('typo_common_items')->insert(['title' => "with item {$i}"]);
        }
        $id         = fn (string $title) => (int) DB::table('typo_common_items')->where('title', $title)->value('id');
        $this->rare = ['lord' => $id('sith lord with cape'), 'sith' => $id('sith with'), 'switch' => $id('switch box')];

        app(IndexManager::class)->indexBatch(TypoCommonItem::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('typo_common_items');

        parent::tearDown();
    }

    /** @return list<int> */
    private static function ids(iterable $rows): array
    {
        return array_map(fn ($row) => (int) $row->id, is_array($rows) ? $rows : $rows->all());
    }

    private function smith(): \Ashiqfardus\LaravelFuzzySearch\SearchBuilder
    {
        return TypoCommonItem::search('smith')->useInvertedIndex()->typoTolerance(2);
    }

    /** More documents than the cap: the 12 rows are a large index. */
    private static function large(int $cap = 10): void
    {
        config(['fuzzy-search.bm25.max_postings_per_term' => $cap]);
    }

    /** @return list<string> the terms the last index query of $search ran */
    private static function terms(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder $search): array
    {
        $search->get();

        return array_map('strval', array_keys($search->getDebugInfo()['index_terms']));
    }

    public function test_in_a_large_index_a_word_in_most_documents_is_no_typo_expansion_and_the_rare_neighbours_still_are(): void
    {
        self::large();

        $this->assertEqualsCanonicalizing(array_values($this->rare), self::ids($this->smith()->get()), 'get');
        $this->assertSame(3, $this->smith()->count(), 'count');
        $this->assertSame(3, $this->smith()->paginate(2)->total(), 'paginate');
        $this->assertCount(1, $this->smith()->simplePaginate(2, 'page', 2)->items(), 'simplePaginate');
        $this->assertEqualsCanonicalizing(array_values($this->rare), self::ids($this->smith()->tokenize()->matchAll()->get()), 'matchAll');
        $this->assertSame(3, $this->smith()->orderBy('id')->count(), 'orderBy');

        $terms = self::terms($this->smith());
        $this->assertContains('sith', $terms);
        $this->assertContains('switch', $terms);
        $this->assertNotContains('with', $terms);
    }

    /** "with" added about 0 to a row (its idf is ln(1 + 1.5/11.5)): the rows the rare neighbours hold keep the order they had. */
    public function test_the_ranking_of_the_other_matches_is_unchanged(): void
    {
        self::large();
        $expected = [$this->rare['sith'], $this->rare['switch'], $this->rare['lord']];

        $this->assertSame($expected, self::ids($this->smith()->get()));
        $this->assertSame($this->rare['sith'], (int) $this->smith()->first()->id, 'first');
    }

    public function test_a_small_index_keeps_every_expansion(): void
    {
        $this->assertCount(12, $this->smith()->get(), '"with" in 11 of 12 rows, under the cap');
        $this->assertSame(12, $this->smith()->count(), 'count');
        $this->assertContains('with', self::terms($this->smith()));
    }

    /** A word in no more than half the documents of a large index, in more than the cap: still an expansion. */
    public function test_a_word_under_half_of_a_large_index_is_expanded_past_the_cap(): void
    {
        self::large(1);

        $this->assertSame(array_values($this->rare), self::ids($this->smith()->orderBy('id')->get()), '"sith" is in 2 rows');
        $this->assertContains('sith', self::terms($this->smith()));
        $this->assertNotContains('with', self::terms($this->smith()));
    }

    public function test_the_common_word_typed_still_matches(): void
    {
        self::large();

        $this->assertCount(11, TypoCommonItem::search('with')->useInvertedIndex()->typoTolerance(0)->orderBy('id')->get());
        $this->assertCount(12, TypoCommonItem::search('with smith')->useInvertedIndex()->orderBy('id')->get(), 'beside a misspelling');
    }

    public function test_prefix_and_synonym_terms_are_kept(): void
    {
        self::large();

        $this->assertCount(11, TypoCommonItem::search('wit')->useInvertedIndex()->asYouType()->orderBy('id')->get(), 'a prefix completion');
        $this->assertCount(12, $this->smith()->withSynonyms(['smith' => ['with']])->orderBy('id')->get(), 'a synonym');
    }
}
