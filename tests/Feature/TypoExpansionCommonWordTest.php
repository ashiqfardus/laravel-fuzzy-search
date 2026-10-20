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
 * that score about 0 and never reach the page. An expansion is no longer a word in more than half
 * the model's documents (its Robertson–Spärck Jones idf is ≤ 0: no signal for a misspelling), nor
 * one in more than bm25.max_postings_per_term of them (its read alone would reach the cap). The words
 * typed, prefix completions and synonyms keep matching at any frequency.
 *
 * "with" is in 11 of the 12 rows here; "sith" (distance 1, 2 rows) and "switch" (distance 2, 1
 * row) are the rare neighbours of "smith".
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

    public function test_a_word_in_most_documents_is_no_typo_expansion_and_the_rare_neighbours_still_are(): void
    {
        $this->assertEqualsCanonicalizing(array_values($this->rare), self::ids($this->smith()->get()), 'get');
        $this->assertSame(3, $this->smith()->count(), 'count');
        $this->assertSame(3, $this->smith()->paginate(2)->total(), 'paginate');
        $this->assertCount(1, $this->smith()->simplePaginate(2, 'page', 2)->items(), 'simplePaginate');
        $this->assertEqualsCanonicalizing(array_values($this->rare), self::ids($this->smith()->tokenize()->matchAll()->get()), 'matchAll');

        $search = $this->smith();
        $search->get();
        $terms = array_keys($search->getDebugInfo()['index_terms']);
        $this->assertContains('sith', $terms);
        $this->assertContains('switch', $terms);
        $this->assertNotContains('with', $terms);
    }

    /** "with" added about 0 to a row (its idf is ln(1 + 1.5/11.5)): the rows the rare neighbours hold keep the order they had. */
    public function test_the_ranking_of_the_other_matches_is_unchanged(): void
    {
        $expected = [$this->rare['sith'], $this->rare['switch'], $this->rare['lord']];

        $this->assertSame($expected, self::ids($this->smith()->get()));
        $this->assertSame($this->rare['sith'], (int) $this->smith()->first()->id, 'first');
    }

    public function test_the_common_word_typed_still_matches(): void
    {
        $this->assertCount(11, TypoCommonItem::search('with')->useInvertedIndex()->typoTolerance(0)->get());
        $this->assertCount(12, TypoCommonItem::search('with smith')->useInvertedIndex()->get(), 'beside a misspelling');
    }

    public function test_prefix_and_synonym_terms_are_kept(): void
    {
        $this->assertCount(11, TypoCommonItem::search('wit')->useInvertedIndex()->asYouType()->get(), 'a prefix completion');
        $this->assertCount(12, $this->smith()->withSynonyms(['smith' => ['with']])->get(), 'a synonym');
    }

    /** A word under half the documents but over the cap is no expansion either: its read alone would reach the cap. */
    public function test_a_word_over_the_posting_cap_is_no_typo_expansion(): void
    {
        config(['fuzzy-search.bm25.max_postings_per_term' => 1]);

        $this->assertSame([$this->rare['switch']], self::ids($this->smith()->get()), '"sith" is in 2 rows');
    }
}
