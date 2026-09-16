<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Illuminate\Support\Facades\DB;

/**
 * SearchBuilder Unit Tests
 *
 * Unit tests for the SearchBuilder fluent API methods.
 */
class SearchBuilderTest extends TestCase
{
    use FakesDriverConnections;

    protected SearchBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        
        $query = User::query();
        $fuzzySearch = app(FuzzySearch::class);
        $this->builder = new SearchBuilder($query, $fuzzySearch);
    }

    /*
    |--------------------------------------------------------------------------
    | Fluent API Method Tests
    |--------------------------------------------------------------------------
    */

    public function test_search_method_is_chainable(): void
    {
        $result = $this->builder->search('test');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_search_in_method_is_chainable(): void
    {
        $result = $this->builder->searchIn(['name', 'email']);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_using_method_is_chainable(): void
    {
        $result = $this->builder->using('levenshtein');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_typo_tolerance_method_is_chainable(): void
    {
        $result = $this->builder->typoTolerance(2);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_tokenize_method_is_chainable(): void
    {
        $result = $this->builder->tokenize();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_match_all_method_is_chainable(): void
    {
        $result = $this->builder->matchAll();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_match_any_method_is_chainable(): void
    {
        $result = $this->builder->matchAny();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_prefix_boost_method_is_chainable(): void
    {
        $result = $this->builder->prefixBoost(2.0);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_partial_match_method_is_chainable(): void
    {
        $result = $this->builder->partialMatch();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_min_match_length_method_is_chainable(): void
    {
        $result = $this->builder->minMatchLength(3);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_custom_score_method_is_chainable(): void
    {
        $result = $this->builder->customScore(fn($item, $score) => $score);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_ignore_stop_words_method_is_chainable(): void
    {
        $result = $this->builder->ignoreStopWords();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_with_synonyms_method_is_chainable(): void
    {
        $result = $this->builder->withSynonyms(['test' => ['exam']]);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_synonym_group_method_is_chainable(): void
    {
        $result = $this->builder->synonymGroup(['laptop', 'notebook']);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_locale_method_is_chainable(): void
    {
        $result = $this->builder->locale('en');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_accent_insensitive_method_is_chainable(): void
    {
        $result = $this->builder->accentInsensitive();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_unicode_normalize_method_is_chainable(): void
    {
        $result = $this->builder->unicodeNormalize();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_highlight_method_is_chainable(): void
    {
        $result = $this->builder->highlight('em');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_debug_score_method_is_chainable(): void
    {
        $result = $this->builder->debugScore();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_use_index_method_is_chainable(): void
    {
        $result = $this->builder->useIndex();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_cache_method_is_chainable(): void
    {
        $result = $this->builder->cache(60);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_stable_ranking_method_is_chainable(): void
    {
        $result = $this->builder->stableRanking();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_fallback_method_is_chainable(): void
    {
        $result = $this->builder->fallback('like');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_debounce_is_deprecated_but_still_chainable(): void
    {
        $deprecations = [];
        set_error_handler(function (int $errno, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_USER_DEPRECATED);

        try {
            $result = $this->builder->debounce(300);
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(SearchBuilder::class, $result);
        $this->assertCount(1, $deprecations);
        $this->assertStringContainsString('debounce() is deprecated', $deprecations[0]);
    }

    public function test_max_patterns_method_is_chainable(): void
    {
        $result = $this->builder->maxPatterns(50);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_filter_method_is_chainable(): void
    {
        $result = $this->builder->filter('name', 'John');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_filter_in_method_is_chainable(): void
    {
        $result = $this->builder->filterIn('id', [1, 2, 3]);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_facet_method_is_chainable(): void
    {
        $result = $this->builder->facet('name');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_with_relevance_method_is_chainable(): void
    {
        $result = $this->builder->withRelevance();
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_take_method_is_chainable(): void
    {
        $result = $this->builder->take(10);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_limit_method_is_chainable(): void
    {
        $result = $this->builder->limit(10);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_skip_method_is_chainable(): void
    {
        $result = $this->builder->skip(5);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_offset_method_is_chainable(): void
    {
        $result = $this->builder->offset(5);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_page_method_is_chainable(): void
    {
        $result = $this->builder->page(1, 10);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_order_by_method_is_chainable(): void
    {
        $result = $this->builder->orderBy('name');
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    public function test_options_method_is_chainable(): void
    {
        $result = $this->builder->options(['max_distance' => 3]);
        
        $this->assertInstanceOf(SearchBuilder::class, $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Value Constraint Tests
    |--------------------------------------------------------------------------
    */

    public function test_typo_tolerance_is_clamped_to_max(): void
    {
        $this->builder->typoTolerance(10);

        $prop = new \ReflectionProperty($this->builder, 'typoTolerance');
        $prop->setAccessible(true);
        $this->assertSame(5, $prop->getValue($this->builder));
    }

    public function test_typo_tolerance_is_clamped_to_min(): void
    {
        $this->builder->typoTolerance(-5);

        $prop = new \ReflectionProperty($this->builder, 'typoTolerance');
        $prop->setAccessible(true);
        $this->assertSame(0, $prop->getValue($this->builder));
    }

    public function test_prefix_boost_has_minimum_of_one(): void
    {
        $this->builder->prefixBoost(0.5);

        $prop = new \ReflectionProperty($this->builder, 'prefixBoostMultiplier');
        $prop->setAccessible(true);
        $this->assertSame(1.0, $prop->getValue($this->builder));
    }

    public function test_min_match_length_has_minimum_of_one(): void
    {
        $this->builder->minMatchLength(0);

        $prop = new \ReflectionProperty($this->builder, 'minMatchLength');
        $prop->setAccessible(true);
        $this->assertSame(1, $prop->getValue($this->builder));
    }

    public function test_max_patterns_floor_matches_cap_patterns_floor(): void
    {
        // maxPatterns() used to floor at 10 while BaseDriver::capPatterns() floors at 1 —
        // an undocumented, inconsistent minimum (M8). Both now floor at 1.
        $this->builder->maxPatterns(5);

        $prop = new \ReflectionProperty($this->builder, 'maxPatterns');
        $prop->setAccessible(true);
        $this->assertSame(5, $prop->getValue($this->builder));

        $this->builder->maxPatterns(0);
        $this->assertSame(1, $prop->getValue($this->builder));
    }

    /*
    |--------------------------------------------------------------------------
    | Complex Chain Tests
    |--------------------------------------------------------------------------
    */

    public function test_full_chain_is_valid(): void
    {
        $results = User::search('john')
            ->searchIn(['name' => 10, 'email' => 5])
            ->using('levenshtein')
            ->typoTolerance(2)
            ->tokenize()
            ->matchAny()
            ->prefixBoost(2.0)
            ->ignoreStopWords()
            ->accentInsensitive()
            ->highlight('em')
            ->withRelevance()
            ->stableRanking()
            ->cache(60)
            ->limit(10)
            ->get();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $results);
    }

    /**
     * Regression: alpha.4 fix — searchIn() must deduplicate columns.
     * Before the fix, chaining searchIn(['name']) when 'name' was already in the
     * trait's default columns produced triple-binding SQL: (name LIKE ? OR email LIKE ? OR name LIKE ?)
     */
    public function test_search_in_deduplicates_duplicate_columns(): void
    {
        $builder = User::search('john')->searchIn(['name', 'name', 'email', 'name']);
        $sql = $builder->toSql();

        // With proper dedup, the ORDER BY clause has 3 CASE WHEN blocks for 'name' and
        // 3 for 'email' (exact + prefix + contains). Without dedup, 'name' would appear
        // 3× more: 9 blocks for name instead of 3.
        // Count CASE WHEN name blocks vs CASE WHEN email blocks — should be equal.
        // Column quoting differs per driver (`name` on MySQL, "name" on PostgreSQL,
        // [name] on SQL Server, bare on SQLite), so match any quote style.
        $nameBlocks  = preg_match_all('/CASE WHEN [`"\[]?name[`"\]]?\s/', $sql);
        $emailBlocks = preg_match_all('/CASE WHEN [`"\[]?email[`"\]]?\s/', $sql);

        $this->assertEquals($nameBlocks, $emailBlocks,
            'After dedup, name and email should have equal CASE WHEN scoring blocks. ' .
            "Got name={$nameBlocks}, email={$emailBlocks} in: {$sql}");
        $this->assertGreaterThan(0, $nameBlocks, 'Should have at least one CASE WHEN for name');
    }

    /*
    |--------------------------------------------------------------------------
    | Stable Ranking / suggest() SQL Tests (Task 7: B2, B3)
    |--------------------------------------------------------------------------
    */

    public function test_stable_ranking_orders_by_the_models_primary_key_name(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
            protected $table = 'users';
            protected $primaryKey = 'email'; // pretend key to prove we do not hard-code "id"
            public $incrementing = false;
            protected $keyType = 'string';
            protected array $searchable = ['columns' => ['name' => 1]];
        };

        $sql = strtolower($model::search('john')->stableRanking()->toSql());

        $this->assertMatchesRegularExpression('/order by .*[`"\[]?users[`"\]]?\.[`"\[]?email[`"\]]? asc/', $sql);
        $this->assertDoesNotMatchRegularExpression('/[`"\[\s]id[`"\]\s] asc/', $sql);
    }

    public function test_stable_ranking_orders_by_bare_id_for_query_builder(): void
    {
        $sql = strtolower(
            (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))
                ->search('john')
                ->searchIn(['name'])
                ->stableRanking()
                ->toSql()
        );

        $this->assertMatchesRegularExpression('/order by .*[`"\[]?id[`"\]]? asc/', $sql);
        $this->assertDoesNotMatchRegularExpression('/users[`"\]]?\.[`"\[]?id/', $sql,
            'Query-Builder stableRanking() must order by a bare "id", not a table-qualified column.');
    }

    public function test_suggest_candidate_query_uses_ilike_only_on_postgresql(): void
    {
        $expected = [
            'mysql'   => false,
            'mariadb' => false,
            'pgsql'   => true,
            'sqlsrv'  => false,
            'sqlite'  => false,
        ];

        $checked = 0;

        foreach ($expected as $driver => $expectsIlike) {
            if (!$this->fakeDriverAvailable($driver)) {
                continue;
            }

            $builder = new SearchBuilder(
                $this->fakeConnectionTable($driver, 'users'),
                app(FuzzySearch::class)
            );
            $builder->search('joh')->searchIn(['name']);

            $sql = strtolower(\Closure::bind(
                fn () => $this->suggestCandidateQuery('joh')->toSql(),
                $builder,
                SearchBuilder::class
            )());

            if ($expectsIlike) {
                $this->assertStringContainsString('ilike', $sql, $driver);
            } else {
                $this->assertStringContainsString('like', $sql, $driver);
                $this->assertStringNotContainsString('ilike', $sql, $driver);
            }

            $checked++;
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'at least mysql, pgsql, sqlite and sqlsrv must be asserted');
    }
}
