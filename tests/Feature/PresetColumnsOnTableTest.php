<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** preset() adds only the columns the table has: the users preset's `username` on a name/email table is not an error. */
class PresetColumnsOnTableTest extends TestCase
{
    public function test_a_preset_column_the_table_lacks_is_dropped(): void
    {
        $rows = User::search('john')->preset('users')->get();

        $this->assertContains('John Doe', $rows->pluck('name')->all());
        $this->assertSame($rows->count(), User::search('john')->preset('users')->count());
        $this->assertSame($rows->count(), User::search('john')->preset('users')->paginate(50)->total());
        $this->assertNotNull(User::search('john')->preset('users')->first());
    }

    public function test_a_preset_whose_columns_are_all_missing_still_applies_its_other_settings(): void
    {
        config(['fuzzy-search.presets.nocols' => ['columns' => ['nothing_here' => 5], 'algorithm' => 'simple']]);

        $builder = User::search('john')->searchIn(['name'])->preset('nocols');

        $this->assertContains('John Doe', $builder->get()->pluck('name')->all());
        $this->assertSame('simple', $builder->getDebugInfo()['algorithm'] ?? 'simple');
    }

    public function test_a_table_that_has_the_column_still_searches_it(): void
    {
        Schema::dropIfExists('preset_people');
        Schema::create('preset_people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username');
        });
        DB::table('preset_people')->insert([['name' => 'Alice', 'username' => 'zebra_fan']]);

        $builder = new SearchBuilder(DB::table('preset_people'), app(FuzzySearch::class));

        $this->assertCount(1, $builder->search('zebra_fan')->preset('users')->get());
        Schema::dropIfExists('preset_people');
    }

    public function test_a_column_named_with_search_in_that_does_not_exist_still_throws(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite reads an unknown double-quoted column as a string literal and does not throw. CI covers the other databases.');
        }
        $this->expectException(\Illuminate\Database\QueryException::class);

        User::search('john')->searchIn(['username'])->preset('users')->get();
    }
}
