<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\Searchable;

class ScoutInputDoc extends Model
{
    use Searchable, FuzzySearchable {
        FuzzySearchable::search insteadof Searchable;
        Searchable::search as scoutSearch;
        FuzzySearchable::bootSearchable insteadof Searchable;
        Searchable::bootSearchable as bootScoutSearchable;
    }

    protected $table   = 'scout_input_docs';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10]];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

/**
 * Request input that reaches the Scout engine: an order column is checked like the builder's
 * orderBy() (a secret name is refused), and a long query uses its first query.max_tokens terms.
 */
class ScoutRequestInputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'fuzzy-search', 'scout.queue' => false, 'scout.after_commit' => false]);

        Schema::dropIfExists('scout_input_docs');
        Schema::create('scout_input_docs', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('api_token')->default('t');
        });
        DB::table('scout_input_docs')->insert([
            ['name' => 'ka kb kc', 'api_token' => 'b'],
            ['name' => 'kc', 'api_token' => 'a'],
        ]);
        ScoutInputDoc::query()->get()->searchable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scout_input_docs');
        parent::tearDown();
    }

    public function test_a_secret_named_order_column_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ScoutInputDoc::scoutSearch('kc')->orderBy('api_token')->get();
    }

    public function test_an_ordinary_order_column_still_orders(): void
    {
        $this->assertSame(['kc', 'ka kb kc'], ScoutInputDoc::scoutSearch('kc')->orderBy('name', 'desc')->get()->pluck('name')->all());
    }

    public function test_the_query_uses_its_first_max_tokens_distinct_terms(): void
    {
        config(['fuzzy-search.query.max_tokens' => 2]);

        // "ka kb kc" would match "kc" only through its third term.
        $this->assertSame(['ka kb kc'], ScoutInputDoc::scoutSearch('ka kb kc')->get()->pluck('name')->all());
        $this->assertSame([], ScoutInputDoc::scoutSearch('zq zr kc')->get()->pluck('name')->all());
    }
}
