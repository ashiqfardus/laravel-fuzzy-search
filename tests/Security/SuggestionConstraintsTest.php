<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchCollection;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dictionary is scoped to the model, not to a query: on a multi-tenant indexed model it
 * holds every tenant's terms. suggest() in 'auto' mode and didYouMean() must therefore honour
 * the caller's where() and the model's global scopes — the SoftDeletes scope excepted, which
 * the index already honours (a trashed row is removed from it).
 */
class SuggestionConstraintsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tenant_notes');
        Schema::create('tenant_notes', function ($table) {
            $table->id();
            $table->unsignedInteger('tenant_id');
            $table->string('body');
        });

        // Tenant 1 wrote "jonah", tenant 2 wrote "jonas". The index holds both, as it does
        // when a worker indexes every tenant's rows.
        TenantNote::create(['tenant_id' => 1, 'body' => 'jonah headphones']);
        TenantNote::create(['tenant_id' => 2, 'body' => 'jonas speaker']);
        $this->index(TenantNote::all());
        $this->index(TenantOneNote::withoutGlobalScopes()->get());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tenant_notes');

        parent::tearDown();
    }

    private function index(iterable $models): void
    {
        foreach ($models as $model) {
            app(IndexManager::class)->indexModel($model);
        }
    }

    /** @return string[] the SQL of every query $run executed */
    private function queriesOf(Closure $run): array
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

    /** @return string[] */
    private function terms(array $didYouMean): array
    {
        return array_column($didYouMean, 'term');
    }

    // ---- suggest() ----------------------------------------------------------------------

    public function test_auto_suggest_honours_the_callers_where(): void
    {
        $this->index(Product::all()); // MacBook Pro costs 1999.99

        $table = Product::search('mac')->where('price', '<', 1000)->suggestFrom('table')->suggest(5);
        $auto  = Product::search('mac')->where('price', '<', 1000)->suggest(5);

        $this->assertSame([], $table, 'baseline: the table scan honours where()');
        $this->assertSame($table, $auto, 'dictionary suggest() leaked a term from a row the where() excludes');
    }

    public function test_auto_suggest_honours_a_global_scope(): void
    {
        $suggestions = TenantOneNote::search('jon')->suggest(5);

        $this->assertContains('jonah', $suggestions);
        foreach ($suggestions as $suggestion) {
            $this->assertStringStartsNotWith('jonas', $suggestion, 'another tenant\'s term was suggested');
        }
    }

    public function test_an_unconstrained_indexed_model_still_completes_from_the_dictionary(): void
    {
        $this->index(Product::all());

        // The table scan would propose the value as stored ("MacBook", "MacBook Pro");
        // only the dictionary yields the lower-case term.
        $this->assertSame(['macbook'], Product::search('mac')->suggest(5));
    }

    public function test_suggest_from_index_stays_model_wide_under_a_where(): void
    {
        $this->index(Product::all());

        $this->assertSame(['macbook'], Product::search('mac')->where('price', '<', 1000)->suggestFrom('index')->suggest(5));
    }

    // ---- the SoftDeletes scope is not a constraint here ---------------------------------

    public function test_a_soft_deletes_model_completes_from_the_dictionary_and_did_you_mean_adds_no_queries(): void
    {
        $this->index(SoftDeletedUser::all());

        $suggestions = SoftDeletedUser::search('jo')->suggest(10);
        $this->assertContains('john', $suggestions);
        $this->assertNotContains('John', $suggestions, 'auto fell back to the table scan for the SoftDeletes scope');

        $alternatives = [];
        $queries = $this->queriesOf(function () use (&$alternatives) {
            $alternatives = SoftDeletedUser::search('jonh')->didYouMean(3);
        });
        $this->assertNotEmpty($alternatives);
        $this->assertCount(1, $queries, 'didYouMean() verified candidates although only the SoftDeletes scope applies: ' . json_encode($queries));
    }

    public function test_a_soft_deletes_model_with_a_where_takes_the_constrained_paths(): void
    {
        $this->index(SoftDeletedUser::all());

        $this->assertContains('John', SoftDeletedUser::search('jo')->where('email', 'john@example.com')->suggest(10));

        $alternatives = [];
        $queries = $this->queriesOf(function () use (&$alternatives) {
            $alternatives = SoftDeletedUser::search('jonh')->where('email', 'jon@example.com')->didYouMean(5);
        });
        $this->assertGreaterThan(1, count($queries), 'the where() was not verified');
        $this->assertSame(['jon'], $this->terms($alternatives), 'only Jon Snow is in scope');
    }

    // ---- didYouMean() -------------------------------------------------------------------

    public function test_did_you_mean_honours_a_tenant_where(): void
    {
        $this->assertEqualsCanonicalizing(['jonah', 'jonas'], $this->terms(TenantNote::search('jonaz')->didYouMean(5)), 'precondition');

        $tenantOne = $this->terms(TenantNote::search('jonaz')->where('tenant_id', 1)->didYouMean(5));
        $tenantTwo = $this->terms(TenantNote::search('jonaz')->where('tenant_id', 2)->didYouMean(5));

        $this->assertSame(['jonah'], $tenantOne);
        $this->assertSame(['jonas'], $tenantTwo);
    }

    public function test_did_you_mean_honours_a_global_scope(): void
    {
        $this->assertSame(['jonah'], $this->terms(TenantOneNote::search('jonaz')->didYouMean(5)));
    }

    public function test_did_you_mean_verification_is_bounded(): void
    {
        $unconstrained = $this->queriesOf(fn () => TenantNote::search('jonaz')->didYouMean(3));
        $this->assertCount(1, $unconstrained, 'an unconstrained query made extra queries');

        // A tenant that sees none of the candidates: every one is checked, and each check is one
        // postings read plus one chunked primary-key lookup (the ids fit one chunk here).
        $constrained = $this->queriesOf(fn () => TenantNote::search('jonaz')->where('tenant_id', 3)->didYouMean(3));
        $this->assertCount(1 + 2 * 2, $constrained, json_encode($constrained));
        foreach ($constrained as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('like', $sql, 'verification must not scan the table');
        }
    }

    public function test_did_you_mean_checks_at_most_max_limit_times_three_or_ten_candidates(): void
    {
        // Twelve terms one edit from "jonaz", each on two rows of a tenant the query cannot
        // see, so all twelve rank above tenant 1's "jonah" (one row).
        foreach (range('a', 'l') as $letter) {
            TenantNote::create(['tenant_id' => 2, 'body' => 'jona' . $letter . 'z']);
            TenantNote::create(['tenant_id' => 2, 'body' => 'jona' . $letter . 'z']);
        }
        $this->index(TenantNote::all());

        $alternatives = [];
        $queries = $this->queriesOf(function () use (&$alternatives) {
            $alternatives = TenantNote::search('jonaz')->where('tenant_id', 1)->didYouMean(1);
        });

        // 1 dictionary read + 10 candidates × (postings read + primary-key check). The budget
        // runs out before "jonah" is reached: fewer results, never another tenant's term.
        $this->assertCount(1 + 10 * 2, $queries);
        $this->assertSame([], $alternatives);
    }

    public function test_the_json_collection_suggestions_honour_a_where(): void
    {
        $meta = FuzzySearchCollection::fromBuilder(
            TenantNote::search('jonaz')->where('tenant_id', 1)->useInvertedIndex()->typoTolerance(0)
        )->with(request())['meta'];

        $this->assertSame(['jonah'], $meta['suggestions']);
    }
}

class TenantNote extends Model
{
    use Searchable;

    protected $table   = 'tenant_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['body' => 1]];
}

/** A tenant-style global scope: the request sees tenant 1 only, the index holds every tenant. */
class TenantOneNote extends TenantNote
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn ($query) => $query->where('tenant_id', 1));
    }
}
