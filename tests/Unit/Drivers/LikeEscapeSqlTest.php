<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Drivers;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * ER-41/ER-42, pinned per database without a server (toSql() on fake connections): SQLite and
 * SQL Server have no default LIKE escape character, so every LIKE the package writes there
 * carries ESCAPE '!'; MySQL, MariaDB and PostgreSQL default to backslash and get no clause.
 */
class LikeEscapeSqlTest extends TestCase
{
    use FakesDriverConnections;

    /** @return array<string, string> label => one SQL statement from each LIKE site */
    private function statements(string $driver): array
    {
        $table  = fn () => $this->fakeConnectionTable($driver, 'users');
        $search = fn () => new SearchBuilder($table(), app(FuzzySearch::class));
        $sql    = [];

        foreach (['simple', 'fuzzy', 'levenshtein', 'trigram', 'similar_text', 'soundex'] as $algorithm) {
            $sql[$algorithm] = $search()->search('snake_case')->searchIn(['name', 'users.email'])->using($algorithm)->toSql();
        }
        $sql['extended'] = $search()->searchIn(['name'])->extended("'a_b ^a_b a_b\$ !a_b ~a_bc a_b")->toSql();
        $sql['suggest']  = \Closure::bind(
            fn () => $this->suggestCandidateQuery('a\\_b')->toSql(),
            $search()->search('a_b')->searchIn(['name']),
            SearchBuilder::class
        )();
        $sql['macros']   = $table()->whereFuzzy('name', 'a_b', 'like')->orWhereFuzzy('email', 'a_b', 'fuzzy')->toSql();
        // The dictionary's LIKE branch: always on PostgreSQL and SQL Server; on SQLite and MySQL
        // only for a prefix whose last character has no successor.
        $sql['prefix']   = $this->capturedPrefixQuery($driver, "a_\u{10FFFF}");

        return $sql;
    }

    /** The statement TermExpander::prefix() sends, captured before it reaches the (absent) server. */
    private function capturedPrefixQuery(string $driver, string $prefix): string
    {
        $this->fakeConnectionTable($driver, 'users');
        $name     = 'fake_' . $driver;
        $default  = config('database.default');
        $captured = '';

        config(['database.default' => $name]);
        DB::connection($name)->beforeExecuting(function (string $query) use (&$captured) {
            $captured = $query;
            throw new \LogicException('captured');
        });

        try {
            app(TermExpander::class)->prefix($prefix, 5);
        } catch (\LogicException) {
            // expected: the statement was captured
        } finally {
            config(['database.default' => $default]);
            DB::purge($name);
        }

        return $captured;
    }

    public function test_sqlite_and_sql_server_give_every_like_an_escape_clause(): void
    {
        foreach (['sqlite', 'sqlsrv'] as $driver) {
            foreach ($this->statements($driver) as $label => $sql) {
                $sql = strtolower($sql);

                $this->assertGreaterThan(0, substr_count($sql, ' like ?'), "{$driver} {$label}: {$sql}");
                $this->assertSame(
                    substr_count($sql, ' like ?'),
                    substr_count($sql, " like ? escape '!'"),
                    "{$driver} {$label}: {$sql}"
                );
            }
        }
    }

    public function test_mysql_mariadb_and_postgresql_send_no_escape_clause(): void
    {
        foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
            if (!$this->fakeDriverAvailable($driver)) {
                continue; // mariadb on Laravel 10
            }

            foreach ($this->statements($driver) as $label => $sql) {
                $this->assertStringNotContainsString('escape', strtolower($sql), "{$driver} {$label}");
            }
        }
    }

    public function test_the_term_is_escaped_for_like_on_each_database(): void
    {
        // With backslash: \ % _. With ! (SQLite, SQL Server): ! % _, and [ on SQL Server, the one
        // database where it is a wildcard; a backslash is ordinary there.
        $expected = [
            'sqlite'  => '%a\\b!%!_[c]!!%',
            'mysql'   => '%a\\\\b\\%\\_[c]!%',
            'mariadb' => '%a\\\\b\\%\\_[c]!%',
            'pgsql'   => '%a\\\\b\\%\\_[c]!%',
            'sqlsrv'  => '%a\\b!%!_![c]!!%',
        ];

        foreach ($expected as $driver => $pattern) {
            if (!$this->fakeDriverAvailable($driver)) {
                continue;
            }

            $builder = (new SearchBuilder($this->fakeConnectionTable($driver, 'users'), app(FuzzySearch::class)))
                ->search('a\\b%_[c]!')->searchIn(['name'])->using('simple')->withRelevance(false);

            $this->assertSame([$pattern], $builder->getBindings(), $driver);
        }
    }

    public function test_to_raw_sql_on_sqlite_shows_every_binding(): void
    {
        if (!method_exists(\Illuminate\Database\Query\Builder::class, 'toRawSql')) {
            $this->markTestSkipped('Query\Builder::toRawSql() arrived in Laravel 10.15.');
        }

        // Laravel's raw-SQL renderer reads \' as an escaped quote, so ESCAPE '\' hid every later
        // binding behind a '?'. ESCAPE '!' is an ordinary one-character string.
        $raw = $this->fakeConnectionTable('sqlite', 'users')
            ->whereFuzzy('name', '50%', 'like')
            ->orWhereFuzzy('email', 'x_y', 'like')
            ->toRawSql();

        $this->assertStringNotContainsString('?', $raw);
        $this->assertStringContainsString("'%50!%%' ESCAPE '!'", $raw);
        $this->assertStringContainsString("'%x!_y%' ESCAPE '!'", $raw);
    }
}
