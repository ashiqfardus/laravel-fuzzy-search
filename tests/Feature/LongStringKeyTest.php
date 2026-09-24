<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LongKeyNote extends Model
{
    use Searchable;

    protected $table      = 'long_key_notes';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['body' => 10]];
}

/**
 * The index stores model_id as varchar(191) (owner decision Q17): a string primary key longer
 * than 36 characters, the old width, indexes and searches. SQLite does not enforce a varchar
 * length, so the failure this guards against shows on MySQL, MariaDB, PostgreSQL and SQL Server.
 */
class LongStringKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('long_key_notes');
        Schema::create('long_key_notes', function (Blueprint $table) {
            $table->string('code', 100)->primary();
            $table->string('body');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('long_key_notes');

        parent::tearDown();
    }

    private function key(string $char): string
    {
        return str_repeat($char, 99) . '1';
    }

    public function test_a_100_character_string_key_indexes_and_searches(): void
    {
        $first  = LongKeyNote::create(['code' => $this->key('a'), 'body' => 'quantum widget']);
        $second = LongKeyNote::create(['code' => $this->key('b'), 'body' => 'plain widget']);

        app(IndexManager::class)->indexBatch(LongKeyNote::all());
        app(IndexManager::class)->indexModel($first);

        $this->assertSame([$first->code], LongKeyNote::search('quantum')->useInvertedIndex()->typoTolerance(0)->get()->pluck('code')->all());
        $this->assertEqualsCanonicalizing([$first->code, $second->code], LongKeyNote::search('widget')->useInvertedIndex()->typoTolerance(0)->get()->pluck('code')->all());

        app(IndexManager::class)->removeFromIndex(LongKeyNote::class, $second->code);
        $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_id', $second->code)->count());

        $this->artisan('fuzzy-search:rebuild', ['model' => LongKeyNote::class, '--fresh' => true])->assertExitCode(0);
        $this->assertSame(2, DB::table('fuzzy_index_documents')->where('model_type', LongKeyNote::class)->count());
    }

    public function test_the_migration_widens_model_id_and_rolls_back(): void
    {
        // Run the migration's own down() and up(): a migrate:rollback --step would leave the rest
        // of the batch recorded for the test teardown's rollback, which only undoes the last batch.
        $migration = require __DIR__ . '/../../database/migrations/2026_09_20_000001_widen_model_id_on_fuzzy_index_tables.php';
        $long      = ['model_type' => 'App\\Models\\Long', 'model_id' => $this->key('c'), 'doc_length' => 1];

        DB::table('fuzzy_index_documents')->insert($long);
        $migration->down();

        if ($this->dbDriver !== 'sqlite') { // SQLite stores any length in a varchar column: down() leaves it be
            $this->assertSame(0, DB::table('fuzzy_index_documents')->where('model_id', $long['model_id'])->count()); // dropped: too long for 36

            try {
                DB::table('fuzzy_index_documents')->insert($long);
                $this->fail('model_id should be 36 characters wide again after the rollback');
            } catch (QueryException) {
                // too long for varchar(36)
            }
        }

        $migration->up();

        DB::table('fuzzy_index_documents')->where('model_id', $long['model_id'])->delete();
        DB::table('fuzzy_index_documents')->insert($long);
        DB::table('fuzzy_index_postings')->insert([
            'term_id'     => DB::table('fuzzy_index_terms')->insertGetId(['term' => 'widget', 'doc_count' => 1, 'term_length' => 6]),
            'model_type'  => $long['model_type'],
            'model_id'    => $long['model_id'],
            'column_name' => 'body',
            'frequency'   => 1,
        ]);
        $this->assertSame(1, DB::table('fuzzy_index_postings')->where('model_id', $long['model_id'])->count());
    }
}
