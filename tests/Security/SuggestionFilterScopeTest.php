<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchCollection;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FilterScopedNote extends Model
{
    use Searchable;

    protected $table   = 'filter_scoped_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['body' => 1]];
}

/**
 * filter() and filterIn() narrow the rows a search returns, so they narrow what suggest() and
 * didYouMean() offer: tenant A's suggestions never come from tenant B's rows. The limits are cut
 * to a small maximum, as a page size is, because a limit read from the request multiplies queries.
 */
class SuggestionFilterScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('filter_scoped_notes');
        Schema::create('filter_scoped_notes', function ($table) {
            $table->id();
            $table->unsignedInteger('tenant_id');
            $table->string('body');
        });
        FilterScopedNote::create(['tenant_id' => 1, 'body' => 'jonah headphones']);
        FilterScopedNote::create(['tenant_id' => 2, 'body' => 'jonas speaker']);
        $this->index();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('filter_scoped_notes');
        parent::tearDown();
    }

    private function index(): void
    {
        foreach (FilterScopedNote::all() as $note) {
            app(IndexManager::class)->indexModel($note);
        }
    }

    /** @return string[] */
    private function queriesOf(\Closure $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $run();
        } finally {
            DB::disableQueryLog();
        }

        return array_column(DB::getQueryLog(), 'query');
    }

    public function test_did_you_mean_honours_filter(): void
    {
        $this->assertSame(['jonah'], array_column(FilterScopedNote::search('jonaz')->filter('tenant_id', 1)->didYouMean(5), 'term'));
        $this->assertSame(['jonas'], array_column(FilterScopedNote::search('jonaz')->filter('tenant_id', 2)->didYouMean(5), 'term'));
    }

    public function test_did_you_mean_honours_filter_in(): void
    {
        $this->assertSame(['jonas'], array_column(FilterScopedNote::search('jonaz')->filterIn('tenant_id', [2])->didYouMean(5), 'term'));
    }

    public function test_did_you_mean_groups_an_or_where_before_the_filter(): void
    {
        // where(A)->orWhere(B) then filter(C) is (A or B) and C: tenant 2's row matches B but not C.
        $terms = array_column(
            FilterScopedNote::search('jonaz')->where('tenant_id', 1)->orWhere('tenant_id', 2)->filter('tenant_id', 1)->didYouMean(5),
            'term'
        );

        $this->assertSame(['jonah'], $terms);
    }

    public function test_suggest_honours_filter(): void
    {
        $suggestions = FilterScopedNote::search('jon')->filter('tenant_id', 1)->suggest(5);

        $this->assertContains('jonah', $suggestions);
        $this->assertNotContains('jonas', $suggestions);
    }

    public function test_the_json_collection_suggestions_honour_filter(): void
    {
        $meta = FuzzySearchCollection::fromBuilder(
            FilterScopedNote::search('jonaz')->filter('tenant_id', 1)->useInvertedIndex()->typoTolerance(0)
        )->with(request())['meta'];

        $this->assertSame(['jonah'], $meta['suggestions']);
    }

    public function test_suggest_cuts_its_limit(): void
    {
        $limits = [];
        foreach ($this->queriesOf(fn () => FilterScopedNote::search('jon')->filter('tenant_id', 1)->suggest(1_000_000)) as $sql) {
            if (preg_match('/limit (\d+)/i', $sql, $m)) {
                $limits[] = (int) $m[1];
            }
        }

        $this->assertNotSame([], $limits);
        $this->assertLessThanOrEqual(150, max($limits));
    }

    public function test_did_you_mean_cuts_its_limit(): void
    {
        // 200 terms within two edits of "jonaz", all in tenant 2, which the filter hides.
        $words = [];
        foreach (str_split('jonaz') as $i => $char) {
            foreach (range('a', 'z') as $letter) {
                $words[substr_replace('jonaz', $letter, $i, 1)] = true;
                $words[substr_replace('jonaz', $letter, $i, 0)] = true;
            }
        }
        $words = array_slice(array_keys(array_diff_key($words, ['jonaz' => 1, 'jonah' => 1])), 0, 200);
        $this->assertCount(200, $words);
        foreach ($words as $word) {
            FilterScopedNote::create(['tenant_id' => 2, 'body' => $word]);
        }
        $this->index();

        $checks = array_filter(
            $this->queriesOf(fn () => FilterScopedNote::search('jonaz')->filter('tenant_id', 1)->didYouMean(1_000_000)),
            fn (string $sql) => str_contains($sql, 'fuzzy_index_postings') || str_contains($sql, 'filter_scoped_notes')
        );

        // At most 150 candidates, each a postings read and a key check (plus a few reads besides).
        $this->assertLessThanOrEqual(320, count($checks));
    }
}
