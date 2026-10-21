<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TypoIdfArticle extends Model
{
    use Searchable;

    protected $table   = 'typo_idf_articles';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10, 'body' => 1]];
}

/**
 * WB-4 (ruling ER-217): BM25 scored a typo expansion with its own idf. A word's misspellings are
 * rare, so their idf is far above the word's own: "laravel" correctly typed, in 200 of 202 rows,
 * put the two misspelled rows first, at more than twice the best exact score. An expansion's
 * document frequency is now at least its source word's, when the model's index holds that word
 * (a blended frequency, as Lucene's FuzzyQuery scores): its idf is capped at the word's, and with
 * the damping it scores below the exact hits. A word the index lacks (the user typed the
 * misspelling) leaves its expansions their own idf, as before; prefix and synonym terms too.
 */
class TypoExpansionIdfTest extends TestCase
{
    /** @var array{laravell: int, larave: int} the misspelled rows' keys */
    private array $misspelled;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('typo_idf_articles');
        Schema::create('typo_idf_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
        });

        foreach (array_chunk(range(1, 200), 100) as $chunk) {
            DB::table('typo_idf_articles')->insert(array_map(fn ($i) => ['title' => "Laravel tip {$i}", 'body' => 'some body text'], $chunk));
        }
        DB::table('typo_idf_articles')->insert(['title' => 'Laravell tip, one insertion', 'body' => 'some body text']);
        DB::table('typo_idf_articles')->insert(['title' => 'Another larave post, one deletion', 'body' => 'some body text']);
        $this->misspelled = [
            'laravell' => (int) DB::table('typo_idf_articles')->where('title', 'like', 'Laravell%')->value('id'),
            'larave'   => (int) DB::table('typo_idf_articles')->where('title', 'like', 'Another%')->value('id'),
        ];

        app(IndexManager::class)->indexBatch(TypoIdfArticle::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('typo_idf_articles');

        parent::tearDown();
    }

    /** @return list<int> */
    private static function ids(iterable $rows): array
    {
        return array_map(fn ($row) => (int) $row->id, is_array($rows) ? $rows : $rows->all());
    }

    public function test_the_exact_hits_of_a_common_word_outrank_its_misspellings(): void
    {
        foreach ([1, 2] as $tolerance) {
            $all = TypoIdfArticle::search('laravel')->useInvertedIndex()->typoTolerance($tolerance)->take(202)->get();

            $this->assertCount(202, $all, "typo {$tolerance}: every match");
            $this->assertEqualsCanonicalizing(array_values($this->misspelled), array_slice(self::ids($all), 200), "typo {$tolerance}: the misspelled rows come last");
            $this->assertLessThan($all[0]->_raw_score, $all[200]->_raw_score, "typo {$tolerance}: below every exact hit");
        }

        // The default tolerance, through paginate().
        $page = TypoIdfArticle::search('laravel')->useInvertedIndex()->paginate(5);
        $this->assertSame(202, $page->total());
        $this->assertSame([], array_intersect(array_values($this->misspelled), self::ids($page->items())), 'page 1 holds exact hits only');
    }

    public function test_a_misspelling_the_index_lacks_keeps_its_expansions_own_idf(): void
    {
        // "larvel" is no word of the index: its expansions keep their own idf, so the rare ones lead.
        $rows = TypoIdfArticle::search('larvel')->useInvertedIndex()->typoTolerance(2)->take(3)->get();
        $this->assertEqualsCanonicalizing(array_values($this->misspelled), array_slice(self::ids($rows), 0, 2));

        // A misspelling typed as it is indexed matches exactly first.
        $this->assertSame($this->misspelled['laravell'], (int) TypoIdfArticle::search('laravell')->useInvertedIndex()->typoTolerance(1)->first()->id);
    }

    public function test_prefix_terms_keep_their_own_idf(): void
    {
        $rows = TypoIdfArticle::search('larav')->useInvertedIndex()->asYouType()->take(3)->get();

        $this->assertEqualsCanonicalizing(array_values($this->misspelled), array_slice(self::ids($rows), 0, 2));
    }
}
