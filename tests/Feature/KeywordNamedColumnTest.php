<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class KeywordColumnTeam extends Model
{
    use Searchable;

    protected $table   = 'keyword_column_teams';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['group' => 10, 'desc' => 5, 'order' => 1]];
}

/**
 * Deep review RB-4. A bare column went into the relevance ordering and the extended `=term`
 * predicate unquoted on SQLite (`CASE WHEN group = ?`, `LOWER(group) = LOWER(?)`), so a searchable
 * column named with a keyword (`group`, `order`, `desc`) made get() and paginate() throw a syntax
 * error there, for every algorithm, while the other databases quoted it and ran.
 */
class KeywordNamedColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('keyword_column_teams');
        Schema::create('keyword_column_teams', function ($table) {
            $table->id();
            $table->string('group');
            $table->string('desc')->nullable();
            $table->string('order')->nullable();
        });
        DB::table('keyword_column_teams')->insert([
            ['group' => 'admins', 'desc' => 'administrators of the site', 'order' => 'first'],
            ['group' => 'editors', 'desc' => 'people who edit', 'order' => 'second'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('keyword_column_teams');
        parent::tearDown();
    }

    public function test_a_searchable_column_named_with_a_keyword_is_searched_and_ranked(): void
    {
        foreach (['fuzzy', 'simple', 'levenshtein', 'trigram', 'similar_text'] as $algorithm) {
            $this->assertSame(['admins'], KeywordColumnTeam::search('admins')->using($algorithm)->get()->pluck('group')->all(), $algorithm);
        }

        $this->assertSame(['admins'], collect(KeywordColumnTeam::search('admins')->paginate(5)->items())->pluck('group')->all());
        $this->assertSame(1, KeywordColumnTeam::search('admins')->count());
        $this->assertSame(['editors'], KeywordColumnTeam::search('second')->searchIn(['order', 'group'])->get()->pluck('group')->all());
        $this->assertSame(['admins'], KeywordColumnTeam::search('')->extended('=admins')->get()->pluck('group')->all());
        $this->assertSame(['editors'], KeywordColumnTeam::search('')->extended('order:second')->get()->pluck('group')->all());
        $this->assertSame(['admins'], KeywordColumnTeam::search('admins')->highlight()->debugScore()->get()->pluck('group')->all());
    }
}
