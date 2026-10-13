<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;

class SuggestPlainUser extends Model
{
    use Searchable;

    protected $table = 'users';

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5]];
}

/** A global scope that keeps a one-to-many join from repeating rows, as a visibility scope does. */
class SuggestDistinctScopedUser extends SuggestPlainUser
{
    protected static function booted(): void
    {
        static::addGlobalScope('distinct', fn ($query) => $query->select('users.*')->distinct());
    }
}

class SuggestJoinDistinctScopedUser extends SuggestPlainUser
{
    protected static function booted(): void
    {
        static::addGlobalScope('team', fn ($query) => $query->join('suggest_teams', 'suggest_teams.user_id', '=', 'users.id')
            ->where('suggest_teams.team', '<', 3)->select('users.*')->distinct());
    }
}

/**
 * suggest() and didYouMean() answer "what else could you have typed", so they must run beside
 * the search, not inside it: the WHEREs a previous get() compiled must not reach them — while
 * constraints the caller put on the query themselves still scope what can be suggested.
 *
 * This is the docs/integrations.md Livewire recipe: one builder, get(), then suggest() when
 * the page came back empty.
 */
class SuggestBaseQueryTest extends TestCase
{
    private function candidateSql(SearchBuilder $builder, string $term): string
    {
        $method = new ReflectionMethod($builder, 'suggestCandidateQuery');
        $method->setAccessible(true);

        return $method->invoke($builder, $term)->toSql();
    }

    public function test_suggest_query_is_identical_before_and_after_get(): void
    {
        $builder = User::search('joh')->searchIn(['name']);

        $before = $this->candidateSql($builder, 'joh');
        $builder->get();
        $after = $this->candidateSql($builder, 'joh');

        $this->assertSame($before, $after, 'suggest() ran inside the previous search');
    }

    public function test_suggest_after_get_matches_a_fresh_builder(): void
    {
        $builder = User::search('joh')->searchIn(['name'])->limit(10);
        $fresh   = User::search('joh')->searchIn(['name'])->limit(10);

        $builder->get();

        $this->assertNotEmpty($fresh->suggest(5));
        $this->assertSame($fresh->suggest(5), $builder->suggest(5));
    }

    public function test_suggest_still_completes_when_the_search_page_is_empty(): void
    {
        // filter() is one of the search's own predicates: it is compiled with the search and
        // must not reach the suggestions — that empty page is exactly when the recipe asks
        // for them.
        $builder = User::search('joh')->searchIn(['name'])->filter('email', 'nobody@example.com');

        $this->assertEmpty($builder->get(), 'precondition: the page is empty');
        $this->assertNotEmpty($builder->suggest(5));
    }

    public function test_constraints_the_caller_applied_still_scope_the_suggestions(): void
    {
        $scoped = User::search('joh')->searchIn(['name'])->where('email', 'john@example.com');
        $scoped->get();

        // Only John Doe is in scope, so Johnny Bravo and Bob Johnson must not be suggested.
        // (searchIn() adds to the model's configured columns, so email is a source too.)
        $this->assertSame(['John', 'John Doe', 'john@example.com'], $scoped->suggest(5));

        $elsewhere = User::search('joh')->searchIn(['name'])->where('email', 'alice@example.com');
        $elsewhere->get();

        $this->assertSame([], $elsewhere->suggest(5));
    }

    public function test_did_you_mean_is_unaffected_by_the_previous_search(): void
    {
        $termId = $this->app['db']->table('fuzzy_index_terms')->insertGetId(
            ['term' => 'john', 'doc_count' => 50, 'term_length' => 4]
        );
        $this->insertNamedPostings([
            ['term_id' => $termId, 'model_type' => User::class, 'model_id' => '1', 'frequency' => 1, 'column_name' => 'name']
        ]);

        $builder = User::search('jonh')->searchIn(['name']);

        $before = $builder->didYouMean(3);
        $builder->get();
        $after = $builder->didYouMean(3);

        $this->assertContains('john', array_column($before, 'term'));
        $this->assertSame($before, $after);
    }

    /**
     * TE-9. The table scan read the first limit × 3 rows that start with the term in whatever order
     * the plan gave (rowid, heap or index order), then sorted that sample by length: with more such
     * rows than that, the shortest completions in the table were offered only by chance, and on
     * PostgreSQL an UPDATE that changed no value changed the suggestions. The scan now reads the
     * shortest values first, then by value.
     */
    public function test_the_table_scan_offers_the_shortest_completions_in_the_table(): void
    {
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['name' => 'Qu' . substr(md5((string) $i), 0, 8) . ' Smith', 'email' => "q{$i}@example.com"];
        }
        User::query()->insert([...$rows, ...array_map(fn (string $name) => ['name' => $name, 'email' => strtolower(strtok($name, ' ')) . '@example.com'], ['Quin Black', 'Quo Snow', 'Quy Adams', 'Qual Lee'])]);

        $this->assertSame(['Quo', 'Quy', 'Qual', 'Quin'], array_slice(User::search('qu')->searchIn(['name'])->suggestFrom('table')->suggest(5), 0, 4));
        $this->assertSame(['Quo', 'Quy', 'Qual', 'Quin'], array_slice(User::search('qu')->searchIn(['name', 'email'])->suggestFrom('table')->suggest(5), 0, 4));
    }

    public static function distinctScopes(): array
    {
        return [
            'a distinct() global scope'           => [SuggestDistinctScopedUser::class],
            'a join and distinct() global scope' => [SuggestJoinDistinctScopedUser::class],
        ];
    }

    /**
     * VC-2. TE-9's length order is left out where a DISTINCT would reject it, but the check read
     * the Eloquent builder's query before its global scopes applied: a scope's distinct() got
     * `ORDER BY LENGTH(name)`, which PostgreSQL (42P10) and SQL Server (145) reject under SELECT
     * DISTINCT, so every suggest() of such a model threw there.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('distinctScopes')]
    public function test_a_global_scopes_distinct_leaves_the_table_scan_unordered(string $model): void
    {
        Schema::dropIfExists('suggest_teams');
        Schema::create('suggest_teams', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->integer('team');
        });
        foreach (DB::table('users')->pluck('id') as $id) {
            DB::table('suggest_teams')->insert([['user_id' => $id, 'team' => 1], ['user_id' => $id, 'team' => 2]]);
        }

        try {
            $expected = SuggestPlainUser::search('jo')->suggestFrom('table')->suggest(5);
            $this->assertNotEmpty($expected);
            $this->assertSame($expected, $model::search('jo')->suggestFrom('table')->suggest(5));
            $this->assertSame($expected, $model::search('jo')->suggest(5), 'auto: the table scan, the index being empty or the base query joined');
        } finally {
            Schema::dropIfExists('suggest_teams');
        }
    }
}
