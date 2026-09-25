<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A string key column with a case-sensitive collation of its own, not the database's. */
class CollatedKeyItem extends Model
{
    use Searchable;

    protected $table      = 'collated_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    public $timestamps    = false;
    protected $guarded    = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

class CollatedKeyScoutItem extends Model
{
    use \Laravel\Scout\Searchable, Searchable {
        Searchable::search insteadof \Laravel\Scout\Searchable;
        Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
    }

    protected $table      = 'collated_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    public $incrementing  = false;
    public $timestamps    = false;
    protected $guarded    = [];

    protected array $searchable = ['columns' => ['name' => 1]];
}

/**
 * L3 (round 8). Past one candidate chunk the index path compares model_id with the key cast to a
 * string. On SQL Server the cast kept the key column's collation, so a key column collated unlike
 * the database (Latin1_General_100_BIN2 against SQL_Latin1_General_CP1_CI_AS) failed with "Cannot
 * resolve the collation conflict" on every ordered or constrained search. The other databases
 * never failed; this runs on all five.
 */
class KeyCollationIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $collation = match ($this->dbDriver) {
            'sqlsrv'           => 'Latin1_General_100_BIN2',
            'pgsql'            => 'C',
            'mysql', 'mariadb' => 'utf8mb4_bin',
            default            => 'BINARY',
        };

        Schema::dropIfExists('collated_key_items');
        Schema::create('collated_key_items', function ($table) use ($collation) {
            $table->string('code', 40)->collation($collation)->primary();
            $table->string('name');
            $table->string('shelf');
        });

        DB::table('collated_key_items')->insert(array_map(
            fn ($i) => ['code' => sprintf('Key%04d', $i), 'name' => sprintf('widget %04d', $i), 'shelf' => $i % 50 === 0 ? 'top' : 'low'],
            range(0, 249)
        ));

        foreach ([CollatedKeyItem::class, CollatedKeyScoutItem::class] as $class) {
            app(IndexManager::class)->indexBatch($class::all());
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('collated_key_items');

        parent::tearDown();
    }

    public function test_the_index_path_reads_past_one_chunk_under_a_key_collation_of_its_own(): void
    {
        config(['fuzzy-search.bm25.candidate_chunk' => 20]);
        $make = fn () => CollatedKeyItem::search('widget')->typoTolerance(0)->useInvertedIndex();

        $this->assertSame(['widget 0000', 'widget 0001', 'widget 0002'], $make()->orderBy('name')->take(3)->get()->pluck('name')->all());
        $this->assertSame(['widget 0015', 'widget 0016'], $make()->orderBy('name')->paginate(15, 'page', 2)->take(2)->pluck('name')->all());
        $this->assertSame(250, $make()->orderBy('name')->count());

        $top = $make()->where('shelf', 'top')->paginate(15);
        $this->assertSame(5, $top->total());
        $this->assertEqualsCanonicalizing(['Key0000', 'Key0050', 'Key0100', 'Key0150', 'Key0200'], $top->pluck('code')->all());
    }

    public function test_scout_orders_past_one_chunk_under_a_key_collation_of_its_own(): void
    {
        if (!class_exists(\Laravel\Scout\EngineManager::class)) {
            $this->markTestSkipped('laravel/scout not installed.');
        }

        config(['scout.driver' => 'fuzzy-search', 'fuzzy-search.bm25.candidate_chunk' => 20]);
        $page = (new \Laravel\Scout\Builder(new CollatedKeyScoutItem, 'widget'))->orderBy('name')->paginate(3);

        $this->assertSame(250, $page->total());
        $this->assertSame(['widget 0000', 'widget 0001', 'widget 0002'], collect($page->items())->pluck('name')->all());
    }
}
