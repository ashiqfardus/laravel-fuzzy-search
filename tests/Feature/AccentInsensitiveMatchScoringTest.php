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
        parent::tearDown();
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
    }
}
