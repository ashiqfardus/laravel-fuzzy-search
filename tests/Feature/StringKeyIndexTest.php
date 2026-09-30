<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A string primary key whose values mix all-digit and other strings. */
class StringKeyItem extends Model
{
    use Searchable;

    protected $table      = 'string_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    public $timestamps    = false;
    protected $guarded    = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

class StringKeyScoutItem extends Model
{
    use \Laravel\Scout\Searchable, Searchable {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table      = 'string_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    public $timestamps    = false;
    protected $guarded    = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * N6 (pre-existing). A ranking is keyed by model_id, and PHP turns an all-digit key ('42') into an
 * int array key. Bound as an int against a string key column, SQL Server converts the whole
 * nvarchar column to int and fails on its first other key: "Conversion failed when converting the
 * nvarchar value 'abc-1' to data type int" (22018), so every index search of such a model failed.
 * Keys are bound as strings wherever the index path relates model_id to a string key.
 */
class StringKeyIndexTest extends TestCase
{
    private const KEYS = ['42', '7001', 'abc-1', '99999', 'K-5', '00123', '8', 'x9', '1000', 'b-2', '77', 'z-last'];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('string_key_items');
        Schema::create('string_key_items', function ($table) {
            $table->string('code', 36)->primary();
            $table->string('name');
        });
        foreach (self::KEYS as $i => $code) {
            DB::table('string_key_items')->insert(['code' => $code, 'name' => sprintf('Zebra %02d', $i + 1)]);
        }
        DB::table('string_key_items')->insert(['code' => '5', 'name' => 'Aardvark']);

        app(IndexManager::class)->indexBatch(StringKeyItem::all());
        app(IndexManager::class)->indexBatch(StringKeyScoutItem::all());
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('string_key_items');
        parent::tearDown();
    }

    /** @return string[] */
    private function names(iterable $rows, bool $sorted = true): array
    {
        $names = collect($rows instanceof \Illuminate\Contracts\Pagination\Paginator ? $rows->items() : $rows)->pluck('name')->all();
        if ($sorted) {
            sort($names);
        }

        return $names;
    }

    public function test_every_index_path_reads_mixed_string_keys(): void
    {
        $all  = array_map(fn ($i) => sprintf('Zebra %02d', $i), range(1, 12));
        $make = fn () => StringKeyItem::search('zebra')->typoTolerance(0)->useInvertedIndex();

        foreach ([200 => 'ids in the query', 5 => 'past one chunk'] as $chunk => $walk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);

            $this->assertSame($all, $this->names($make()->get()), "{$walk}: get");
            $this->assertSame($all, $this->names([...$make()->paginate(5, 'page', 1)->items(), ...$make()->paginate(5, 'page', 2)->items(), ...$make()->paginate(5, 'page', 3)->items()]), "{$walk}: paginate");
            $this->assertSame(12, $make()->count(), "{$walk}: count");
            $this->assertSame(11, $make()->where('name', '!=', 'Zebra 01')->paginate(15)->total(), "{$walk}: a constrained total");
            $this->assertSame($all, $this->names($make()->orderBy('name')->get(), false), "{$walk}: orderBy");
            $this->assertSame(array_slice($all, 1), $this->names($make()->where('name', '!=', 'Zebra 01')->orderBy('name')->get(), false), "{$walk}: orderBy under a where()");

            $make()->cache()->get();
            $this->assertSame($all, $this->names($make()->cache()->get()), "{$walk}: a cache hit");
        }

        // A ranking capped at bm25.max_postings_per_term: the postings subquery restricts the
        // ordered read on every database (a whole one past one chunk is listed on MySQL, MariaDB
        // and SQLite, ruling ER-132).
        config(['fuzzy-search.bm25.max_postings_per_term' => 5]);
        $this->assertSame($all, $this->names($make()->orderBy('name')->get(), false), 'capped ranking: orderBy');
        $this->assertSame(array_slice($all, 1), $this->names($make()->where('name', '!=', 'Zebra 01')->orderBy('name')->get(), false), 'capped ranking: orderBy under a where()');
    }

    /** A search over a union of two parts, 12 string-keyed matches in all, and the most bindings any statement of $read sent. */
    private function overAUnion(\Closure $read): array
    {
        config(['fuzzy-search.bm25.candidate_chunk' => 5, 'fuzzy-search.max_candidates' => 5]);
        $make = fn () => StringKeyItem::search('zebra')->typoTolerance(0)->useInvertedIndex()->where('name', '<', 'Zebra 07')
            ->query(fn ($query) => $query->unionAll(StringKeyItem::query()->where('name', '>=', 'Zebra 07')));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows     = $read($make);
        $bindings = max(array_map(fn (array $query) => count($query['bindings']), DB::getQueryLog()));
        DB::disableQueryLog();

        return [$rows, $bindings];
    }

    /**
     * An ordered read of a string ranking over a union is never a bound list past one chunk on MySQL
     * or MariaDB (ruling ER-132's lists), since MySQL compares a bound list with the union's derived
     * table row by row: at 34,000 matches an ordered page took 54 s where the postings subquery
     * takes 0.5 s. It goes through the subquery there.
     */
    public function test_an_ordered_string_ranking_over_a_union_is_not_bound_past_one_chunk_on_mysql(): void
    {
        [$rows, $bindings] = $this->overAUnion(fn (\Closure $make) => array_merge(...array_map(fn (int $page) => $make()->orderBy('name')->paginate(4, 'page', $page)->items(), [1, 2, 3])));

        $this->assertSame(array_map(fn ($i) => sprintf('Zebra %02d', $i), range(1, 12)), $this->names($rows, false));

        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->assertLessThan(count(self::KEYS), $bindings, 'a chunk of ids at most, beside the two where()s: never the 12 ranked ids');
        }
    }

    public function test_scout_reads_mixed_string_keys(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search']);
        $all  = array_map(fn ($i) => sprintf('Zebra %02d', $i), range(1, 12));
        $make = fn () => new \Laravel\Scout\Builder(new StringKeyScoutItem, 'zebra');

        foreach ([200 => 'ids in the query', 5 => 'past one chunk'] as $chunk => $walk) {
            config(['fuzzy-search.bm25.candidate_chunk' => $chunk]);

            $this->assertSame($all, $this->names($make()->take(20)->get()), "{$walk}: get");
            $this->assertSame($all, $this->names($make()->paginate(20)), "{$walk}: paginate");
            $this->assertSame($all, $this->names($make()->orderBy('name')->take(20)->get(), false), "{$walk}: orderBy");
            $this->assertSame(array_slice($all, 1), $this->names($make()->query(fn ($q) => $q->where('name', '!=', 'Zebra 01'))->orderBy('name')->paginate(20), false), "{$walk}: orderBy under a query() where");
            $this->assertSame(['42', '7001'], $make()->query(fn ($q) => $q->where('name', '!=', 'Zebra 03'))->orderBy('name')->take(2)->keys()->all(), "{$walk}: keys() are the model's own");
        }

        // A capped ranking: the postings subquery restricts the ordered read.
        config(['fuzzy-search.bm25.max_postings_per_term' => 5]);
        $this->assertSame($all, $this->names($make()->orderBy('name')->take(20)->get(), false), 'capped ranking: orderBy');
        $this->assertSame(array_slice($all, 1), $this->names($make()->query(fn ($q) => $q->where('name', '!=', 'Zebra 01'))->orderBy('name')->paginate(20), false), 'capped ranking: orderBy under a query() where');
    }
}
