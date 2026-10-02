<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RecencyProbeUser extends Model
{
    use Searchable;

    public static int $calls = 0;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10]];

    /** Stands in for an app method with a side effect, such as MustVerifyEmail::markEmailAsVerified(). */
    public function purgeEverything(): void
    {
        static::$calls++;
    }
}

/** Columns whose cast reads as an array and as an object without __toString(). */
class RecencyCastItem extends Model
{
    use Searchable;

    protected $table   = 'recency_cast_items';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = ['options' => 'array', 'meta' => 'object'];

    protected array $searchable = ['columns' => ['name' => 10]];
}

/**
 * SF-8 (ruling ER-148, overturning round 7's R1). boostRecent()'s column was read with data_get(),
 * which on an Eloquent row is getAttribute(): its relation fallback calls any public method named
 * like the column. A request-supplied recency column (`?recent_by=markEmailAsVerified`) ran that
 * method on the first scored row, which then threw "must return a relationship instance" after the
 * write. The column is now read as every other request-named column is (SearchableColumns::read(),
 * ER-84), and so is @fuzzyHighlight's display fallback.
 */
class RecencyColumnReadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecencyProbeUser::$calls = 0;
        app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)->indexBatch(RecencyProbeUser::all());
    }

    public function test_a_recency_column_that_names_a_model_method_runs_nothing(): void
    {
        $make = fn () => RecencyProbeUser::search('john')->boostRecent(1.5, 'purgeEverything');

        $runs = [
            'get'            => fn () => $make()->get()->count(),
            'first'          => fn () => $make()->first() === null ? 0 : 1,
            'paginate'       => fn () => count($make()->paginate(5)->items()),
            'simplePaginate' => fn () => count($make()->simplePaginate(5)->items()),
            'extended'       => fn () => RecencyProbeUser::search('')->extended('john')->boostRecent(1.5, 'purgeEverything')->get()->count(),
            // The index path applies boostRecent() too (ruling ER-149).
            'index get'      => fn () => $make()->useInvertedIndex()->get()->count(),
            'index first'    => fn () => $make()->useInvertedIndex()->first() === null ? 0 : 1,
            'index paginate' => fn () => count($make()->useInvertedIndex()->paginate(5)->items()),
        ];

        foreach ($runs as $label => $run) {
            $this->assertGreaterThan(0, $run(), $label);
            $this->assertSame(0, RecencyProbeUser::$calls, "{$label} called the method");
        }
    }

    public function test_a_real_recency_column_still_boosts(): void
    {
        DB::table('users')->where('name', 'John Doe')->update(['created_at' => now()->subDays(200)]);

        $plain   = RecencyProbeUser::search('john doe')->get()->firstWhere('name', 'John Doe')->_raw_score;
        $boosted = RecencyProbeUser::search('john doe')->boostRecent(5.0)->get();

        // John Doe is outside the window; the others matching "john" were created now.
        $this->assertEquals($plain, $boosted->firstWhere('name', 'John Doe')->_raw_score);
        $this->assertGreaterThan(
            RecencyProbeUser::search('john doe')->get()->firstWhere('name', 'Johnny Bravo')->_raw_score,
            $boosted->firstWhere('name', 'Johnny Bravo')->_raw_score
        );
    }

    public function test_fuzzy_highlight_fallback_runs_no_model_method(): void
    {
        $user = RecencyProbeUser::query()->where('name', 'John Doe')->first();

        $this->assertSame('', SearchBuilder::renderHighlighted($user, 'purgeEverything'));
        $this->assertSame(0, RecencyProbeUser::$calls);
        $this->assertSame('John Doe', SearchBuilder::renderHighlighted($user, 'name'));
    }

    /**
     * TF-2. A recency column that reads as an array (a JSON or array cast) or as an object without
     * __toString() reached `new DateTime()`, whose TypeError is no \Exception: the search threw on
     * every path (`?recent_by=options` was a 500) where a column that is no date gives no boost.
     */
    public function test_a_recency_column_that_reads_as_an_array_or_an_object_gives_no_boost(): void
    {
        Schema::dropIfExists('recency_cast_items');
        Schema::create('recency_cast_items', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('options')->nullable();
            $table->text('meta')->nullable();
        });
        foreach (['zed alpha', 'zed beta'] as $name) {
            RecencyCastItem::create(['name' => $name, 'options' => ['a' => 1], 'meta' => (object) ['b' => 2]]);
        }
        app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)->indexBatch(RecencyCastItem::all());

        try {
            foreach (['options', 'meta'] as $column) {
                $make = fn (bool $boost) => $boost ? RecencyCastItem::search('zed')->boostRecent(5.0, $column) : RecencyCastItem::search('zed');
                $runs = [
                    'get'            => fn (bool $b) => $make($b)->get()->all(),
                    'first'          => fn (bool $b) => [$make($b)->first()],
                    'paginate'       => fn (bool $b) => $make($b)->paginate(5)->items(),
                    'simplePaginate' => fn (bool $b) => $make($b)->simplePaginate(5)->items(),
                    'extended'       => fn (bool $b) => ($b ? RecencyCastItem::search('')->extended('zed')->boostRecent(5.0, $column) : RecencyCastItem::search('')->extended('zed'))->get()->all(),
                    'index get'      => fn (bool $b) => $make($b)->useInvertedIndex()->get()->all(),
                    'index paginate' => fn (bool $b) => $make($b)->useInvertedIndex()->paginate(5)->items(),
                ];

                foreach ($runs as $label => $run) {
                    $scores = fn (array $rows) => array_map(fn ($row) => [$row->name, $row->_score], $rows);
                    $this->assertSame($scores($run(false)), $scores($run(true)), "{$column} {$label}");
                }
            }
        } finally {
            Schema::dropIfExists('recency_cast_items');
        }
    }
}
