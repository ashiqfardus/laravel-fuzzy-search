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
}
