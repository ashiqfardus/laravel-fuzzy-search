<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** relationships.md's shape: a relation column among the model's $searchable columns. */
class TableSearchRelationUser extends User
{
    protected array $searchable = ['columns' => ['name' => 10, 'users.email' => 5, 'notes.body' => 2]];

    public function notes(): HasMany
    {
        return $this->hasMany(TableSearchNote::class, 'user_id');
    }
}

class TableSearchNote extends \Illuminate\Database\Eloquent\Model
{
    protected $table   = 'table_search_notes';
    protected $guarded = [];
    public $timestamps = false;
}

class TableSearchTest extends TestCase
{
    /** Filament evaluates the closure inside a where group with (query, search). */
    private function apply(\Closure $closure, string $search): array
    {
        return User::query()->where(fn ($q) => $closure($q, $search))->orderBy('id')->pluck('name')->all();
    }

    public function test_the_closure_applies_a_fuzzy_match_on_the_named_columns(): void
    {
        $this->assertContains('John Doe', $this->apply(FuzzySearch::tableSearch(['name']), 'jonh'));
        $this->assertNotContains('Bob Johnson', $this->apply(FuzzySearch::tableSearch(['email']), 'zzzz'));
    }

    public function test_a_single_column_string_and_the_model_defaults_both_work(): void
    {
        $this->assertContains('John Doe', $this->apply(FuzzySearch::tableSearch('name'), 'jonh'));
        // null → the model's $searchable columns (User declares name + email)
        $this->assertContains('Bob Johnson', $this->apply(FuzzySearch::tableSearch(), 'bob@'));
    }

    public function test_algorithm_and_options_are_forwarded(): void
    {
        // 'like' is the registered exact-substring driver (src/FuzzySearch.php $registry);
        // there is no 'exact' entry, so this test uses 'like' to get exact-match semantics.
        $exact = $this->apply(FuzzySearch::tableSearch(['name'], 'like'), 'jonh');
        $fuzzy = $this->apply(FuzzySearch::tableSearch(['name'], 'fuzzy', ['max_distance' => 2]), 'jonh');

        $this->assertNotContains('John Doe', $exact);
        $this->assertContains('John Doe', $fuzzy);
    }

    public function test_the_closure_returns_the_builder_and_composes_with_other_constraints(): void
    {
        $closure = FuzzySearch::tableSearch(['name']);
        $names   = User::query()->where('email', 'like', '%example.com')->where(fn ($q) => $closure($q, 'jonh'))->pluck('name')->all();

        $this->assertContains('John Doe', $names);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $closure(User::query(), 'x'));
    }

    /** Ruling P8-R18: Filament's search box is unbounded user input, so the closure caps it. */
    public function test_a_long_search_is_capped_at_max_term_length(): void
    {
        config(['fuzzy-search.query.max_term_length' => 5]);

        $query = User::query();
        (FuzzySearch::tableSearch(['name']))($query, str_repeat('a', 40));

        $strings = array_filter($query->getQuery()->getBindings(), 'is_string');
        $this->assertNotEmpty($strings);
        foreach ($strings as $binding) {
            // 5 + 1: a typo pattern may carry one extra wildcard character ('%aaaa_a%') for the
            // inserted edit. Uncapped, every one of these bindings would be 40 characters wide.
            $this->assertLessThanOrEqual(6, mb_strlen(trim($binding, '%')), $binding);
        }
    }

    public function test_the_columns_are_qualified_with_the_table(): void
    {
        $query = User::query();
        (FuzzySearch::tableSearch(['name']))($query, 'jonh');

        $this->assertStringContainsString($query->getQuery()->getGrammar()->wrap('users.name'), $query->toSql());
    }

    /**
     * UD-2. With no columns the closure took the model's $searchable columns whole, and qualified a
     * relation column (`notes.body`) as the SQL column "notes"."body", which the table query does
     * not have: every typed search of a Filament table over such a model threw. The no-argument
     * form now leaves the relation columns out, to Filament's own searchable(); a `table.column`
     * name whose head is no relation is still searched as written.
     */
    public function test_with_no_columns_a_relation_column_of_the_model_is_left_out(): void
    {
        Schema::dropIfExists('table_search_notes');
        Schema::create('table_search_notes', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('body');
        });
        DB::table('table_search_notes')->insert(['user_id' => User::query()->where('name', 'John Doe')->value('id'), 'body' => 'kestrel']);

        $search = fn (string $term) => TableSearchRelationUser::query()
            ->where(fn ($q) => FuzzySearch::tableSearch()($q, $term))->orderBy('id')->pluck('name')->all();

        $this->assertContains('John Doe', $search('jonh'));
        $this->assertContains('John Doe', $search('john@example')); // users.email, the table's own
        $this->assertSame([], $search('kestrel'));                   // only in the relation column
        $this->assertSame(TableSearchRelationUser::query()->count(), TableSearchRelationUser::query()->where(fn ($q) => FuzzySearch::tableSearch()($q, ''))->count());

        Schema::dropIfExists('table_search_notes');
    }
}
