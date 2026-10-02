<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrdinalItem extends Model
{
    use Searchable;

    protected $table   = 'ordinal_items';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];
}

/**
 * SA-2. RB-1's letter check sent a term with no letter to the pattern fallback, but counted ª, º and
 * µ as letters (PCRE's \pL). MySQL's and MariaDB's SOUNDEX() encode them as '', so "2º" took the
 * native SOUNDEX() there and matched "iPhone 15" and "Office 365". It now finds the rows that hold
 * it on every database (the regression shows on MySQL and MariaDB; the others take the fallback).
 */
class SoundexOrdinalTermTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('ordinal_items');
        Schema::create('ordinal_items', function ($table) {
            $table->id();
            $table->string('title');
        });
        DB::table('ordinal_items')->insert([
            ['title' => 'iPhone 15'],
            ['title' => 'Office 365'],
            ['title' => 'Piso 2º'],
            ['title' => 'Planta 1ª'],
            ['title' => 'Garden'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ordinal_items');
        parent::tearDown();
    }

    public function test_an_ordinal_term_finds_only_the_rows_that_hold_it(): void
    {
        foreach (['2º' => ['Piso 2º'], '1ª' => ['Planta 1ª']] as $term => $expected) {
            $this->assertSame($expected, DB::table('ordinal_items')->whereFuzzy('title', $term, 'soundex')->pluck('title')->all(), "{$term} macro");
            $this->assertSame($expected, OrdinalItem::search($term)->using('soundex')->get()->pluck('title')->all(), "{$term} get");
            $this->assertSame(1, OrdinalItem::search($term)->using('soundex')->count(), "{$term} count");
            $this->assertSame(1, OrdinalItem::search($term)->using('soundex')->paginate(10)->total(), "{$term} paginate");
        }
    }

    /**
     * PostgreSQL's fuzzystrmatch soundex() skips a leading byte its C library does not call a letter:
     * under glibc (Linux, CI) every byte past ASCII, so "Émile" encoded from its "m" (M400, the code
     * of "Mila") and "Øle" as L000 ("Lee"); on macOS it kept the byte and returned an invalid code,
     * and the search threw. A term whose first letter is not ASCII takes the pattern fallback there.
     */
    public function test_postgresql_sends_a_term_whose_first_letter_is_not_ascii_to_the_fallback(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (CI runs it): fuzzystrmatch\'s soundex() is the native function there.');
        }
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
        } catch (\Throwable $e) {
            $this->markTestSkipped('The fuzzystrmatch extension cannot be created here: ' . $e->getMessage());
        }
        config(['fuzzy-search.use_native_functions' => true]);
        DB::table('ordinal_items')->insert([['title' => 'Mila Kunis'], ['title' => 'Émile Zola'], ['title' => 'Lee Marvin'], ['title' => 'Øle Gunnar']]);

        foreach (['Émile' => ['Émile Zola'], 'Øle' => ['Øle Gunnar']] as $term => $expected) {
            $this->assertSame($expected, OrdinalItem::search($term)->using('soundex')->get()->pluck('title')->all(), "{$term} get");
            $this->assertSame($expected, DB::table('ordinal_items')->whereFuzzy('title', $term, 'soundex')->pluck('title')->all(), "{$term} macro");
        }

        // A term whose first letter is ASCII still takes soundex(): "Mila" finds "Mila Kunis" by sound.
        $this->assertContains('Mila Kunis', OrdinalItem::search('Myla')->using('soundex')->get()->pluck('title')->all());
    }

    /**
     * The stored side of the same: soundex() of a stored word whose first letter is not ASCII skipped
     * that letter under glibc, so "Mila" found "Émile Zola" (M400) and "Lee" found "Øle Gunnar"
     * (L000), and on macOS it returned a code that is not UTF-8 and the search threw. Such a word is
     * now not compared by soundex() at all (ruling ER-172).
     */
    public function test_postgresql_does_not_compare_a_stored_word_whose_first_letter_is_not_ascii(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (CI runs it): fuzzystrmatch\'s soundex() is the native function there.');
        }
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
        } catch (\Throwable $e) {
            $this->markTestSkipped('The fuzzystrmatch extension cannot be created here: ' . $e->getMessage());
        }
        config(['fuzzy-search.use_native_functions' => true]);
        DB::table('ordinal_items')->insert([['title' => 'Mila Kunis'], ['title' => 'Émile Zola'], ['title' => 'Lee Marvin'], ['title' => 'Øle Gunnar'], ['title' => 'Anna Øle']]);

        foreach (['Mila' => ['Mila Kunis'], 'Lee' => ['Lee Marvin']] as $term => $expected) {
            $this->assertSame($expected, OrdinalItem::search($term)->using('soundex')->get()->pluck('title')->all(), "{$term} get");
            $this->assertSame($expected, DB::table('ordinal_items')->whereFuzzy('title', $term, 'soundex')->pluck('title')->all(), "{$term} macro");
        }

        // A stored word whose first letter is ASCII is still compared, first word or last.
        $this->assertSame(['Anna Øle'], OrdinalItem::search('Ana')->using('soundex')->get()->pluck('title')->all());

        // The guard itself, which macOS's fuzzystrmatch cannot show: each word reaches soundex() only
        // when it begins with an ASCII letter, inside a CASE, so PostgreSQL never evaluates it first.
        $sql = DB::table('ordinal_items')->whereFuzzy('title', 'Mila', 'soundex')->toSql();
        $this->assertSame(2, substr_count($sql, "~ '^[A-Za-z]' THEN SOUNDEX("), $sql);
    }
}
