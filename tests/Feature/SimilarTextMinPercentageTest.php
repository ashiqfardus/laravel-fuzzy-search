<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../TestModels.php';

/**
 * similar_text.min_percentage (ruling ER-49). The driver matches LIKE '%term%', so the term is
 * contained in the value and similar_text(term, value) is 200·t / (t + v): the percentage is a
 * character-length bound on the column, CHAR_LENGTH(col) <= floor(t·(200 − p) / p), which SQL can
 * enforce on every path. It was never read in 2.0.
 */
class SimilarTextMinPercentageTest extends TestCase
{
    use FakesDriverConnections;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('users')->insert([
            ['name' => 'John', 'email' => 'john.solo@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Johnny', 'email' => 'johnny.solo@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** Every name containing "john": what 2.0 returned. */
    private const ALL_JOHNS = ['Bob Johnson', 'John', 'John Doe', 'Johnny', 'Johnny Bravo'];

    private function names(iterable $rows): array
    {
        $names = collect($rows)->pluck('name')->sort()->values()->all();

        return $names;
    }

    private function search(): SearchBuilder
    {
        return (new SearchBuilder(User::query(), app(FuzzySearch::class)))->search('john')->searchIn(['name'])->using('similar_text');
    }

    public function test_the_default_70_keeps_values_at_most_1_86_times_the_term(): void
    {
        // "john" (4): "John" 100%, "Johnny" 80%, "John Doe" 66.7%, "Bob Johnson" 53.3%
        $this->assertSame(['John', 'Johnny'], $this->names($this->search()->get()));
        $this->assertSame(2, $this->search()->count());
        $this->assertSame(2, $this->search()->paginate(10)->total());
        $this->assertSame(['John', 'Johnny'], $this->names($this->search()->simplePaginate(10)->items()));
    }

    public function test_zero_or_null_restores_the_2_0_results(): void
    {
        $this->assertSame(self::ALL_JOHNS, $this->names($this->search()->options(['min_percentage' => 0])->get()));
        $this->assertSame(self::ALL_JOHNS, $this->names($this->search()->options(['min_percentage' => null])->get()));

        config(['fuzzy-search.similar_text.min_percentage' => 0]);
        $this->assertSame(self::ALL_JOHNS, $this->names($this->search()->get()));
    }

    public function test_a_per_call_option_overrides_the_config(): void
    {
        config(['fuzzy-search.similar_text.min_percentage' => 0]);

        // 90%: v <= 4·110/90 = 4.9 → only "John"
        $this->assertSame(['John'], $this->names($this->search()->options(['min_percentage' => 90])->get()));
        $this->assertSame(['John'], $this->names(DB::table('users')->whereFuzzy('name', 'john', 'similar_text', ['min_percentage' => 90])->get()));
    }

    public function test_the_macros_enforce_it(): void
    {
        $this->assertSame(['John', 'Johnny'], $this->names(DB::table('users')->whereFuzzy('name', 'john', 'similar_text')->get()));
        $this->assertSame(['John', 'Johnny'], $this->names(User::query()->whereFuzzyMultiple(['name'], 'john', 'similar_text')->get()));
        $this->assertSame(self::ALL_JOHNS, $this->names(DB::table('users')->whereFuzzy('name', 'john', 'similar_text', ['min_percentage' => 0])->get()));
    }

    public function test_the_fuzzy_similar_scope_passes_its_min_percentage(): void
    {
        $this->assertSame(['John', 'Johnny'], $this->names(User::query()->fuzzySimilar('john', ['name'])->get()), 'no argument: the config default');
        $this->assertSame(['John'], $this->names(User::query()->fuzzySimilar('john', ['name'], 90)->get()));
        $this->assertSame(self::ALL_JOHNS, $this->names(User::query()->fuzzySimilar('john', ['name'], 50)->get()), '50%: v <= 12');
        $this->assertSame(self::ALL_JOHNS, $this->names(User::query()->fuzzySimilar('john', ['name'], 0)->get()), '0 disables it');
    }

    /** Ruling ER-59: under tokenize() the bound measures the whole term, not each token. */
    public function test_under_tokenize_the_bound_uses_the_whole_term_length(): void
    {
        $search = fn () => (new SearchBuilder(User::query(), app(FuzzySearch::class)))->search('john doe')->searchIn(['name'])->using('similar_text')->tokenize();

        // "john doe" (8): v <= floor(8·130/70) = 14, for the "john" and "doe" tokens alike.
        $this->assertContains('John Doe', $this->names($search()->get()));
        $this->assertContains('Johnny Bravo', $this->names($search()->get()));
        $this->assertSame([14, 14], array_values(array_filter($search()->withRelevance(false)->getBindings(), 'is_int')));

        $this->assertSame(['John Doe'], $this->names($search()->matchAll()->get()));
    }

    public function test_lengths_are_counted_in_characters_not_bytes(): void
    {
        DB::table('users')->insert([
            ['name' => '東京都', 'email' => 'tokyo@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => '東京都庁', 'email' => 'tocho@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Zoë Müller', 'email' => 'zoe@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // "東京" (2 characters): v <= floor(2·130/70) = 3. "東京都" is 3 characters but 9 bytes.
        $this->assertSame(['東京都'], $this->names(DB::table('users')->whereFuzzy('name', '東京', 'similar_text')->get()));

        // "müller" (6): v <= 11. "Zoë Müller" is 10 characters but 12 bytes.
        $this->assertSame(['Zoë Müller'], $this->names(DB::table('users')->whereFuzzy('name', 'müller', 'similar_text')->get()));
    }

    /** The bound names the column exactly as the LIKE beside it does: DbDialect::column(), with a table prefix and a qualified column. */
    public function test_the_bound_and_the_like_write_the_column_the_same_way(): void
    {
        $checked = 0;
        foreach (['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv'] as $dialect) {
            if (!$this->fakeDriverAvailable($dialect)) {
                continue;
            }

            $query  = $this->fakeConnectionTable($dialect, 'users', 'pre_');
            $column = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::column($query, 'users.name', $dialect);
            $sql    = $query->whereFuzzy('users.name', 'john', 'similar_text')->toSql();

            $this->assertStringContainsString("({$column} ", $sql, "{$dialect}: the LIKE");
            $this->assertMatchesRegularExpression('/(LENGTH|CHAR_LENGTH|LEN)\((CAST\()?' . preg_quote($column, '/') . '/', $sql, "{$dialect}: the bound");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(4, $checked);
    }

    public function test_the_bound_is_a_character_length_on_every_grammar(): void
    {
        $expected = [
            'mysql'   => 'CHAR_LENGTH(`name`) <= ?',
            'mariadb' => 'CHAR_LENGTH(`name`) <= ?',
            'pgsql'   => 'CHAR_LENGTH("name") <= ?',
            'sqlite'  => 'LENGTH("name") <= ?',
            // LEN() alone ignores trailing spaces; the cast makes it work on int/decimal and legacy text/ntext (ER-54)
            'sqlsrv'  => "(LEN(CAST([name] AS NVARCHAR(MAX)) + N'x') - 1) <= ?",
        ];

        $checked = 0;
        foreach ($expected as $dialect => $bound) {
            if (!$this->fakeDriverAvailable($dialect)) {
                continue;
            }

            $query = $this->fakeConnectionTable($dialect, 'users')->whereFuzzy('name', 'john', 'similar_text')->where('id', '>', 0);

            $this->assertStringContainsString($bound, $query->toSql(), $dialect);
            $this->assertSame(['%john%', 7, 0], $query->getBindings(), $dialect);

            $none = $this->fakeConnectionTable($dialect, 'users')->whereFuzzy('name', 'john', 'similar_text', ['min_percentage' => 0]);
            $this->assertStringNotContainsString('<= ?', $none->toSql(), "{$dialect}: 0 must leave the 2.0 SQL");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(4, $checked);
    }
}
