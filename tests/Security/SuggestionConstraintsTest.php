<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchCollection;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
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
            $table->string('extra')->nullable();
        });

        // User 1 is a member of tenant 1 only — tenancy by join (MemberNote).
        Schema::dropIfExists('tenant_members');
        Schema::create('tenant_members', function ($table) {
            $table->unsignedInteger('tenant_id');
            $table->unsignedInteger('user_id');
        });
        DB::table('tenant_members')->insert(['tenant_id' => 1, 'user_id' => 1]);

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
        Schema::dropIfExists('tenant_members');
        Schema::dropIfExists('tenant_tags');

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

    public function test_did_you_mean_caps_distinct_rows_not_postings(): void
    {
        // Tenant 2's row holds "jonah" in two columns, so two of the term's postings; tenant 1's
        // row holds it once. A cap of two postings was filled by tenant 2's row twice.
        config(['fuzzy-search.max_candidates' => 2]);
        DB::table('tenant_notes')->where('id', 2)->update(['body' => 'jonah speaker', 'extra' => 'jonah']);
        $tenantOne = TwoColumnNote::create(['tenant_id' => 1, 'body' => 'jonah']);
        $this->index([TwoColumnNote::find(2), $tenantOne]);

        $this->assertSame(['jonah'], $this->terms(TwoColumnNote::search('jonaz')->where('tenant_id', 1)->didYouMean(5)));
    }

    // ---- a join is a constraint -----------------------------------------------------------

    public function test_a_join_that_narrows_the_rows_scopes_suggestions(): void
    {
        $this->index(MemberNote::withoutGlobalScopes()->get());

        $this->assertSame([], MemberNote::search('jonas')->useInvertedIndex()->typoTolerance(0)->get()->pluck('body')->all(), 'precondition: the search honours the join');
        $this->assertSame(['jonah'], $this->terms(MemberNote::search('jonaz')->didYouMean(5)));
        $this->assertEmpty(preg_grep('/^jonas/', MemberNote::search('jon')->suggest(5)), 'another tenant\'s term was suggested');

        $callerJoin = TenantNote::search('jonaz')
            ->join('tenant_members as m', fn ($join) => $join->on('m.tenant_id', '=', 'tenant_notes.tenant_id')->where('m.user_id', '=', 1))
            ->select('tenant_notes.*');
        $this->assertSame(['jonah'], $this->terms($callerJoin->didYouMean(5)));
    }

    public function test_a_join_that_narrows_the_rows_narrows_the_totals(): void
    {
        $this->index(MemberNote::withoutGlobalScopes()->get());

        // Only tenant 2 wrote "jonas": a total above 0 tells tenant 1 that it exists.
        $search = fn () => MemberNote::search('jonas')->useInvertedIndex()->typoTolerance(0);

        $this->assertSame(0, $search()->count());
        $this->assertSame(0, $search()->paginate(10)->total());
    }

    public function test_a_one_to_many_join_counts_models_not_joined_rows(): void
    {
        // Three members of tenant 1: TeamNote's join repeats each tenant-1 note three times.
        DB::table('tenant_members')->insert([['tenant_id' => 1, 'user_id' => 2], ['tenant_id' => 1, 'user_id' => 3]]);
        TenantNote::create(['tenant_id' => 1, 'body' => 'jonah cable']);
        $this->index(TeamNote::withoutGlobalScopes()->get());

        $search = fn () => TeamNote::search('jonah')->useInvertedIndex()->typoTolerance(0);

        $this->assertCount(2, $search()->get(), 'precondition: two models match');
        $this->assertSame(2, $search()->count());
        $this->assertSame(2, $search()->paginate(10)->total());
        // The usual way to de-duplicate a join still counts its groups, not one per chunk.
        $this->assertSame(2, $search()->groupBy('tenant_notes.id')->count());
    }

    public function test_a_joined_table_sharing_a_searched_column_does_not_break_the_suggestion_scan(): void
    {
        Schema::create('tenant_tags', function ($table) {
            $table->unsignedInteger('tenant_id');
            $table->string('body');
        });
        DB::table('tenant_tags')->insert(['tenant_id' => 1, 'body' => 'jonas tag']);
        $this->index(TaggedNote::withoutGlobalScopes()->get());

        // The join makes 'auto' take the table scan, whose LIKE named a bare "body" that both
        // tables have.
        $this->assertSame(['jonah', 'jonah headphones'], TaggedNote::search('jon')->suggest(5));
    }

    // ---- a plain query builder with an explicit model -------------------------------------

    public function test_a_plain_query_builder_with_an_explicit_model_keeps_its_where(): void
    {
        $plain = fn (string $term) => (new SearchBuilder(DB::table('tenant_notes')->where('tenant_id', 1), app(FuzzySearch::class)))
            ->search($term)->searchIn(['body'])->useInvertedIndex(TenantNote::class);

        $this->assertSame([], $plain('jonas')->typoTolerance(0)->get()->pluck('body')->all(), 'the index path dropped the where()');
        $this->assertSame(0, $plain('jonas')->typoTolerance(0)->count());
        $this->assertSame(['jonah'], $this->terms($plain('jonaz')->didYouMean(5)));
        $this->assertEmpty(preg_grep('/^jonas/', $plain('jon')->suggest(5)), 'another tenant\'s term was suggested');
    }

    public function test_a_plain_query_builder_checks_did_you_mean_against_the_models_global_scope(): void
    {
        $plain = (new SearchBuilder(DB::table('tenant_notes'), app(FuzzySearch::class)))
            ->search('jonaz')->searchIn(['body'])->useInvertedIndex(TenantOneNote::class);

        $this->assertSame(['jonah'], $this->terms($plain->didYouMean(5)));
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

/** Tenancy by join: the request's user (1) is a member of tenant 1 only. */
class MemberNote extends TenantNote
{
    protected static function booted(): void
    {
        static::addGlobalScope('member', fn ($query) => $query
            ->join('tenant_members as m', fn ($join) => $join->on('m.tenant_id', '=', 'tenant_notes.tenant_id')->where('m.user_id', '=', 1))
            ->select('tenant_notes.*'));
    }
}

/** A one-to-many join: every member of a note's tenant repeats the note once. */
class TeamNote extends TenantNote
{
    protected static function booted(): void
    {
        static::addGlobalScope('team', fn ($query) => $query
            ->join('tenant_members as m', 'm.tenant_id', '=', 'tenant_notes.tenant_id')
            ->select('tenant_notes.*'));
    }
}

/** A join to a table that also has a "body" column, the column the notes are searched on. */
class TaggedNote extends TenantNote
{
    protected static function booted(): void
    {
        static::addGlobalScope('tagged', fn ($query) => $query
            ->join('tenant_tags', 'tenant_tags.tenant_id', '=', 'tenant_notes.tenant_id')
            ->select('tenant_notes.*'));
    }
}

/** The same notes indexed over two columns, so one row can hold a term twice. */
class TwoColumnNote extends TenantNote
{
    protected array $searchable = ['columns' => ['body' => 1, 'extra' => 1]];
}

/** A tenant-style global scope: the request sees tenant 1 only, the index holds every tenant. */
class TenantOneNote extends TenantNote
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn ($query) => $query->where('tenant_id', 1));
    }
}
