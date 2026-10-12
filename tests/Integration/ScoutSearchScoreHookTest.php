<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The README's Per-Model Customization example (a featured row boosted, here ×100), on Scout's trait and the package's. */
class ScoutHookProduct extends Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::search as scoutSearch;
    }

    protected $table   = 'scout_hook_products';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10]];

    public function getSearchScore(float $baseScore): float
    {
        return $this->is_featured ? $baseScore * 100 : $baseScore;
    }
}

/**
 * TE-5 (pass 3, ruling ER-173): the Scout engine ranked with the same BM25 scorer as useInvertedIndex()
 * but never ran the model's getSearchScore() override, so the featured row at BM25 rank 9 led the
 * builder's page and was ninth through Scout, where the docs say both rank the same. The engine
 * re-ranks the same window as the builder (the first max_candidates matches), and _score is the
 * score through the hook, the builder's _raw_score.
 */
class ScoutSearchScoreHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        Schema::dropIfExists('scout_hook_products');
        Schema::create('scout_hook_products', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->boolean('is_featured')->default(false);
        });

        // BM25 rank i: row i holds "kettle" and i filler words. Rows 9 and 11 are featured.
        for ($i = 0; $i < 12; $i++) {
            DB::table('scout_hook_products')->insert([
                'title'       => trim('kettle ' . implode(' ', array_map(fn ($k) => "w{$i}x{$k}", range(1, $i)))),
                'is_featured' => in_array($i, [9, 11], true),
            ]);
        }

        app(IndexManager::class)->indexBatch(ScoutHookProduct::all());
        config(['scout.driver' => 'fuzzy-search']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('scout_hook_products');
        parent::tearDown();
    }

    /** @return array<int, array{0: int, 1: float}> [BM25 rank, score] per row: Scout's _score, the builder's _raw_score */
    private static function served(iterable $rows, string $score): array
    {
        $first = (int) DB::table('scout_hook_products')->min('id');

        return collect($rows)->map(fn (Model $row) => [$row->getKey() - $first, round((float) $row->{$score}, 6)])->values()->all();
    }

    private function assertScoutRanksAsTheIndexPath(string $at, \Closure $scout, \Closure $index): void
    {
        $this->assertSame(self::served($index()->take(5)->get(), '_raw_score'), self::served($scout()->take(5)->get(), '_score'), "{$at}: get");

        for ($page = 1; $page <= 3; $page++) {
            $this->assertSame(
                self::served($index()->paginate(5, 'page', $page)->items(), '_raw_score'),
                self::served($scout()->paginate(5, 'page', $page)->items(), '_score'),
                "{$at}: paginate page {$page}"
            );
        }
    }

    public function test_the_featured_row_ranks_the_same_through_scout_and_use_inverted_index(): void
    {
        $scout = fn () => ScoutHookProduct::scoutSearch('kettle');
        $index = fn () => ScoutHookProduct::search('kettle')->useInvertedIndex()->typoTolerance(0);

        // Rows 9 and 11 lead, boosted.
        $top = array_column(self::served($scout()->take(5)->get(), '_score'), 0);
        $this->assertEqualsCanonicalizing([9, 11], array_slice($top, 0, 2));
        $this->assertScoutRanksAsTheIndexPath('window holds every match', $scout, $index);

        // A window of 5: row 9 and row 11 are past it and keep their BM25 places, on both.
        config(['fuzzy-search.max_candidates' => 5]);
        $this->assertSame([], array_intersect([9, 11], array_column(self::served($scout()->take(5)->get(), '_score'), 0)));
        $this->assertScoutRanksAsTheIndexPath('window of 5', $scout, $index);
    }

    public function test_a_constrained_and_an_ordered_search_score_through_the_hook(): void
    {
        $ids = DB::table('scout_hook_products')->orderBy('id')->pluck('id')->all();
        unset($ids[0], $ids[11]); // rows 0 and 11 out

        $this->assertScoutRanksAsTheIndexPath(
            'whereIn()',
            fn () => ScoutHookProduct::scoutSearch('kettle')->whereIn('id', array_values($ids)),
            fn () => ScoutHookProduct::search('kettle')->useInvertedIndex()->typoTolerance(0)->whereIn('id', array_values($ids))
        );

        // Under orderBy() the order is the column's, and _score is still the score through the hook.
        $scout = ScoutHookProduct::scoutSearch('kettle')->orderBy('id')->take(12)->get();
        $index = ScoutHookProduct::search('kettle')->useInvertedIndex()->typoTolerance(0)->orderBy('id')->take(12)->get();
        $this->assertSame(self::served($index, '_raw_score'), self::served($scout, '_score'), 'orderBy');
    }
}
