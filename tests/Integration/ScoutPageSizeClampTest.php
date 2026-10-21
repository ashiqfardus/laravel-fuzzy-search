<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Searchable;

class ScoutClampDoc extends Model
{
    use Searchable, FuzzySearchable {
        FuzzySearchable::search insteadof Searchable;
        Searchable::search as scoutSearch;
        FuzzySearchable::bootSearchable insteadof Searchable;
        Searchable::bootSearchable as bootScoutSearchable;
    }

    protected $table   = 'scout_clamp_docs';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10]];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

/**
 * A page size read from the request is cut to max_candidates on the Scout engine as it is on
 * the builder and FederatedSearch: ?per_page=1000000 hydrated every match of the word at once.
 */
class ScoutPageSizeClampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false, 'fuzzy-search.max_candidates' => 5]);

        Schema::dropIfExists('scout_clamp_docs');
        Schema::create('scout_clamp_docs', function ($table) {
            $table->id();
            $table->string('name');
        });
        DB::table('scout_clamp_docs')->insert(array_map(fn (int $i) => ['name' => "zed item {$i}"], range(1, 12)));
        ScoutClampDoc::query()->get()->searchable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scout_clamp_docs');
        parent::tearDown();
    }

    private function skipOnScout100(): void
    {
        if (!interface_exists(\Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase::class)) {
            $this->markTestSkipped('Scout 10.0 builds paginate()\'s paginator itself, so the engine does not cut its page.');
        }
    }

    public function test_on_scout_10_0_a_page_is_not_cut_so_every_match_stays_reachable(): void
    {
        if (interface_exists(\Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase::class)) {
            $this->markTestSkipped('Scout 10.1 and later hand the engine the paginator, which cuts the page.');
        }

        $page = ScoutClampDoc::scoutSearch('zed')->paginate(1_000_000);
        $this->assertCount(12, $page->items());
        $this->assertSame(12, $page->total());
        $this->assertCount(12, ScoutClampDoc::scoutSearch('zed')->simplePaginate(1_000_000)->items());
    }

    public function test_paginate_cuts_the_page_size_to_max_candidates(): void
    {
        $this->skipOnScout100();
        $page = ScoutClampDoc::scoutSearch('zed')->paginate(1_000_000);

        $this->assertCount(5, $page->items());
        $this->assertSame(5, $page->perPage());
        $this->assertSame(12, $page->total());
    }

    public function test_simple_paginate_cuts_the_page_size_to_max_candidates(): void
    {
        $this->skipOnScout100();
        $page = ScoutClampDoc::scoutSearch('zed')->simplePaginate(1_000_000);

        $this->assertCount(5, $page->items());
        $this->assertTrue($page->hasMorePages());
    }

    public function test_take_is_cut_to_max_candidates(): void
    {
        $this->assertCount(5, ScoutClampDoc::scoutSearch('zed')->take(1_000_000)->get());
    }

    public function test_a_page_size_under_the_cap_is_unchanged(): void
    {
        $this->assertCount(3, ScoutClampDoc::scoutSearch('zed')->paginate(3)->items());
        $this->assertCount(2, ScoutClampDoc::scoutSearch('zed')->take(2)->get());
    }
}
