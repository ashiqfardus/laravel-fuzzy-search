<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../TestModels.php';

/**
 * The query macros and the Fuzzy scopes bind their term through FuzzySearch, not SearchBuilder,
 * so they need query.max_term_length there too: a 2000-character levenshtein term used to build
 * every O(n²) pattern (>2 GB) before max_patterns trimmed the list.
 */
class MacroTermCapTest extends TestCase
{
    private const MAX_TERM     = 64;
    private const MAX_PATTERNS = 100;

    /**
     * No two neighbouring characters are equal, so every generated pattern is distinct, and none of
     * soundex's substitutions (f → ph, k → ck) applies, so no pattern is longer than the term.
     */
    private string $term;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fuzzy-search.query.max_term_length'    => self::MAX_TERM,
            'fuzzy-search.performance.max_patterns' => self::MAX_PATTERNS,
        ]);

        $this->term = substr(str_repeat('abdegijlmnopqrtuvxy', 106), 0, 2000);

        // metaphone searches a shadow column
        Schema::table('users', function ($table) {
            $table->string('name_metaphone')->nullable();
            $table->string('email_metaphone')->nullable();
        });
    }

    public static function algorithms(): array
    {
        // levenshtein last: before the cap it exhausts memory and ends the run
        return array_map(fn ($a) => [$a], array_combine(
            $names = ['simple', 'like', 'similar_text', 'soundex', 'trigram', 'metaphone', 'fuzzy', 'levenshtein'],
            $names
        ));
    }

    #[DataProvider('algorithms')]
    public function test_the_where_macros_cap_the_term_and_the_patterns(string $algorithm): void
    {
        $this->assertCapped(fn () => $this->app['db']->table('users')->whereFuzzy('name', $this->term, $algorithm), 1);
        $this->assertCapped(fn () => $this->app['db']->table('users')->where('id', '>', 0)->orWhereFuzzy('name', $this->term, $algorithm), 1, 1);
        $this->assertCapped(fn () => $this->app['db']->table('users')->whereFuzzyMultiple(['name', 'email'], $this->term, $algorithm), 2);
        $this->assertCapped(fn () => User::query()->whereFuzzy('name', $this->term, $algorithm), 1);
        $this->assertCapped(fn () => User::query()->whereFuzzyMultiple(['name', 'email'], $this->term, $algorithm), 2);
        $this->assertCapped(fn () => User::query()->fuzzyWith($algorithm, $this->term), 2); // Fuzzy scope, $fuzzySearchable = [name, email]
    }

    public function test_the_fuzzy_scopes_cap_the_term_and_the_patterns(): void
    {
        $this->assertCapped(fn () => User::query()->fuzzy($this->term), 2);
        $this->assertCapped(fn () => User::query()->fuzzyLevenshtein($this->term), 2);
        $this->assertCapped(fn () => User::query()->fuzzySoundex($this->term), 2);
        $this->assertCapped(fn () => User::query()->fuzzySimilar($this->term, null, 50), 2);
    }

    public function test_the_fuzzy_search_macro_caps_the_term_and_the_patterns(): void
    {
        $this->assertCapped(fn () => $this->app['db']->table('users')->fuzzySearch(['name', 'email'], $this->term), 2);
        $this->assertCapped(fn () => User::query()->fuzzySearch('name', $this->term), 1);
    }

    public function test_order_by_fuzzy_binds_the_capped_term(): void
    {
        $this->assertCapped(fn () => $this->app['db']->table('users')->orderByFuzzy('name', $this->term), 1);
        $this->assertCapped(fn () => User::query()->orderByFuzzy('name', $this->term, 'desc'), 1);
    }

    public function test_federated_search_caps_the_term_of_a_model_without_the_trait(): void
    {
        // A model without Searchable goes through whereFuzzyMultiple(), not SearchBuilder.
        foreach (['simple', 'levenshtein'] as $algorithm) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            FederatedSearch::across([MacroCapPlainUser::class])->search($this->term)->searchIn(['name'])->using($algorithm)->get();

            DB::disableQueryLog();
            $bindings = array_merge([], ...array_column(DB::getQueryLog(), 'bindings'));

            $this->assertNotEmpty($bindings, $algorithm);
            $this->assertLessThanOrEqual(self::MAX_PATTERNS + 1, count($bindings), $algorithm); // + the LIMIT
            foreach (array_filter($bindings, 'is_string') as $binding) {
                $this->assertLessThanOrEqual(self::MAX_TERM + 3, mb_strlen($binding), "{$algorithm}: a binding carries the uncapped term");
            }
        }
    }

    /**
     * @param \Closure(): (\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder) $build
     * @param int $columns how many columns the predicate covers
     * @param int $extra   bindings the test itself adds
     */
    private function assertCapped(\Closure $build, int $columns, int $extra = 0): void
    {
        $query    = $build();
        $bindings = $query->getBindings();

        $this->assertLessThanOrEqual($columns * self::MAX_PATTERNS + $extra, count($bindings), 'more bindings than max_patterns allows');

        foreach ($bindings as $binding) {
            if (is_string($binding)) {
                // a LIKE pattern adds at most three wildcards (%a_b%) to the capped term
                $this->assertLessThanOrEqual(self::MAX_TERM + 3, mb_strlen($binding), 'a binding carries the uncapped term');
            }
        }

        $query->get(); // and the SQL runs
    }
}

/** No Searchable trait: FederatedSearch searches it through the whereFuzzyMultiple() macro. */
class MacroCapPlainUser extends Model
{
    protected $table = 'users';
}
