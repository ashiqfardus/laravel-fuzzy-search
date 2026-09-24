<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../TestModels.php';

/**
 * Verifies that no user-supplied input is interpolated directly into SQL.
 * Each test checks: query executes without error AND table row count is unchanged.
 */
class InjectionTest extends TestCase
{
    use FakesDriverConnections;

    private FuzzySearch $fuzzySearch;
    private int $baseline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuzzySearch = app(FuzzySearch::class);
        $this->baseline = $this->app['db']->table('users')->count();
    }

    #[DataProvider('injectionVectorProvider')]
    public function test_injection_vector_does_not_corrupt_results(string $vector): void
    {
        try {
            $results = (new SearchBuilder(
                $this->app['db']->table('users'),
                $this->fuzzySearch
            ))
                ->search($vector)
                ->searchIn(['name'])
                ->get();

            // Must not return more rows than the table has
            $this->assertLessThanOrEqual(
                $this->baseline,
                $results->count(),
                "Vector returned more rows than table size: [{$vector}]"
            );
        } catch (\Exception) {
            // Exception is acceptable — PDO or validation rejected the malicious input
        }

        // Table must still have same row count (no DELETE ran)
        $afterCount = $this->app['db']->table('users')->count();
        $this->assertEquals(
            $this->baseline,
            $afterCount,
            "Table row count changed — possible injection: [{$vector}]"
        );
    }

    public static function injectionVectorProvider(): array
    {
        return [
            'classic drop'        => ["'; DROP TABLE users; --"],
            'union select'        => ["' UNION SELECT 1,2,3 --"],
            'boolean always-true' => ["' OR '1'='1"],
            'comment injection'   => ["john' -- "],
            'backtick injection'  => ['`users`'],
            'semicolon chain'     => ["john; SELECT * FROM users"],
            'null byte'           => ["john\0doe"],
            'percent wildcard'    => ["%"],
            'underscore wildcard' => ["_"],
            'double percent'      => ["%%john%%"],
            'backslash escape'    => ["john\\' OR 1=1 --"],
            'very long input'     => [str_repeat('a', 100)],
            'unicode injection'   => ["'; DROP TABLE users; --"],
            'newline injection'   => ["john\nUNION SELECT 1--"],
            'carriage return'     => ["john\rDROP TABLE users"],
        ];
    }

    public function test_extended_search_escapes_like_wildcards(): void
    {
        // '%' in an extended FuzzyTerm must not match all rows — it should be treated as a literal.
        $results = (new SearchBuilder(
            $this->app['db']->table('users'),
            $this->fuzzySearch
        ))
            ->search('%')
            ->searchIn(['name'])
            ->extended()
            ->get();

        // A literal '%' LIKE pattern '%\%%' should match only rows containing '%', not all rows.
        $this->assertLessThan(
            $this->baseline,
            $results->count(),
            'Extended-path FuzzyTerm with literal % must not match all rows (wildcard not escaped)'
        );

        // Same check for '_' which as an unescaped wildcard matches any single character.
        $results = (new SearchBuilder(
            $this->app['db']->table('users'),
            $this->fuzzySearch
        ))
            ->search('_')
            ->searchIn(['name'])
            ->extended()
            ->get();

        $this->assertLessThan(
            $this->baseline,
            $results->count(),
            'Extended-path FuzzyTerm with literal _ must not match all rows (wildcard not escaped)'
        );
    }

    public function test_column_name_from_searchin_does_not_allow_raw_sql(): void
    {
        // searchIn accepts developer-supplied column names.
        // This test documents the current behavior and ensures no data corruption.

        try {
            (new SearchBuilder(
                $this->app['db']->table('users'),
                $this->fuzzySearch
            ))
                ->search('john')
                ->searchIn(['name; DROP TABLE users --'])
                ->get();
        } catch (\Exception) {
            // Exception is acceptable — PDO rejected the bad column name
        }

        // Table must still exist with same count
        $count = $this->app['db']->table('users')->count();
        $this->assertEquals($this->baseline, $count);
    }

    /** `$` alone matches before a final newline: every column check anchors at the very end. */
    #[DataProvider('trailingNewlineColumns')]
    public function test_a_column_with_a_trailing_newline_is_rejected(string $column): void
    {
        $entryPoints = [
            'searchIn'   => fn () => (new SearchBuilder($this->app['db']->table('users'), $this->fuzzySearch))->searchIn([$column]),
            'facet'      => fn () => (new SearchBuilder($this->app['db']->table('users'), $this->fuzzySearch))->facet($column),
            'whereFuzzy' => fn () => $this->app['db']->table('users')->whereFuzzy($column, 'john'),
            'federated'  => fn () => FederatedSearch::across([])->searchIn([$column]),
        ];

        $accepted = [];
        foreach ($entryPoints as $label => $call) {
            try {
                $call();
                $accepted[] = $label;
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $accepted, 'accepted ' . json_encode($column));
    }

    public static function trailingNewlineColumns(): array
    {
        return ['bare' => ["name\n"], 'qualified' => ["users.name\n"]];
    }

    /**
     * orderByFuzzy() writes its column into raw SQL — on SQLite a bare column goes in exactly as
     * written — so it takes whereFuzzy()'s column check: an app that passes a request's sort
     * field through must get an exception, never an injected subquery, on every grammar.
     */
    #[DataProvider('injectedOrderColumns')]
    public function test_order_by_fuzzy_rejects_an_injected_column(string $column): void
    {
        $calls = [
            'eloquent' => fn () => User::query()->orderByFuzzy($column, 'john'),
            'facade'   => fn () => $this->fuzzySearch->applyFuzzyOrder($this->app['db']->table('users'), $column, 'john'),
        ];
        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            if ($this->fakeDriverAvailable($driver)) {
                $calls[$driver] = fn () => $this->fakeConnectionTable($driver, 'users')->orderByFuzzy($column, 'john');
            }
        }

        $accepted = [];
        foreach ($calls as $label => $call) {
            try {
                $accepted[$label] = $call()->toSql();
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame([], $accepted, 'accepted ' . json_encode($column));
    }

    public static function injectedOrderColumns(): array
    {
        return [
            'subquery'  => ['name) , (select 1'],
            'quote'     => ["name'"],
            'semicolon' => ['name; DROP TABLE users'],
            'newline'   => ["name\n"],
            'comment'   => ['name --'],
        ];
    }

    public function test_order_by_fuzzy_still_takes_a_bare_or_qualified_column(): void
    {
        foreach (['name', 'users.name'] as $column) {
            foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
                if ($this->fakeDriverAvailable($driver)) {
                    $this->assertStringContainsString('name', $this->fakeConnectionTable($driver, 'users')->orderByFuzzy($column, 'john')->toSql(), $driver);
                }
            }

            $this->assertCount($this->baseline, User::query()->orderByFuzzy($column, 'john', 'desc')->get(), $column);
        }
    }
}
