<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Drivers\LevenshteinDriver;
use Ashiqfardus\LaravelFuzzySearch\Drivers\SoundexDriver;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Query\AstCompiler;
use Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * Generates SQL for each driver name WITHOUT executing it (toSql() only), so this
 * runs on any local database and pins the dialect branches for all five drivers.
 */
class DriverDialectSqlTest extends TestCase
{
    use FakesDriverConnections;

    private function config(array $extra = []): array
    {
        return array_merge(config('fuzzy-search'), $extra);
    }

    public function test_soundex_uses_native_function_on_mysql_and_mariadb(): void
    {
        foreach (['mysql', 'mariadb'] as $driver) {
            $sql = strtolower((new SoundexDriver($this->config(), $driver))
                ->apply($this->app['db']->table('users'), 'name', 'john')
                ->toSql());

            $this->assertStringContainsString('soundex(substring_index(`name`', $sql, $driver);
            $this->assertStringNotContainsString(' like ', $sql, $driver);
        }
    }

    public function test_soundex_falls_back_to_like_on_sqlite_and_sqlsrv(): void
    {
        foreach (['sqlite', 'sqlsrv'] as $driver) {
            $sql = strtolower((new SoundexDriver($this->config(), $driver))
                ->apply($this->app['db']->table('users'), 'name', 'john')
                ->toSql());

            $this->assertStringContainsString('like', $sql, $driver);
            $this->assertStringNotContainsString('soundex(', $sql, $driver);
        }
    }

    public function test_levenshtein_native_udf_is_used_on_mariadb_when_enabled(): void
    {
        $config = $this->config(['use_native_functions' => true]);

        foreach (['mysql', 'mariadb'] as $driver) {
            $sql = strtolower((new LevenshteinDriver($config, $driver))
                ->apply($this->app['db']->table('users'), 'name', 'john')
                ->toSql());

            $this->assertStringContainsString('levenshtein(`name`, ?) <= ?', $sql, $driver);
        }
    }

    public function test_ast_compiler_quotes_identifiers_per_driver(): void
    {
        $expected = [
            'mysql'   => 'lower(`name`)',
            'mariadb' => 'lower(`name`)',
            'pgsql'   => 'lower("name")',
            'sqlsrv'  => 'lower([name])',
            'sqlite'  => 'lower(name)',
        ];

        $ast = (new ExtendedQueryParser())->parse((new Lexer())->tokenize('=John'));

        foreach ($expected as $driver => $fragment) {
            $builder = $this->app['db']->table('users');
            (new AstCompiler($driver))->compile($ast, $builder, ['name']);

            $this->assertStringContainsString($fragment, strtolower($builder->toSql()), $driver);
        }
    }

    public function test_relevance_ordering_quotes_identifiers_per_driver(): void
    {
        $expected = [
            'mysql'   => 'case when `name` = ?',
            'mariadb' => 'case when `name` = ?',
            'pgsql'   => 'case when "name" = ?',
            'sqlsrv'  => 'case when [name] = ?',
            'sqlite'  => 'case when name = ?',
        ];

        $checked = 0;

        foreach ($expected as $driver => $fragment) {
            if (!$this->fakeDriverAvailable($driver)) {
                continue;
            }

            $sql = strtolower((new SearchBuilder($this->fakeConnectionTable($driver, 'users'), app(FuzzySearch::class)))
                ->search('john')
                ->searchIn(['name'])
                ->using('like')
                ->toSql());

            $this->assertStringContainsString($fragment, $sql, $driver);

            if ($driver === 'pgsql') {
                $this->assertStringContainsString('ilike', $sql, $driver);
            }

            $checked++;
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'at least mysql, pgsql, sqlsrv and sqlite must be asserted');
    }

    public function test_order_by_fuzzy_uses_the_drivers_position_function(): void
    {
        $expected = [
            'mysql'   => 'locate(?, `name`)',
            'mariadb' => 'locate(?, `name`)',
            'pgsql'   => 'position(? in "name")',
            'sqlite'  => 'instr(name, ?)',
            'sqlsrv'  => 'charindex(?, [name])',
        ];

        $checked = 0;

        foreach ($expected as $driver => $fragment) {
            if (!$this->fakeDriverAvailable($driver)) {
                continue;
            }

            $sql = strtolower(app(FuzzySearch::class)
                ->applyFuzzyOrder($this->fakeConnectionTable($driver, 'users'), 'name', 'john')
                ->toSql());

            $this->assertStringContainsString($fragment, $sql, $driver);

            $checked++;
        }

        $this->assertGreaterThanOrEqual(4, $checked, 'at least mysql, pgsql, sqlsrv and sqlite must be asserted');
    }
}
