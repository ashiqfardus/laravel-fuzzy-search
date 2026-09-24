<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../TestModels.php';

/**
 * A literal %, _, \ or ! in a search term matches itself on every database and every search path
 * (ER-41, ER-42, ER-43). SQLite and SQL Server have no default LIKE escape character, so the
 * package's backslash escapes were ordinary characters there and such a term matched nothing;
 * every database but PostgreSQL now escapes with ! under ESCAPE '!', so a ! in a term must be
 * escaped too (MySQL and MariaDB: see NoBackslashEscapesTest). MySQL, MariaDB and PostgreSQL read
 * "\s" as "s", so a backslash in a term was lost. Each case pairs the literal row with the
 * look-alike that an unescaped wildcard (or a lost character) matches.
 */
class LiteralLikeCharactersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['50% off', '500 off', 'snake_case', 'snakeXcase', 'back\\slash', 'backslash', 'wow! deal', 'wow deal'] as $i => $name) {
            $this->addUser($name, "literal{$i}@example.com");
        }
    }

    private function addUser(string $name, string $email): void
    {
        DB::table('users')->insert(['name' => $name, 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return string[] */
    private function names(iterable $results): array
    {
        return collect($results)->pluck('name')->all();
    }

    /** @return array<string, array{string, string, string}> term, the row it names, the look-alike */
    public static function literals(): array
    {
        return [
            'percent'    => ['50%', '50% off', '500 off'],
            'underscore' => ['snake_case', 'snake_case', 'snakeXcase'],
            'backslash'  => ['back\\slash', 'back\\slash', 'backslash'],
            'bang'       => ['wow!', 'wow! deal', 'wow deal'],
        ];
    }

    #[DataProvider('literals')]
    public function test_a_simple_search_matches_the_literal_row_only(string $term, string $literal, string $lookalike): void
    {
        $this->assertSame([$literal], $this->names(User::search($term)->using('simple')->get()));
    }

    #[DataProvider('literals')]
    public function test_every_like_algorithm_finds_the_literal_row(string $term, string $literal, string $lookalike): void
    {
        foreach (['simple', 'fuzzy', 'levenshtein', 'trigram', 'similar_text', 'soundex'] as $algorithm) {
            $names = $this->names(User::search($term)->using($algorithm)->get());

            $this->assertContains($literal, $names, "{$algorithm}: " . json_encode($names));
        }

        // The exact-substring algorithms have no typo tolerance to excuse the look-alike.
        foreach (['simple', 'similar_text'] as $algorithm) {
            $this->assertNotContains($lookalike, $this->names(User::search($term)->using($algorithm)->get()), $algorithm);
        }
    }

    /** User's $searchable algorithm is 'fuzzy', the shipped default_algorithm. */
    #[DataProvider('literals')]
    public function test_the_default_algorithm_ranks_the_literal_row_first(string $term, string $literal, string $lookalike): void
    {
        $names = $this->names(User::search($term)->get());

        $this->assertSame($literal, $names[0] ?? null, json_encode($names));
    }

    public function test_a_short_term_with_the_default_algorithm_matches_the_literal_row_only(): void
    {
        // Below typo_tolerance.min_word_length the fuzzy driver sends the term alone, so no
        // typo pattern can excuse "500 off".
        $this->assertSame(['50% off'], $this->names(User::search('50%')->get()));
    }

    /** @return array<string, array{string, string[]}> */
    public static function extendedQueries(): array
    {
        return [
            'plain term'           => ['50%', ['50% off']],
            'include (\')'         => ["'snake_case", ['snake_case']],
            'prefix (^)'           => ['^50%', ['50% off']],
            'suffix ($)'           => ['snake_case$', ['snake_case']],
            'exact (=)'            => ['=snake_case', ['snake_case']],
            'exclude (!)'          => ['off !50%', ['500 off']],
            'field scope'          => ['name:snake_case', ['snake_case']],
            'typo (~), short term' => ['~50%', ['50% off']],
            'backslash'            => ['back\\slash', ['back\\slash']],
            'backslash suffix'     => ['back\\slash$', ['back\\slash']],
            // A bare ! starts a NOT term, so a literal one is written inside a quoted phrase.
            'bang in a phrase'     => ['"wow!"', ['wow! deal']],
        ];
    }

    #[DataProvider('extendedQueries')]
    public function test_extended_operators_match_literal_characters(string $query, array $expected): void
    {
        $this->assertSame($expected, $this->names(User::search('')->extended($query)->get()));
    }

    #[DataProvider('literals')]
    public function test_the_query_builder_macros_match_the_literal_row_only(string $term, string $literal, string $lookalike): void
    {
        $users = fn () => DB::table('users');

        $this->assertSame([$literal], $users()->whereFuzzy('name', $term, 'like')->pluck('name')->all(), 'whereFuzzy');
        $this->assertSame([$literal], $users()->where('name', '=', 'nobody')->orWhereFuzzy('name', $term, 'like')->pluck('name')->all(), 'orWhereFuzzy');
        $this->assertSame([$literal], $users()->whereFuzzyMultiple(['name', 'email'], $term, 'similar_text')->pluck('name')->all(), 'whereFuzzyMultiple');
        $this->assertSame([$literal], User::whereFuzzy('name', $term, 'like')->pluck('name')->all(), 'Eloquent whereFuzzy');
        $this->assertContains($literal, $users()->fuzzySearch('name', $term)->pluck('name')->all(), 'fuzzySearch');
    }

    /** @return array<string, array{string, string}> prefix, its completion */
    public static function prefixes(): array
    {
        return [
            'percent'    => ['50%', '50% off'],
            'underscore' => ['snake_', 'snake_case'],
            'backslash'  => ['back\\', 'back\\slash'],
            'bang'       => ['wow!', 'wow! deal'],
        ];
    }

    #[DataProvider('prefixes')]
    public function test_the_suggestion_table_scan_completes_a_literal_prefix(string $prefix, string $completion): void
    {
        $this->assertSame([$completion], User::search($prefix)->suggestFrom('table')->suggest());
    }

    public function test_the_relevance_order_by_ranks_a_literal_prefix_match_first(): void
    {
        // One candidate, so the SQL ORDER BY alone picks the row. "save 50% now" is the older row;
        // only the prefix arm (LIKE '50\%%') lifts "50% off" above it.
        config(['fuzzy-search.max_candidates' => 1]);
        DB::table('users')->where('name', '50% off')->delete();
        $this->addUser('save 50% now', 'save@example.com');
        $this->addUser('50% off', 'off@example.com');

        $this->assertSame(['50% off'], $this->names(User::search('50%')->using('simple')->get()));
    }

    public function test_order_by_fuzzy_ranks_the_literal_row_first(): void
    {
        $names = DB::table('users')->whereIn('name', ['500 off', '50% off'])
            ->orderByFuzzy('name', '50%', 'desc')->pluck('name')->all();

        $this->assertSame(['50% off', '500 off'], $names);
    }

    public function test_a_literal_bracket_matches_on_sql_server(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlsrv') {
            $this->markTestSkipped('[ is a LIKE wildcard only on SQL Server.');
        }

        // Unescaped, [draft] is a character class: any row with a d, r, a, f or t.
        $this->addUser('[draft] notes', 'draft@example.com');
        $this->addUser('d notes', 'd@example.com');

        $this->assertSame(['[draft] notes'], $this->names(User::search('[draft]')->using('simple')->get()));
        $this->assertSame(['[draft] notes'], $this->names(User::search('')->extended('^[draft]')->get()));
        $this->assertSame(['[draft] notes'], DB::table('users')->whereFuzzy('name', '[draft]', 'like')->pluck('name')->all());
        $this->assertSame(['[draft]', '[draft] notes'], User::search('[dr')->suggestFrom('table')->suggest());
    }
}
