<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Latin1KeyItem extends Model
{
    use Searchable;

    protected $table      = 'latin1_key_items';
    protected $primaryKey = 'code';
    protected $keyType    = 'string';
    protected $guarded    = [];
    public $incrementing  = false;
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['name' => 10]];
}

/** MySQL: a model whose string key lives on a latin1 table indexes, searches and re-indexes, and its keys round-trip. */
class MySqlLatin1StringKeyTest extends TestCase
{
    private const KEYS = ['ab-1', 'Cd_2', 'ef.3'];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver !== 'mysql') {
            $this->markTestSkipped('MySQL only: the latin1 table charset is checked there.');
        }

        Schema::dropIfExists('latin1_key_items');
        DB::statement('create table latin1_key_items (code varchar(40) not null primary key, name varchar(100) not null) engine=InnoDB default charset=latin1');
        DB::table('latin1_key_items')->insert([
            ['code' => 'ab-1', 'name' => 'apple pie'],
            ['code' => 'Cd_2', 'name' => 'banana bread'],
            ['code' => 'ef.3', 'name' => 'cherry tart'],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->dbDriver === 'mysql') {
            Schema::dropIfExists('latin1_key_items');
        }

        parent::tearDown();
    }

    private function find(string $term): array
    {
        return Latin1KeyItem::search($term)->useInvertedIndex()->typoTolerance(0)->get()->pluck('code')->all();
    }

    public function test_keys_round_trip_through_index_search_and_reindex(): void
    {
        $this->assertStringContainsString('latin1', (string) DB::selectOne("select character_set_name as c from information_schema.columns where table_schema = database() and table_name = 'latin1_key_items' and column_name = 'code'")->c);

        app(IndexManager::class)->indexBatch(Latin1KeyItem::all());

        $this->assertEqualsCanonicalizing(self::KEYS, DB::table('fuzzy_index_documents')->where('model_type', Latin1KeyItem::class)->pluck('model_id')->all());
        $this->assertSame(['ab-1'], $this->find('apple'));
        $this->assertSame(['Cd_2'], $this->find('banana'));
        $this->assertSame(['ef.3'], $this->find('cherry'));

        DB::table('latin1_key_items')->where('code', 'Cd_2')->update(['name' => 'grape juice']);
        app(IndexManager::class)->indexModel(Latin1KeyItem::find('Cd_2'));

        $this->assertSame([], $this->find('banana'));
        $this->assertSame(['Cd_2'], $this->find('grape'));
        $this->assertSame('Cd_2', $this->find('grape')[0]);
        $this->assertEqualsCanonicalizing(self::KEYS, DB::table('fuzzy_index_documents')->where('model_type', Latin1KeyItem::class)->pluck('model_id')->all());
    }
}
