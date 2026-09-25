<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** The review's table (F7): a uuid, a boolean, json and jsonb, an integer; everything in $fillable. */
class JsonMetaInvoice extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table    = 'json_invoices';
    protected $fillable = ['ref', 'paid', 'meta', 'extra', 'code_num'];
    public $timestamps  = false;
}

/**
 * Ruling ER-90 (F7): a json column is never auto-detected where the database reports its type as
 * json or jsonb (MySQL, PostgreSQL), and never where the model casts it to a json-family type, on
 * any database. SQLite, MariaDB and SQL Server store json as text (text, longtext, nvarchar(max))
 * and report it so: there, without a cast, it is a text column like any other (documented).
 */
class AutoDetectJsonColumnsTest extends TestCase
{
    private const JSON_CASTS = [
        'array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:collection',
        'encrypted:object', 'JSON', AsArrayObject::class, AsCollection::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(Schema::getFacadeRoot(), 'getColumns')) {
            $this->markTestSkipped('Schema::getColumns() arrived in Laravel 10.x; detection cannot read column types without it.');
        }

        Schema::dropIfExists('json_invoices');
        Schema::create('json_invoices', function ($table) {
            $table->id();
            $table->uuid('ref');
            $table->boolean('paid');
            $table->json('meta');
            $table->jsonb('extra');
            $table->integer('code_num');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('json_invoices');
        parent::tearDown();
    }

    public function test_a_column_the_database_reports_as_json_is_never_detected(): void
    {
        $detected = (new JsonMetaInvoice)->getSearchableColumns();
        $jsonIsText = in_array($this->dbDriver, ['sqlite', 'mariadb', 'sqlsrv'], true);

        $this->assertSame($jsonIsText, in_array('meta', $detected, true), 'json on ' . $this->dbDriver);
        $this->assertSame($jsonIsText, in_array('extra', $detected, true), 'jsonb on ' . $this->dbDriver);
        $this->assertNotContains('paid', $detected);
        $this->assertNotContains('code_num', $detected);
    }

    public function test_a_json_family_cast_is_never_detected_on_any_database(): void
    {
        foreach (self::JSON_CASTS as $cast) {
            SearchableColumns::reset();
            $model = new class extends JsonMetaInvoice {
                public static string $cast = 'array';

                public function getCasts(): array
                {
                    return ['meta' => static::$cast, 'extra' => static::$cast] + parent::getCasts();
                }
            };
            $model::$cast = $cast;

            $detected = $model->getSearchableColumns();

            $this->assertNotContains('meta', $detected, $cast);
            $this->assertNotContains('extra', $detected, $cast);
        }
    }

    public function test_a_json_cast_column_is_never_indexed_or_searched(): void
    {
        $model = new class extends JsonMetaInvoice {
            protected $casts = ['meta' => 'array', 'extra' => 'array'];
        };

        $model::query()->create([
            'ref' => '00000000-0000-4000-8000-000000000001', 'paid' => true,
            'meta' => ['note' => 'zanzibar'], 'extra' => ['note' => 'zanzibar'], 'code_num' => 7,
        ]);

        $this->assertSame(0, $model::search('zanzibar')->count());
    }
}
