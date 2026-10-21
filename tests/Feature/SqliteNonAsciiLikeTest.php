<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

/**
 * SQLite's LIKE folds ASCII letters only, so 'лев' never found "Лев Толстой" there while MySQL did.
 * For a non-ASCII term on SQLite the LIKE path reads the column through the package's own
 * fuzzy_lower() and binds the pattern lower-cased; ASCII terms and the other databases are
 * unchanged.
 */
class SqliteNonAsciiLikeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert([
            ['name' => 'Лев Толстой', 'email' => 'lt@x.test', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'ΑΘΗΝΑ Παπαδοπούλου', 'email' => 'ap@x.test', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Émile Zola', 'email' => 'ez@x.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function names(string $term, string $algorithm = 'simple'): array
    {
        return User::searchOn(User::query(), $term, ['name'])->using($algorithm)->get()->pluck('name')->all();
    }

    public function test_a_cyrillic_term_finds_the_name_in_any_case(): void
    {
        foreach (['лев', 'ЛЕВ', 'Лев'] as $term) {
            $this->assertContains('Лев Толстой', $this->names($term), $term);
        }
    }

    public function test_greek_and_accented_latin_capitals_match_too(): void
    {
        $this->assertContains('ΑΘΗΝΑ Παπαδοπούλου', $this->names('αθηνα'));
        $this->assertContains('ΑΘΗΝΑ Παπαδοπούλου', $this->names('ΠΑΠΑΔΟΠ'));
        $this->assertContains('Émile Zola', $this->names('émile'));
    }

    public function test_every_like_algorithm_and_the_count_agree(): void
    {
        foreach (['simple', 'fuzzy', 'levenshtein'] as $algorithm) {
            $search = fn () => User::searchOn(User::query(), 'лев', ['name'])->using($algorithm);

            $this->assertContains('Лев Толстой', $search()->get()->pluck('name')->all(), $algorithm);
            $this->assertSame($search()->get()->count(), $search()->count(), $algorithm);
            $this->assertSame('Лев Толстой', $search()->first()?->name, $algorithm);
        }
    }

    public function test_the_extended_path_and_a_negated_term_fold_too(): void
    {
        $this->assertContains('Лев Толстой', User::searchOn(User::query(), '', ['name'])->extended('ЛЕВ')->get()->pluck('name')->all());
        $this->assertNotContains('Лев Толстой', User::searchOn(User::query(), '', ['name'])->extended('Zola | !ЛЕВ')->get()->pluck('name')->all());
    }

    public function test_an_ascii_search_has_no_fuzzy_lower_in_its_sql(): void
    {
        $this->assertStringNotContainsString('fuzzy_lower', User::searchOn(User::query(), 'john', ['name'])->using('simple')->toSql());
        $this->assertStringNotContainsString('fuzzy_lower', User::searchOn(User::query(), 'john', ['name'])->using('simple')->orderBy('name')->toSql());
    }

    public function test_sqlite_sql_reads_the_column_through_fuzzy_lower_for_the_where_and_the_ranking(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('fuzzy_lower() is SQLite only.');
        }

        $sql = User::searchOn(User::query(), 'лев', ['name'])->using('simple')->toSql();

        $this->assertStringContainsString('where (fuzzy_lower(', $sql);
        $this->assertStringContainsString('order by', $sql);
        $this->assertGreaterThan(1, substr_count($sql, 'fuzzy_lower('));
        $this->assertSame('лев', DB::selectOne('select fuzzy_lower(?) as v', ['ЛЕВ'])->v);
        // SQLite's own lower() is not replaced.
        $this->assertSame('ЛЕВ', DB::selectOne('select lower(?) as v', ['ЛЕВ'])->v);
    }

    public function test_every_sqlite_connection_gets_the_function(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('fuzzy_lower() is SQLite only.');
        }

        config(['database.connections.second_sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $second = DB::connection('second_sqlite');
        $second->statement('create table people (name text)');
        $second->table('people')->insert([['name' => 'Лев'], ['name' => 'Anna']]);

        $first = User::searchOn(User::query(), 'ЛЕВ', ['name'])->using('simple')->get()->pluck('name')->all();
        $builder = new \Ashiqfardus\LaravelFuzzySearch\SearchBuilder($second->table('people'), app(\Ashiqfardus\LaravelFuzzySearch\FuzzySearch::class));

        $this->assertSame(['Лев'], $builder->search('лев')->searchIn(['name'])->using('simple')->get()->pluck('name')->all());
        $this->assertContains('Лев Толстой', $first);
    }
}
