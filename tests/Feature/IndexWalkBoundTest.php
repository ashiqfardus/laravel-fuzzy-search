<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The users table under Scout's builder, with the package's index behind it. */
class WalkScoutUser extends \Illuminate\Database\Eloquent\Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

/** A model on another connection than the index's (the default one). */
class WalkOtherConnectionUser extends \Illuminate\Database\Eloquent\Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $connection = 'fuzzy_walk_other';
    protected $table      = 'walk_users';
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * H1 (ruling ER-82). With orderBy() the index path (and Scout's) walked the whole constrained
 * table in OFFSET pages until enough ranked ids had passed, so a page past the matches, matches
 * that sort last, or a tenant where() with fewer matches than the page read every row: 68 s and
 * 201 queries for ?page=1000 on 200k rows. Every index terminal now serves an empty page past
 * the ranking without a walk, and the ordered query is restricted to the ranked documents.
 */
class IndexWalkBoundTest extends TestCase
{
    protected function tearDown(): void
    {
        if (config('database.connections.fuzzy_walk_other') !== null) {
            Schema::connection('fuzzy_walk_other')->dropIfExists('walk_users');
            DB::purge('fuzzy_walk_other');
        }
        parent::tearDown();
    }

    /**
     * 5,000 tenant-a rows that match nothing and sort first by name, then 300 "Zebra" matches (5 of
     * them tenant-a), all indexed under $modelClass.
     */
    private function seedLateMatches(string $modelClass = User::class): void
    {
        $rows = [];
        for ($i = 0; $i < 5000; $i++) {
            $rows[] = ['name' => sprintf('Aardvark %04d', $i), 'email' => "a{$i}@tenant-a.test"];
        }
        for ($i = 0; $i < 300; $i++) {
            $rows[] = ['name' => sprintf('Zebra %03d', $i), 'email' => 'z' . $i . ($i < 5 ? '@tenant-a.test' : '@tenant-b.test')];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        app(IndexManager::class)->indexBatch($modelClass::query()->where('name', 'like', 'Zebra%')->get());
    }

    /** @return string[] every statement $call runs against the users table */
    private function usersQueries(\Closure $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $log = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return array_values(array_filter($log, fn (string $sql) => preg_match('/\busers\b/i', $sql) === 1));
    }

    /** @return string[] the ordered reads of the users table among $queries */
    private function ordered(array $queries): array
    {
        return array_values(array_filter($queries, fn (string $sql) => stripos($sql, 'order by') !== false));
    }

    public function test_a_page_past_the_matches_reads_no_row_on_every_index_terminal(): void
    {
        $this->seedLateMatches();

        foreach (['orderBy' => fn () => User::search('zebra')->useInvertedIndex()->orderBy('name'), 'relevance' => fn () => User::search('zebra')->useInvertedIndex()] as $label => $make) {
            $terminals = [
                'get'            => fn () => $this->assertCount(0, $make()->skip(14985)->get()),
                'first'          => fn () => $this->assertNull($make()->skip(14985)->first()),
                'paginate'       => fn () => $this->assertSame([], $make()->paginate(15, 'page', 1000)->items()),
                'simplePaginate' => fn () => $this->assertSame([], $make()->simplePaginate(15, 'page', 1000)->items()),
            ];

            foreach ($terminals as $terminal => $call) {
                $this->assertSame([], $this->usersQueries($call), "{$label} {$terminal}: page 1000 read the model's table");
            }
        }

        // The total still counts every match.
        $this->assertSame(300, User::search('zebra')->useInvertedIndex()->orderBy('name')->paginate(15, 'page', 1000)->total());
    }

    public function test_matches_that_sort_last_take_one_ordered_query(): void
    {
        $this->seedLateMatches();
        $make = fn () => User::search('zebra')->useInvertedIndex()->orderBy('name');

        $expected = array_map(fn ($i) => sprintf('Zebra %03d', $i), range(0, 14));
        $queries  = $this->usersQueries(function () use ($make, $expected) {
            $this->assertSame($expected, $make()->paginate(15, 'page', 1)->pluck('name')->all());
        });
        $this->assertCount(1, $this->ordered($queries), implode("\n", $queries));

        $queries = $this->usersQueries(function () use ($make) {
            $this->assertSame(['Zebra 285', 'Zebra 286'], $make()->skip(285)->take(2)->get()->pluck('name')->all());
        });
        $this->assertCount(1, $this->ordered($queries), implode("\n", $queries));
    }

    public function test_a_tenant_where_with_fewer_matches_than_the_page_takes_one_ordered_query(): void
    {
        $this->seedLateMatches();

        $queries = $this->usersQueries(function () {
            $page = User::search('zebra')->useInvertedIndex()->where('email', 'like', '%@tenant-a.test')->orderBy('name')->paginate(15);

            $this->assertSame(['Zebra 000', 'Zebra 001', 'Zebra 002', 'Zebra 003', 'Zebra 004'], $page->pluck('name')->all());
            $this->assertSame(5, $page->total());
        });

        $this->assertCount(1, $this->ordered($queries), implode("\n", $queries));
    }

    public function test_scout_matches_that_sort_last_take_one_ordered_query(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        $this->seedLateMatches(WalkScoutUser::class);
        config(['scout.driver' => 'fuzzy-search']);
        $make = fn () => (new \Laravel\Scout\Builder(new WalkScoutUser, 'zebra'))->orderBy('name');

        $expected = array_map(fn ($i) => sprintf('Zebra %03d', $i), range(0, 14));

        foreach (['get' => fn () => $make()->take(15)->get(), 'paginate' => fn () => $make()->paginate(15, 'page', 1)->items()] as $terminal => $call) {
            $queries = $this->usersQueries(function () use ($call, $expected, $terminal) {
                $this->assertSame($expected, collect($call())->pluck('name')->all(), $terminal);
            });
            $this->assertCount(1, $this->ordered($queries), "{$terminal}\n" . implode("\n", $queries));
        }

        $queries = $this->usersQueries(fn () => $this->assertSame([], $make()->paginate(15, 'page', 1000)->items()));
        $this->assertSame([], $this->ordered($queries), 'Scout page 1000');
    }

    /**
     * Ruling ER-82: an index on another connection than the model's cannot be joined, so the
     * ordered window is the top max_candidates ranked ids, listed.
     */
    public function test_a_model_on_another_connection_orders_the_top_max_candidates_matches(): void
    {
        config(['database.connections.fuzzy_walk_other' => config('database.connections.' . config('database.default'))]);
        Schema::connection('fuzzy_walk_other')->dropIfExists('walk_users');
        Schema::connection('fuzzy_walk_other')->create('walk_users', function ($table) {
            $table->id();
            $table->string('name');
        });
        foreach (['Zebra b', 'Zebra d', 'Zebra a', 'Zebra c', 'Zebra e'] as $name) {
            DB::connection('fuzzy_walk_other')->table('walk_users')->insert(['name' => $name]);
        }
        app(IndexManager::class)->indexBatch(WalkOtherConnectionUser::all());

        config(['fuzzy-search.bm25.candidate_chunk' => 1, 'fuzzy-search.max_candidates' => 3]);

        DB::connection('fuzzy_walk_other')->flushQueryLog();
        DB::connection('fuzzy_walk_other')->enableQueryLog();
        $names = WalkOtherConnectionUser::search('zebra')->useInvertedIndex()->orderBy('name')->get()->pluck('name')->all();
        $walk  = array_filter(array_column(DB::connection('fuzzy_walk_other')->getQueryLog(), 'query'), fn ($sql) => stripos($sql, 'order by') !== false);
        DB::connection('fuzzy_walk_other')->disableQueryLog();

        // Equal scores rank by key: the first three rows inserted, then in name order.
        $this->assertSame(['Zebra a', 'Zebra b', 'Zebra d'], $names);
        $this->assertCount(1, $walk);
        $this->assertStringNotContainsString('fuzzy_index', implode(' ', $walk));
    }
}
