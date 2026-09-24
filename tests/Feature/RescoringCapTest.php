<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LongDoc extends Model
{
    protected $table   = 'long_docs';
    protected $guarded = [];
    public $timestamps = false;
}

/**
 * PHP rescoring ran similar_text() and levenshtein() — O(n·m), similar_text() worse — on the
 * whole term against the whole column value, and the extended path on every leaf term joined
 * into one string: 300 rows of 20KB took seconds (a CPU DoS). Both strings are now cut to 255
 * characters first, and an extended query scores each leaf term on its own.
 */
class RescoringCapTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('long_docs');
        parent::tearDown();
    }

    /** A builder whose protected scorers the test can call. */
    private function scorer(): SearchBuilder
    {
        return new class (User::query(), app(FuzzySearch::class)) extends SearchBuilder {
            public function score(string $value, string $term): float
            {
                return $this->scoreValue($value, mb_strtolower($term, 'UTF-8'), 1);
            }

            /** @param string[] $terms */
            public function rawScore(Model $row, array $terms): float
            {
                $scored = $this->searchIn(['name'])->calculateRelevanceScores(collect([$row->fresh()]), $terms)->first();

                return (float) ($scored->_raw_score ?? $scored->_score); // a 0 score is never normalised
            }
        };
    }

    public function test_the_scorer_compares_only_the_first_255_characters(): void
    {
        $scorer = $this->scorer();
        $long   = str_repeat('lorem ipsum dolor sit amet ', 800); // ~21 KB
        $term   = 'lorxm ipsxm'; // shares characters with the value, but is not contained in it

        $this->assertGreaterThan(0, $scorer->score(mb_substr($long, 0, 255), $term));
        $this->assertSame($scorer->score(mb_substr($long, 0, 255), $term), $scorer->score($long, $term));

        // Characters, never bytes: 300 two-byte letters are cut to 255 letters.
        $this->assertSame($scorer->score(str_repeat('é', 255), 'éa'), $scorer->score(str_repeat('é', 300), 'éa'));

        // The term is cut the same way.
        $longTerm = str_repeat('ab', 150);
        $this->assertSame($scorer->score('abcabc', mb_substr($longTerm, 0, 255)), $scorer->score('abcabc', $longTerm));
    }

    /** _raw_score at f8f2044, before the cap: values of 255 characters or fewer score exactly as they did. */
    public function test_short_values_score_exactly_as_before(): void
    {
        $scores = fn ($builder, string $key = 'name') => $builder->get()
            ->mapWithKeys(fn ($row) => [$row->{$key} => $row->_raw_score])->sortKeys()->all();

        $this->assertSame(['Bob Johnson' => 226.32, 'John Doe' => 325.0, 'Johnny Bravo' => 255.68, 'Jon Snow' => 328.95], $scores(User::search('jonh')));
        $this->assertSame(['Bob Johnson' => 292.98, 'John Doe' => 325.0, 'Johnny Bravo' => 255.68, 'Jon Snow' => 328.95], $scores(User::search('jhon')));
        $this->assertSame(['Alice Smith' => 272.73], $scores(User::search('smiht')));
        $this->assertSame(['Bob Johnson' => 275.0, 'John Doe' => 402.93, 'Johnny Bravo' => 402.81], $scores(User::search('johny')));
        $this->assertSame(
            ['Alice Smith' => 411.11, 'Bob Johnson' => 300.0, 'Charlie Brown' => 350.0, 'Jane Doe' => 433.33, 'John Doe' => 366.67, 'Johnny Bravo' => 352.63, 'Jon Snow' => 300.0],
            $scores(User::search('example'))
        );
        $this->assertSame(['MacBook Pro' => 229.17, 'iPhone 15 Pro' => 200.4], $scores(Product::search('laptp'), 'title'));

        // A single-leaf extended query keeps its ranking: the leaf is scored as the term was.
        $this->assertSame(['Bob Johnson' => 226.32, 'John Doe' => 325.0, 'Johnny Bravo' => 255.68, 'Jon Snow' => 328.95], $scores(User::search('')->extended('~jonh')));
        $this->assertSame(['Bob Johnson' => 626.32, 'John Doe' => 1200.0, 'Johnny Bravo' => 1200.0], $scores(User::search('')->extended("'john")));
        $this->assertSame(['Alice Smith' => 272.73], $scores(User::search('')->extended('name:~smiht')));
    }

    /** Each leaf is scored on its own and the leaf scores add up (a row matching more of the query ranks higher). */
    public function test_extended_scores_each_leaf_and_adds_them(): void
    {
        // Joined into "alice bob", neither row contained the needle and both fell to the fuzzy floor.
        $raw = User::search('')->extended('alice | bob')->get()->pluck('_raw_score', 'name');
        $this->assertGreaterThanOrEqual(800, $raw['Alice Smith'], 'prefix tier for "alice" on name (weight 10)');
        $this->assertGreaterThanOrEqual(800, $raw['Bob Johnson'], 'prefix tier for "bob" on name (weight 10)');

        $scorer = $this->scorer();
        $alice  = User::query()->where('name', 'Alice Smith')->first();

        $this->assertSame(80.0, $scorer->rawScore($alice, ['alice']));  // prefix tier
        $this->assertSame(60.0, $scorer->rawScore($alice, ['smith']));  // contains tier
        $this->assertSame(140.0, $scorer->rawScore($alice, ['alice', 'smith']));
    }

    public function test_300_rows_of_20kb_rescore_in_bounded_time(): void
    {
        Schema::dropIfExists('long_docs');
        Schema::create('long_docs', function ($table) {
            $table->id();
            $table->string('title');
            $table->text('body');
        });

        $term = substr(str_repeat('quackbrawnfaxjumpt', 8), 0, 127); // no space: one extended leaf
        $body = substr(str_repeat('the quick brown fox jumps over the lazy dog ', 460), 0, 20000);

        foreach (array_chunk(range(1, 300), 20) as $chunk) {
            DB::table('long_docs')->insert(array_map(fn (int $i) => ['title' => $term . ' ' . $i, 'body' => $body], $chunk));
        }

        $search = fn () => (new SearchBuilder(LongDoc::query(), app(FuzzySearch::class)))->searchIn(['title', 'body'])->take(10);

        $started = microtime(true);
        $like    = $search()->search($term)->using('like')->get();
        $likeSeconds = microtime(true) - $started;

        $started  = microtime(true);
        $extended = $search()->extended($term . ' | zzzz')->get();
        $extendedSeconds = microtime(true) - $started;

        $this->assertCount(10, $like);
        $this->assertCount(10, $extended);
        $this->assertLessThan(3.0, $likeSeconds, 'LIKE path rescoring');
        $this->assertLessThan(3.0, $extendedSeconds, 'extended path rescoring');
    }
    /**
     * Ruling ER-55: per-leaf similarity only while the leaves' total length stays within 255
     * characters (the first leaf always gets it); the rest score by tier alone.
     */
    public function test_leaves_past_the_similarity_budget_score_by_tier_only(): void
    {
        $scorer = $this->scorer();
        $alice  = User::query()->where('name', 'Alice Smith')->first();

        // 200 + 5 characters: "smiht" is within the budget and earns its similarity floor.
        $this->assertGreaterThan($scorer->rawScore($alice, [str_repeat('q', 200)]), $scorer->rawScore($alice, [str_repeat('q', 200), 'smiht']));

        // 251 + 5: past it, "smiht" is scored by tier only — it is not contained, so it adds nothing.
        $this->assertSame($scorer->rawScore($alice, [str_repeat('q', 251)]), $scorer->rawScore($alice, [str_repeat('q', 251), 'smiht']));

        // A tier still counts past the budget.
        $this->assertSame($scorer->rawScore($alice, [str_repeat('q', 251)]) + 60.0, $scorer->rawScore($alice, [str_repeat('q', 251), 'smith']));
    }

    /**
     * Ruling ER-55: 16 long leaves against two long columns at the default max_candidates (1,000
     * rows). 2KB, not 20KB: past the 255-character cut every value costs the same comparison, and
     * 1,000 x 2 x 20KB rows would need more than PHP's default 128MB on a buffered connection.
     */
    public function test_sixteen_long_leaves_rescore_in_bounded_time(): void
    {
        Schema::dropIfExists('long_docs');
        Schema::create('long_docs', function ($table) {
            $table->id();
            $table->text('title');
            $table->text('body');
        });

        $body = substr(str_repeat('the quick brown fox jumps over the lazy dog ', 50), 0, 2000);
        foreach (array_chunk(range(1, 1000), 20) as $chunk) {
            DB::table('long_docs')->insert(array_map(fn () => ['title' => $body, 'body' => $body], $chunk));
        }

        $leaves = array_map(fn (int $i) => substr(str_repeat("brawnfaxjumptlazi{$i}", 8), 0, 120), range(1, 15));
        $query  = 'quick | ' . implode(' | ', $leaves);

        $started = microtime(true);
        $rows    = (new SearchBuilder(LongDoc::query(), app(FuzzySearch::class)))->searchIn(['title', 'body'])->extended($query)->take(10)->get();
        $seconds = microtime(true) - $started;

        $this->assertCount(10, $rows);
        $this->assertLessThan(2.0, $seconds, '16-leaf extended rescoring over 1,000 rows x 2 x 2KB (about 0.4 s; 3 s before ER-55)');
    }
}
