<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccentScoredPerson extends Model
{
    use Searchable;

    protected $table   = 'accent_scored_people';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

/** A long text column: values past the 255-character scoring head. */
class AccentScoredDoc extends Model
{
    use Searchable;

    protected $table   = 'accent_scored_docs';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['body' => 1]];
}

/**
 * TF-8 (ruling ER-166). On MySQL/MariaDB's accent-insensitive collations (Laravel's
 * utf8mb4_unicode_ci) an unaccented term returns the accented rows, but PHP scoring and
 * highlighting compared the term as typed: "José Ramírez" scored as a near miss for "jose", below
 * "Joseph Smith", and "cafe" returned "Café Olé" with no tag. Where the SQL matched
 * accent-insensitively, scoring and highlighting now compare the accent-folded value too, mapped
 * back to the value's own bytes; elsewhere (SQLite, PostgreSQL without the unaccent opt-in, SQL
 * Server's accent-sensitive default) they compare as before, so the database stays the judge.
 */
class AccentInsensitiveMatchScoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('accent_scored_people');
        Schema::create('accent_scored_people', fn ($table) => [$table->id(), $table->string('name')]);
        foreach (['José Ramírez', 'Joseph Smith', 'Josefina Ortega', 'Jöhn Müller', 'Muller Smith', 'Café Olé', 'Ana Maria'] as $name) {
            DB::table('accent_scored_people')->insert(['name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('accent_scored_people');
        Schema::dropIfExists('accent_scored_docs');
        parent::tearDown();
    }

    /**
     * A search that folds values (sqlFoldsAccents()): MySQL/MariaDB under the suite's
     * utf8mb4_unicode_ci, PostgreSQL with the unaccent() opt-in; skipped elsewhere (CI runs them).
     *
     * @param  class-string<Model> $model
     * @return array<string, \Closure(string): iterable> path => the rows a term finds
     */
    private function foldingPaths(string $model): array
    {
        if ($this->dbDriver === 'pgsql') {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
            } catch (\Throwable $e) {
                $this->markTestSkipped('The unaccent extension cannot be created here: ' . $e->getMessage());
            }
            config(['fuzzy-search.use_native_functions' => true]);
        } elseif (!$this->foldsInSql()) {
            $this->markTestSkipped('MySQL/MariaDB (the suite\'s accent-insensitive collation) and PostgreSQL (unaccent) only; CI runs them.');
        }
        $search = fn (string $term) => $this->dbDriver === 'pgsql' ? $model::search($term)->using('simple')->accentInsensitive() : $model::search($term)->using('simple');

        $paths = [
            'get'            => fn (string $t) => $search($t)->take(50)->get(),
            'first'          => fn (string $t) => [$search($t)->first()],
            'paginate'       => fn (string $t) => $search($t)->paginate(50)->items(),
            'simplePaginate' => fn (string $t) => $search($t)->simplePaginate(50)->items(),
        ];
        if ($this->dbDriver !== 'pgsql') { // an extended() leaf takes no unaccent() alternative on PostgreSQL
            $paths['extended'] = fn (string $t) => $model::search('')->extended($t)->take(50)->get();
        }

        return $paths;
    }

    /** @return array<string, float> each row's first column => its _score */
    private function scores(iterable $rows, string $column): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->{$column}] = (float) $row->_score;
        }

        return $out;
    }

    private function foldsInSql(): bool
    {
        return in_array($this->dbDriver, ['mysql', 'mariadb'], true); // the suite's utf8mb4_unicode_ci
    }

    /** @return array<string, array{string, ?float, ?string}> name => [name, _score, _highlighted name] */
    private function rows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row->name] = [$row->name, $row->_score ?? null, $row->_highlighted['name'] ?? null];
        }

        return $out;
    }

    public function test_a_row_the_collation_matched_ranks_and_is_highlighted_as_a_match(): void
    {
        $paths = [
            'get'      => fn (string $t) => AccentScoredPerson::search($t)->using('simple')->highlight()->get(),
            'paginate' => fn (string $t) => AccentScoredPerson::search($t)->using('simple')->highlight()->paginate(10)->items(),
            'extended' => fn (string $t) => AccentScoredPerson::search('')->extended($t)->highlight()->get(),
        ];

        foreach ($paths as $label => $search) {
            $jose = $this->rows($search('jose'));
            $cafe = $this->rows($search('cafe'));

            if (!$this->foldsInSql()) {
                // The database compared accents: the accented rows are not matches, as documented.
                $this->assertArrayNotHasKey('José Ramírez', $jose, $label);
                $this->assertArrayNotHasKey('Café Olé', $cafe, $label);
                continue;
            }

            // A prefix match like "Joseph" and "Josefina", tagged on the value's own bytes.
            $this->assertSame(1.0, (float) $jose['José Ramírez'][1], "{$label} jose score");
            $this->assertSame('<em>José</em> Ramírez', $jose['José Ramírez'][2], "{$label} jose highlight");
            $this->assertSame('<em>Jose</em>ph Smith', $jose['Joseph Smith'][2], $label);
            $this->assertSame('<em>Café</em> Olé', $cafe['Café Olé'][2], "{$label} cafe highlight");
        }

        if ($this->foldsInSql()) {
            $row = collect(AccentScoredPerson::search('muller')->using('simple')->highlight()->get())->firstWhere('name', 'Jöhn Müller');
            $this->assertSame([['column' => 'name', 'value' => 'Jöhn Müller', 'indices' => [[6, 12]]]], $row->_matches);
            $this->assertSame('Jöhn <mark>Müller</mark>', \Ashiqfardus\LaravelFuzzySearch\SearchBuilder::renderHighlighted($row, 'name'));

            // A decomposed accent (e + U+0301) stays inside the tag of the letter it marks.
            DB::table('accent_scored_people')->insert(['name' => "Cafe\u{0301} Noir"]);
            $rows = $this->rows(AccentScoredPerson::search('cafe')->using('simple')->highlight()->get());
            $this->assertSame("<em>Cafe\u{0301}</em> Noir", $rows["Cafe\u{0301} Noir"][2]);
        }
    }

    /** Where the database compared accents, a row a typo pattern found is still scored and tagged as before. */
    public function test_where_the_database_compares_accents_nothing_is_folded(): void
    {
        if ($this->foldsInSql()) {
            $this->markTestSkipped('MySQL/MariaDB run the suite under an accent-insensitive collation; the other databases cover this.');
        }

        $row = collect(AccentScoredPerson::search('muller')->using('fuzzy')->highlight()->get())->firstWhere('name', 'Jöhn Müller');
        $this->assertNotNull($row, 'the typo pattern m_ller finds it');
        $this->assertSame('Jöhn Müller', $row->_highlighted['name']);
        $this->assertSame([], $row->_matches);
    }

    public function test_postgresql_folds_where_the_unaccent_opt_in_matched(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (CI runs it): the unaccent() alternative is a PostgreSQL feature.');
        }
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        } catch (\Throwable $e) {
            $this->markTestSkipped('The unaccent extension cannot be created here: ' . $e->getMessage());
        }
        config(['fuzzy-search.use_native_functions' => true]);

        $rows = $this->rows(AccentScoredPerson::search('cafe')->using('simple')->accentInsensitive()->highlight()->get());
        $this->assertSame('<em>Café</em> Olé', $rows['Café Olé'][2]);
        $this->assertSame(1.0, (float) $rows['Café Olé'][1]);

        // UF-2: unaccent() folds ł, which Accents::fold() does not: the row it found is a prefix match.
        DB::table('accent_scored_people')->insert([['name' => 'Łukasz Nowak'], ['name' => 'Lukaszewski Jan']]);
        $rows = $this->rows(AccentScoredPerson::search('lukasz')->using('simple')->accentInsensitive()->highlight()->get());
        $this->assertSame('<em>Łukasz</em> Nowak', $rows['Łukasz Nowak'][2]);
        $this->assertSame(1.0, (float) $rows['Łukasz Nowak'][1]);
    }

    /** The folded search maps each match back to whole characters of the value (MySQL/MariaDB path, called directly). */
    public function test_folded_offsets_cover_whole_characters(): void
    {
        $offsets = fn (string $value, string $term) => (new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder::class, 'foldedMatchOffsets'))
            ->invoke(AccentScoredPerson::search('x'), $value, $term, 0);
        $slices = fn (string $value, string $term) => array_map(fn (array $r) => substr($value, $r[0], $r[1] - $r[0] + 1), $offsets($value, $term));

        $this->assertSame(['Müller'], $slices('Jöhn Müller', 'muller'));
        $this->assertSame(['Straße'], $slices('Straße', 'strasse'));
        $this->assertSame(["Cafe\u{0301}"], $slices("Cafe\u{0301} Noir", 'cafe'));
        $this->assertSame(['ß', 'ß'], $slices('aßbß', 'ss'));
        // Two matches meeting inside one ß ("sss" twice in "ssssss") become one range, not two that overlap.
        $this->assertSame([[0, 5]], $offsets('ßßß', 'sss'));
        // ASCII and an accented term on an accented value keep findMatchOffsets()'s raw ranges.
        $this->assertSame([[1, 2], [3, 4]], $offsets('banana', 'an'));
        $this->assertSame(['José'], $slices('José Ramírez', 'josé'));

        // UF-2: letters MySQL 8's and MariaDB 11's _ai_ci collations or PostgreSQL's unaccent() fold
        // and Accents::fold() leaves, folded here too, typed either way.
        $this->assertSame(['Łukasz'], $slices('Łukasz Nowak', 'lukasz'));
        $this->assertSame(['Łukasz'], $slices('Łukasz Nowak', 'łukasz'));
        $this->assertSame(['Đorđe'], $slices('Đorđe Balašević', 'dorde'));
        $this->assertSame(['Ærø'], $slices('Ærø Island', 'aero'));
        $this->assertSame(['Œuvre'], $slices('Œuvre Complète', 'oeuvre'));
        $this->assertSame(['Þór'], $slices('Þór Heyerdahl', 'thor'));
        $this->assertSame(['æ'], $slices('Cæsar', 'e')); // inside a letter folded to two, the whole letter
    }

    /**
     * R12-M1. Folded highlighting folded every character of a value once for every needle, and
     * scoring folded every value whole on every row: on MySQL's default collation a highlighted
     * search over long non-ASCII text cost 16-39 times the unfolded one. A value is now folded once
     * for every needle, each distinct character once (PHP only: run on every database).
     */
    public function test_folded_highlighting_folds_each_distinct_character_once(): void
    {
        $value   = str_repeat('Jöhn Müller wohnt in der Straße. Ёжик и йогурт. ', 1400); // 64 KB, 13 distinct non-ASCII characters
        $offsets = new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder::class, 'foldedMatchOffsets');
        $builder = AccentScoredPerson::search('x');

        \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decompositions = 0;
        $found = $offsets->invoke($builder, $value, 'muller', 3);
        $this->assertSame(['Müller', 'Müller', 'Müller'], array_map(fn (array $r) => substr($value, $r[0], $r[1] - $r[0] + 1), $found));
        $this->assertLessThanOrEqual(count(array_unique(mb_str_split((string) preg_replace('/[\x00-\x7F]/', '', $value)))), \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decompositions);
    }

    /** R12-M1 through a search: a value that holds the term as typed is not folded to score it. */
    public function test_a_highlighted_search_folds_each_distinct_character_once_a_pass(): void
    {
        if (!$this->foldsInSql()) {
            $this->markTestSkipped('MySQL/MariaDB only (CI runs them): the suite\'s utf8mb4_unicode_ci is the accent-insensitive collation that folds.');
        }
        $body = str_repeat('Jöhn Müller wohnt in der Straße. Ёжик и йогурт. ', 3); // within the column's 255
        DB::table('accent_scored_people')->delete();
        foreach (range(1, 20) as $i) {
            DB::table('accent_scored_people')->insert(['name' => "Muller {$i} {$body}"]);
        }

        \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decompositions = 0;
        $rows = AccentScoredPerson::search('muller')->using('simple')->highlight()->take(20)->get();
        $this->assertCount(20, $rows);
        $this->assertStringStartsWith('<em>Muller</em> 1', $rows->firstWhere('name', "Muller 1 {$body}")->_highlighted['name']);
        // 13 distinct non-ASCII characters, once each for the whole result; no value folded whole to score.
        $this->assertLessThanOrEqual(13, \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decompositions);
    }

    /**
     * R13-L1. Scoring skipped the fold whenever a value held a term as typed anywhere, so a value
     * holding both spellings lost the tier its accented start gives ("Jose José" outranked
     * "José Jose"), and a decomposed "Café" (cafe + U+0301) fell from exact to prefix. Each scores
     * as at 5220f97 again: the best of the value and its fold, short or past the scoring head.
     */
    public function test_a_value_holding_both_spellings_keeps_the_tier_of_its_accented_start(): void
    {
        Schema::dropIfExists('accent_scored_docs');
        Schema::create('accent_scored_docs', fn ($table) => [$table->id(), $table->text('body')]);
        $tail = ' ' . str_repeat('The terrace opens at noon and the tables fill up fast. ', 6); // past the 255-character head
        foreach (['Café de Flore', 'Café de Flore, the best cafe', "Café de Flore, the best cafe{$tail}", 'Cafe Society', "Cafe\u{0301}", 'José Jose', 'Jose José', "José Jose{$tail}"] as $body) {
            DB::table('accent_scored_docs')->insert(['body' => $body]);
        }

        foreach ($this->foldingPaths(AccentScoredDoc::class) as $label => $search) {
            $cafe = $this->scores($search('cafe'), 'body');
            if ($label === 'first') {
                $this->assertSame(["Cafe\u{0301}" => 1.0], $cafe, $label);
                continue;
            }
            // The decomposed "Café" folds to the term: exact. Every other row starts with it, folded or not: prefix.
            $this->assertSame(1.0, $cafe["Cafe\u{0301}"], "{$label} cafe exact");
            foreach (['Café de Flore', 'Café de Flore, the best cafe', "Café de Flore, the best cafe{$tail}", 'Cafe Society'] as $body) {
                $this->assertSame(0.8, $cafe[$body], "{$label} cafe prefix: " . substr($body, 0, 40));
            }

            $jose = $this->scores($search('jose'), 'body');
            foreach (['José Jose', 'Jose José', "José Jose{$tail}"] as $body) {
                $this->assertSame(1.0, $jose[$body], "{$label} jose prefix: " . substr($body, 0, 40));
            }
        }
    }

    /**
     * R13-M1. A value the database matched only through its accent-insensitive collation ("ecole"
     * over "école", the case TF-8 exists for) was folded whole to score it: 3.6-4.3 times the
     * unfolded scoring over long text. Past the 255-character scoring head, only the head is folded
     * when it holds a term (the exact, prefix and contains tiers it gives are the whole value's);
     * the whole value only when neither it nor its folded head holds one. Tiers unchanged.
     */
    public function test_a_long_value_matched_through_its_accents_is_folded_by_its_scoring_head(): void
    {
        Schema::dropIfExists('accent_scored_docs');
        Schema::create('accent_scored_docs', fn ($table) => [$table->id(), $table->text('body')]);
        $filler = str_repeat('Une phrase française près du café, où Hélène mange une crème brûlée. ', 4); // 280 characters
        $rows   = [
            'Ecole'           => 1.0,  // exact
            "École de Paris. {$filler}" => 0.8,  // prefix, from the folded head
            "Paris, une école. {$filler}" => 0.6,  // contains, from the folded head
            "{$filler}Enfin, une école." => 0.6,  // contains, past the head: folded whole
        ];
        foreach (array_keys($rows) as $body) {
            DB::table('accent_scored_docs')->insert(['body' => $body]);
        }
        $long = str_repeat("L'école française près du café où Hélène mange une crème brûlée à côté de la fenêtre. ", 90); // 8 KB
        foreach (range(1, 20) as $i) {
            DB::table('accent_scored_docs')->insert(['body' => "Row {$i}. {$long}"]);
        }

        foreach ($this->foldingPaths(AccentScoredDoc::class) as $label => $search) {
            \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decomposedBytes = 0;
            $scores = $this->scores($search('ecole'), 'body');
            if ($label === 'first') {
                $this->assertSame(['Ecole' => 1.0], $scores, $label);
                continue;
            }
            $this->assertCount(24, $scores, $label);
            foreach ($rows as $body => $score) {
                $this->assertSame($score, $scores[$body], "{$label}: " . substr($body, 0, 30));
            }
            $this->assertSame(0.6, $scores["Row 1. {$long}"], $label);

            // 20 values of 8 KB, each folded by its head (255 characters, at most 1,020 bytes), and one
            // short value whose term sits past its head folded whole: never the 8 KB values whole (164 KB).
            $this->assertLessThan(20 * 1020 + 2 * strlen($filler), \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decomposedBytes, $label);
        }
    }

    /**
     * R13-M1, the other side: a value that holds the term as written gains from its fold at most the
     * prefix tier, so it is not folded at all when its first character folds into the start of no
     * term (8657854's cost for a term typed as stored, kept).
     */
    public function test_a_long_value_holding_the_term_as_written_is_not_folded_to_score_it(): void
    {
        Schema::dropIfExists('accent_scored_docs');
        Schema::create('accent_scored_docs', fn ($table) => [$table->id(), $table->text('body')]);
        $long = str_repeat('Москва — столица России. Ёжик съел йогурт и пошёл гулять по широкой улице. ', 60); // 8 KB
        foreach (range(1, 20) as $i) {
            DB::table('accent_scored_docs')->insert(['body' => "Город {$i}. {$long}"]);
        }

        foreach ($this->foldingPaths(AccentScoredDoc::class) as $label => $search) {
            \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decomposedBytes = 0;
            $scores = $this->scores($search('столица'), 'body');
            $this->assertCount($label === 'first' ? 1 : 20, $scores, $label);
            // The term's own folds, and each value's first character ("Г", 2 bytes) folded to compare
            // it with the term's: never a value's 255-character head (about 460 bytes each).
            $this->assertLessThanOrEqual(10 * strlen('столица') + 20 * 2, \Ashiqfardus\LaravelFuzzySearch\Support\Accents::$decomposedBytes, $label);
        }
    }

    /**
     * R13-L2. An ASCII value has nothing to fold, yet each of its matches was mapped back through the
     * folded text: highlighting ASCII on an accent-insensitive collation cost 1.3-1.4 times the
     * unfolded highlighting, 2.5 times for a value that repeats the term. With no anchors the folded
     * search is findMatchOffsets()'s own, and a value whose characters all fold to themselves
     * (Cyrillic, CJK) is not rewritten. PHP time only: the best of several rounds, each side in turn.
     */
    public function test_a_value_with_nothing_to_fold_is_highlighted_at_the_unfolded_cost(): void
    {
        $builder = AccentScoredPerson::search('x');
        $folded  = new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder::class, 'foldedMatchOffsets');
        $raw     = new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder::class, 'findMatchOffsets');
        $text    = new \ReflectionMethod(\Ashiqfardus\LaravelFuzzySearch\SearchBuilder::class, 'foldedText');
        $value   = str_repeat('The search index and the search box. ', 1800); // 66 KB, 3,600 matches

        $this->assertSame($raw->invoke($builder, $value, 'search', 0), $folded->invoke($builder, $value, 'search', 0));
        $best = [INF, INF];
        for ($round = 0; $round < 9; $round++) {
            foreach ([$folded, $raw] as $side => $method) {
                $started = hrtime(true);
                for ($i = 0; $i < 8; $i++) {
                    $method->invoke($builder, $value, 'search', 0);
                }
                $best[$side] = min($best[$side], hrtime(true) - $started);
            }
        }
        $this->assertLessThan(1.6, $best[0] / $best[1], sprintf('folded %.1f ms against %.1f ms', $best[0] / 1e6, $best[1] / 1e6));

        $memo = [];
        foreach (['Москва — столица России.', '東京は日本の首都です。'] as $plain) {
            $this->assertSame([$plain, []], $text->invokeArgs($builder, [$plain, &$memo]));
        }
    }
}
