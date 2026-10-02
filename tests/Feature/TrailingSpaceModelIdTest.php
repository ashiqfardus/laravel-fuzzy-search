<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PadKeyItem extends Model
{
    use Searchable;

    protected $table      = 'pad_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

/**
 * TE-2 (round 12). The widen migration gave model_id utf8mb4_bin on MySQL and MariaDB so keys
 * compare byte-wise, but utf8mb4_bin is a PAD SPACE collation: 'SKU1' and 'SKU1 ', two rows under a
 * NO PAD key column (MySQL 8's default utf8mb4_0900_ai_ci, MariaDB's *_nopad_*), were one index
 * document. The second save took the first's document and postings, the first row was never found,
 * total_docs counted one, and deleting either removed both. model_id now takes a NO PAD binary
 * collation: utf8mb4_0900_bin on MySQL, utf8mb4_nopad_bin on MariaDB.
 */
class TrailingSpaceModelIdTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/2026_09_20_000001_widen_model_id_on_fuzzy_index_tables.php';

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver === 'sqlsrv') {
            $this->markTestSkipped('SQL Server compares strings padded with trailing spaces under every collation, so its own key column cannot hold both keys.');
        }

        // The model's own key column keeps the two keys apart.
        $collation = match ($this->dbDriver) {
            'pgsql'   => 'C',
            'mysql'   => 'utf8mb4_0900_bin',
            'mariadb' => 'utf8mb4_nopad_bin',
            default   => 'BINARY',
        };
        Schema::dropIfExists('pad_key_items');
        Schema::create('pad_key_items', function (Blueprint $table) use ($collation) {
            $table->string('code', 40)->collation($collation)->primary();
            $table->string('name');
        });
        PadKeyItem::insert([['code' => 'SKU1', 'name' => 'red kettle'], ['code' => 'SKU1 ', 'name' => 'blue teapot']]);
        PadKeyItem::insert(array_map(fn (int $i) => ['code' => "OTHER{$i}", 'name' => "green kettle {$i}"], range(1, 4)));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pad_key_items');

        parent::tearDown();
    }

    private function totalDocs(): int
    {
        return (int) DB::table('fuzzy_index_meta')->where('model_type', PadKeyItem::class)->value('total_docs');
    }

    public function test_keys_differing_only_by_trailing_spaces_are_two_documents(): void
    {
        foreach (PadKeyItem::all() as $item) { // one at a time, as saves arrive
            app(IndexManager::class)->indexModel($item);
        }

        $search = fn (string $term) => PadKeyItem::search($term)->useInvertedIndex()->typoTolerance(0);
        $this->assertSame(['SKU1'], $search('red')->get()->pluck('code')->all());
        $this->assertSame('SKU1', $search('red')->first()?->code);
        $this->assertSame(['SKU1'], collect($search('red')->paginate(10)->items())->pluck('code')->all());
        $this->assertSame(1, $search('red')->count());
        $this->assertSame(['SKU1 '], $search('teapot')->orderBy('name')->get()->pluck('code')->all());
        $this->assertSame(6, $this->totalDocs());

        // A capped ordered read over a derived table compares the keys through model_id's collation.
        config(['fuzzy-search.bm25.max_postings_per_term' => 1]);
        $this->assertSame(['SKU1 '], $search('teapot')->fromSub(DB::table('pad_key_items'), 'pad_key_items')->orderBy('name')->get()->pluck('code')->all());
        config(['fuzzy-search.bm25.max_postings_per_term' => 50000]);

        app(IndexManager::class)->removeFromIndex(PadKeyItem::class, 'SKU1 ');
        $this->assertSame([], $search('teapot')->get()->pluck('code')->all());
        $this->assertSame(['SKU1'], $search('red')->get()->pluck('code')->all());
        $this->assertSame(5, $this->totalDocs());
    }

    /** An install that ran the earlier build of the migration (utf8mb4_bin) gets the NO PAD collation when it runs again. */
    public function test_the_migration_moves_an_earlier_builds_utf8mb4_bin_to_no_pad(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB only: PostgreSQL and SQLite keep model_id under the database\'s own byte-wise comparison; the CI MySQL and MariaDB jobs run this.');
        }

        $prefix     = DB::connection()->getTablePrefix();
        $collations = fn () => array_values(array_unique(array_map(fn ($row) => (string) $row->c, DB::select(
            "select collation_name as c from information_schema.columns where table_schema = database() and table_name in (?, ?) and column_name = 'model_id'",
            [$prefix . 'fuzzy_index_postings', $prefix . 'fuzzy_index_documents']
        ))));
        $noPad = $this->dbDriver === 'mariadb' ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
        $this->assertSame([$noPad], $collations());

        foreach (['fuzzy_index_postings', 'fuzzy_index_documents'] as $table) {
            DB::statement('ALTER TABLE ' . DB::getQueryGrammar()->wrapTable($table) . ' MODIFY model_id VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        }
        $this->assertSame(['utf8mb4_bin'], $collations());

        (require self::MIGRATION)->up();

        $this->assertSame([$noPad], $collations());
    }
}
