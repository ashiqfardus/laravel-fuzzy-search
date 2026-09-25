<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CaseKeyItem extends Model
{
    use Searchable;

    protected $table      = 'case_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

/**
 * Keys that differ only by case (sqids, hashids, base62: aBc and AbC) are two models. MySQL and
 * MariaDB compared model_id under the connection's case-insensitive collation, so the second
 * key overwrote the first key's document: one row became unfindable and total_docs drifted.
 * The widen migration gives model_id utf8mb4_bin there, as term already has.
 *
 * SQL Server keeps its case-insensitive default collation on model_id, a documented limit, and
 * PostgreSQL and SQLite compare it byte-wise already.
 */
class CaseSensitiveModelIdTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/2026_09_20_000001_widen_model_id_on_fuzzy_index_tables.php';

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver === 'sqlsrv') {
            $this->markTestSkipped('SQL Server keeps model_id case-insensitive (a documented limit): keys that differ only by case cannot both be indexed there.');
        }

        // The model's own key column compares byte-wise, so it holds both keys.
        $collation = match ($this->dbDriver) {
            'pgsql'            => 'C',
            'mysql', 'mariadb' => 'utf8mb4_bin',
            default            => 'BINARY',
        };
        Schema::dropIfExists('case_key_items');
        Schema::create('case_key_items', function (Blueprint $table) use ($collation) {
            $table->string('code', 40)->collation($collation)->primary();
            $table->string('name');
        });
        CaseKeyItem::insert([['code' => 'aBc', 'name' => 'apple'], ['code' => 'AbC', 'name' => 'banana']]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('case_key_items');

        parent::tearDown();
    }

    private function totalDocs(): int
    {
        return (int) DB::table('fuzzy_index_meta')->where('model_type', CaseKeyItem::class)->value('total_docs');
    }

    public function test_keys_differing_only_by_case_are_indexed_and_found_apart(): void
    {
        app(IndexManager::class)->indexBatch(CaseKeyItem::all());
        foreach (CaseKeyItem::all() as $item) { // the per-model path upserts by key as well
            app(IndexManager::class)->indexModel($item);
        }

        $search = fn (string $term) => CaseKeyItem::search($term)->useInvertedIndex()->typoTolerance(0);
        foreach (['apple' => 'aBc', 'banana' => 'AbC'] as $term => $code) {
            $this->assertSame([$code], $search($term)->get()->pluck('code')->all(), $term);
            $this->assertSame($code, $search($term)->first()?->code, $term);
            $this->assertSame([$code], collect($search($term)->paginate(10)->items())->pluck('code')->all(), $term);
            $this->assertSame(1, $search($term)->count(), $term);
            $this->assertSame([$code], $search($term)->orderBy('name')->get()->pluck('code')->all(), $term); // the ordered walk
        }

        $this->assertEqualsCanonicalizing(['aBc', 'AbC'], DB::table('fuzzy_index_documents')->where('model_type', CaseKeyItem::class)->pluck('model_id')->all());
        $this->assertSame(2, $this->totalDocs());

        // Removing one key leaves the other.
        app(IndexManager::class)->removeFromIndex(CaseKeyItem::class, 'aBc');
        $this->assertSame([], $search('apple')->get()->pluck('code')->all());
        $this->assertSame(['AbC'], $search('banana')->get()->pluck('code')->all());
        $this->assertSame(1, $this->totalDocs());
    }

    /**
     * down() gives model_id its case-insensitive collation back on MySQL and MariaDB, where two
     * keys that differ only by case would violate the documents primary key. They leave the
     * index the way a too-long key does, giving back their counts; the other keys stay.
     */
    public function test_rolling_back_drops_the_keys_that_differ_only_by_case(): void
    {
        CaseKeyItem::insert(['code' => 'xyz', 'name' => 'apple cherry']);
        app(IndexManager::class)->indexBatch(CaseKeyItem::all());

        $migration = require self::MIGRATION;
        $migration->down();
        $documents = DB::table('fuzzy_index_documents')->where('model_type', CaseKeyItem::class)->pluck('model_id')->all();
        $migration->up();

        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->assertSame(['xyz'], $documents);
            $this->assertSame(1, $this->totalDocs());
            $this->assertSame(0, DB::table('fuzzy_index_postings')->whereIn('model_id', ['aBc', 'AbC'])->count());
            $this->assertSame(1, (int) DB::table('fuzzy_index_terms')->where('term', 'apple')->value('doc_count'));
            $this->assertSame(0, (int) DB::table('fuzzy_index_terms')->where('term', 'banana')->value('doc_count'));
        } else { // model_id compares byte-wise there before and after
            $this->assertEqualsCanonicalizing(['aBc', 'AbC', 'xyz'], $documents);
            $this->assertSame(3, $this->totalDocs());
        }

        $this->assertSame(['xyz'], CaseKeyItem::search('cherry')->useInvertedIndex()->typoTolerance(0)->get()->pluck('code')->all());
    }
}
