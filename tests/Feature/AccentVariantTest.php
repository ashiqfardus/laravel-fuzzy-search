<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../TestModels.php';

/**
 * unicode.accent_insensitive (shipped true) searches both forms: the typed term and its folded
 * form, like a synonym. It used to replace the typed term, so "Müller" searched "Muller" and missed
 * "Zoë Müller" wherever the column is not folded (SQLite, PostgreSQL, SQL Server). On PostgreSQL
 * with use_native_functions, unaccent() is OR'd beside the algorithm only on an explicit opt-in.
 * tests/TestCase.php ships the key false, so every test here sets it.
 */
class AccentVariantTest extends TestCase
{
    use FakesDriverConnections;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fuzzy-search.unicode.accent_insensitive' => true]);

        DB::table('users')->insert([
            ['name' => 'Zoë Müller', 'email' => 'zoe@example.com', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Muller', 'email' => 'muller@example.com', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** An Eloquent source (first() returns a model), with no $searchable configuration applied. */
    private function builder(): SearchBuilder
    {
        return new SearchBuilder(User::query(), app(FuzzySearch::class));
    }

    public static function algorithms(): array
    {
        return ['simple' => ['simple'], 'like' => ['like'], 'fuzzy' => ['fuzzy'], 'levenshtein' => ['levenshtein'], 'trigram' => ['trigram']];
    }

    #[DataProvider('algorithms')]
    public function test_an_accented_term_finds_its_own_row_and_the_folded_one(string $algorithm): void
    {
        $search = fn () => $this->builder()->search('Müller')->searchIn(['name'])->using($algorithm);

        $names = $search()->get()->pluck('name')->all();
        $this->assertContains('Zoë Müller', $names, 'the typed form was not searched');
        $this->assertContains('Muller', $names, 'the folded form was not searched');

        $this->assertSame(count($names), $search()->count());
        $this->assertSame(count($names), $search()->paginate(50)->total());
        $this->assertEqualsCanonicalizing($names, collect($search()->simplePaginate(50)->items())->pluck('name')->all());
        $this->assertContains($search()->first()?->name, ['Zoë Müller', 'Muller']);

        $facets = $search()->facet('email')->getFacets()['email'];
        $this->assertArrayHasKey('zoe@example.com', $facets);
        $this->assertArrayHasKey('muller@example.com', $facets);
    }

    #[DataProvider('algorithms')]
    public function test_an_ascii_term_still_finds_its_row(string $algorithm): void
    {
        $this->assertContains('Muller', $this->builder()->search('Muller')->searchIn(['name'])->using($algorithm)->get()->pluck('name')->all());
    }

    public function test_an_extended_term_searches_both_forms(): void
    {
        $names = fn (string $query) => $this->builder()->search($query)->searchIn(['name', 'email'])->extended()->get()->pluck('name')->all();

        foreach (['Müller', 'name:Müller', '^Müller | Zoë', '~Müller'] as $query) {
            $this->assertContains('Zoë Müller', $names($query), $query);
            $this->assertContains('Muller', $names($query), $query);
        }

        $excluded = $names('(doe | müller | muller) !Müller');
        $this->assertNotContains('Zoë Müller', $excluded, 'a NOT must exclude the typed form');
        $this->assertNotContains('Muller', $excluded, 'a NOT must exclude the folded form too');
        $this->assertContains('John Doe', $excluded);
    }

    public function test_the_folded_form_is_highlighted_and_scored_as_a_match(): void
    {
        $results = $this->builder()->search('Müller')->searchIn(['name'])->using('like')->highlight()->get()->keyBy('name');

        $this->assertSame('Zoë <em>Müller</em>', $results['Zoë Müller']->_highlighted['name']);
        $this->assertSame('<em>Muller</em>', $results['Muller']->_highlighted['name']);

        // "Muller" is an exact match once accents are folded; "Zoë Müller" only contains the term.
        $this->assertSame(['Muller', 'Zoë Müller'], $results->keys()->all());
        $this->assertGreaterThan($results['Zoë Müller']->_score, $results['Muller']->_score);
    }

    public function test_the_macros_bind_the_typed_term_unchanged(): void
    {
        // The variant belongs to SearchBuilder; a macro searches the term it is given.
        $names = DB::table('users')->whereFuzzy('name', 'Müller', 'like')->pluck('name')->all();

        $this->assertContains('Zoë Müller', $names);
    }

    /**
     * An ASCII term has nothing to fold: no variant, so the SQL and bindings are exactly those of a
     * search with folding off, on every grammar.
     */
    public function test_an_ascii_term_compiles_to_the_same_sql_with_folding_on_or_off(): void
    {
        $shapes = [];
        foreach (['fuzzy', 'levenshtein', 'trigram', 'soundex', 'similar_text', 'simple', 'like'] as $algorithm) {
            $shapes["search {$algorithm}"] = fn (SearchBuilder $b) => $b->search('john')->searchIn(['name', 'email'])->using($algorithm);
            $shapes["accentInsensitive() {$algorithm}"] = fn (SearchBuilder $b) => $b->search('john')->searchIn(['name', 'email'])->using($algorithm)->accentInsensitive();
        }
        $shapes += [
            'tokenize all'       => fn (SearchBuilder $b) => $b->search('john doe')->searchIn(['name'])->tokenize()->matchAll(),
            'tokenize any'       => fn (SearchBuilder $b) => $b->search('john doe')->searchIn(['name'])->using('like')->tokenize(),
            'synonyms'           => fn (SearchBuilder $b) => $b->search('john')->searchIn(['name'])->using('like')->withSynonyms(['john' => ['jon']]),
            'stop words'         => fn (SearchBuilder $b) => $b->search('the john')->searchIn(['name'])->ignoreStopWords(['the']),
            'no relevance'       => fn (SearchBuilder $b) => $b->search('john')->searchIn(['name'])->withRelevance(false),
            'filter sort stable' => fn (SearchBuilder $b) => $b->search('john')->searchIn(['name'])->filter('id', '>', 1)->orderBy('name')->stableRanking(),
            'extended exact'     => fn (SearchBuilder $b) => $b->search('=john ^doe')->searchIn(['name', 'email'])->extended(),
            'extended typo or'   => fn (SearchBuilder $b) => $b->search('~john | smith')->searchIn(['name', 'email'])->extended(),
            'extended not field' => fn (SearchBuilder $b) => $b->search('!john name:doe doe$ \'x')->searchIn(['name', 'email'])->extended(),
        ];

        $checked = 0;
        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $dialect) {
            if (!$this->fakeDriverAvailable($dialect)) {
                continue;
            }

            foreach ($shapes as $name => $shape) {
                $compile = function (bool $fold) use ($dialect, $shape): array {
                    config(['fuzzy-search.unicode.accent_insensitive' => $fold]);
                    $builder = $shape(new SearchBuilder($this->fakeConnectionTable($dialect, 'users'), app(FuzzySearch::class)));

                    return [$builder->toSql(), $builder->getBindings()];
                };

                $this->assertSame($compile(false), $compile(true), "{$dialect}: {$name}");
                $checked++;
            }
        }

        $this->assertGreaterThanOrEqual(4 * 23, $checked);
    }

    public function test_the_global_key_never_emits_unaccent_on_postgresql_with_native_functions(): void
    {
        config(['fuzzy-search.use_native_functions' => true]);

        foreach (['fuzzy', 'levenshtein', 'trigram', 'soundex', 'similar_text', 'simple'] as $algorithm) {
            $sql = (new SearchBuilder($this->fakeConnectionTable('pgsql', 'users'), app(FuzzySearch::class)))
                ->search('Müller')->searchIn(['name'])->using($algorithm)->toSql();

            $this->assertStringNotContainsString('unaccent(', $sql, $algorithm);
        }
    }

    public function test_an_explicit_opt_in_ors_unaccent_beside_the_algorithm_on_postgresql_with_native_functions(): void
    {
        config(['fuzzy-search.use_native_functions' => true]);

        $sql = (new SearchBuilder($this->fakeConnectionTable('pgsql', 'users'), app(FuzzySearch::class)))
            ->search('Muller')->searchIn(['name'])->using('trigram')->accentInsensitive()->toSql();

        $this->assertStringContainsString('similarity("name", ?) > ?', $sql, 'the algorithm was replaced');
        $this->assertStringContainsString('or unaccent("name") ILIKE unaccent(?)', $sql);

        // The model's $searchable['accent_insensitive'] and a preset opt in the same way.
        $this->fakeConnectionTable('pgsql', 'users');
        $model = AccentOptInUser::searchOn(AccentOptInUser::on('fake_pgsql'), 'Muller')->toSql();
        $this->assertStringContainsString('similarity("name", ?) > ?', $model, '$searchable');
        $this->assertStringContainsString('unaccent(', $model, '$searchable');

        $preset = (new SearchBuilder($this->fakeConnectionTable('pgsql', 'users'), app(FuzzySearch::class)))
            ->search('Muller')->preset('users')->toSql();
        $this->assertStringContainsString('unaccent(', $preset, 'preset');
    }

    public function test_the_explicit_opt_in_emits_one_unaccent_alternative_per_accent_free_form(): void
    {
        config(['fuzzy-search.use_native_functions' => true]);

        $sql = fn (array $synonyms = []) => (new SearchBuilder($this->fakeConnectionTable('pgsql', 'users'), app(FuzzySearch::class)))
            ->search('Müller')->searchIn(['name', 'email'])->using('trigram')->withSynonyms($synonyms)->accentInsensitive()->toSql();

        // "Müller" and its folded variant "Muller" unaccent alike: one alternative per column.
        $this->assertSame(1, substr_count($sql(), 'unaccent("name")'));
        $this->assertSame(1, substr_count($sql(), 'unaccent("email")'));

        // A synonym with another accent-free form gets its own.
        $this->assertSame(2, substr_count($sql(['müller' => ['miller']]), 'unaccent("name")'));
    }

    /** ER-49 × Q15: the unaccent() alternative is a similar_text match too, so it carries the min_percentage bound. */
    public function test_the_unaccent_alternative_carries_the_similar_text_length_bound(): void
    {
        config(['fuzzy-search.use_native_functions' => true]);

        $sql = fn () => $this->fakeConnectionTable('pgsql', 'users')->whereFuzzy('name', 'john', 'similar_text', ['accent_insensitive' => true]);

        $this->assertStringContainsString('or (unaccent("name") ILIKE unaccent(?) and CHAR_LENGTH("name") <= ?)', $sql()->toSql());
        $this->assertSame(['%john%', 7, '%john%', 7], $sql()->getBindings());

        config(['fuzzy-search.similar_text.min_percentage' => 0]);
        $this->assertStringContainsString('or unaccent("name") ILIKE unaccent(?))', $sql()->toSql(), 'min_percentage 0: no bound on either side');
        $this->assertStringNotContainsString('<= ?', $sql()->toSql());
    }

    public function test_postgresql_explicit_opt_in_keeps_the_similar_text_bound(): void
    {
        $this->requirePostgresExtensions(['unaccent']);

        config(['fuzzy-search.use_native_functions' => true]);

        // Every seeded "john" name is longer than 7 characters, the 70% bound for a 4-character term.
        $search = fn () => $this->builder()->search('john')->searchIn(['name'])->using('similar_text')->accentInsensitive();
        $this->assertSame([], $search()->get()->pluck('name')->all());
        $this->assertSame([], DB::table('users')->whereFuzzy('name', 'john', 'similar_text', ['accent_insensitive' => true])->pluck('name')->all());

        config(['fuzzy-search.similar_text.min_percentage' => 0]);
        $this->assertEqualsCanonicalizing(['John Doe', 'Johnny Bravo', 'Bob Johnson'], $search()->get()->pluck('name')->all());
    }

    public function test_postgresql_native_functions_run_without_the_unaccent_extension(): void
    {
        $this->requirePostgresExtensions(['pg_trgm', 'fuzzystrmatch']);

        try {
            DB::statement('DROP EXTENSION IF EXISTS unaccent');
        } catch (\Throwable $e) {
            $this->markTestSkipped('Cannot drop the unaccent extension here: ' . $e->getMessage());
        }

        config(['fuzzy-search.use_native_functions' => true]);

        foreach (['trigram', 'levenshtein', 'soundex'] as $algorithm) {
            $names = $this->builder()->search('Müller')->searchIn(['name'])->using($algorithm)->get()->pluck('name')->all();

            $this->assertContains('Zoë Müller', $names, $algorithm);
        }
    }

    public function test_postgresql_explicit_opt_in_keeps_typo_tolerance_and_folds_the_column(): void
    {
        $this->requirePostgresExtensions(['pg_trgm', 'unaccent']);

        config(['fuzzy-search.use_native_functions' => true]);

        $search = fn (string $term) => $this->builder()->search($term)->searchIn(['name'])->using('trigram')->accentInsensitive()->get()->pluck('name')->all();

        $this->assertContains('Muller', $search('Mullerr'), 'the typo match was lost');
        $this->assertContains('Zoë Müller', $search('Muller'), 'unaccent() did not fold the column');
    }

    public function test_postgresql_explicit_opt_in_does_not_leak_past_a_where_or_a_filter(): void
    {
        $this->requirePostgresExtensions(['pg_trgm', 'unaccent']);

        config(['fuzzy-search.use_native_functions' => true]);

        $before = (new SearchBuilder(User::query()->where('email', 'muller@example.com'), app(FuzzySearch::class)))
            ->search('Müller')->searchIn(['name'])->using('trigram')->accentInsensitive()->get()->pluck('name')->all();
        $this->assertSame(['Muller'], $before, 'a where() applied before the search');

        $filtered = $this->builder()->search('Müller')->searchIn(['name'])->using('trigram')->accentInsensitive()
            ->filter('email', '=', 'zoe@example.com')->get()->pluck('name')->all();
        $this->assertSame(['Zoë Müller'], $filtered, 'a filter()');
    }

    /** @param string[] $extensions */
    private function requirePostgresExtensions(array $extensions): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (CI runs it); native functions and unaccent() are PostgreSQL features.');
        }

        foreach ($extensions as $extension) {
            try {
                DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
            } catch (\Throwable $e) {
                $this->markTestSkipped("The {$extension} extension cannot be created here: " . $e->getMessage());
            }
        }
    }
}

/** Opts in to accent insensitivity through its own $searchable. */
class AccentOptInUser extends \Illuminate\Database\Eloquent\Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table = 'users';

    protected array $searchable = ['columns' => ['name' => 1], 'algorithm' => 'trigram', 'accent_insensitive' => true];
}
