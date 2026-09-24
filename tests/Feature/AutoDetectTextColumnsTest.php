<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-detection keeps only columns a LIKE can search: text types. A numeric $fillable column
 * used to be picked, and a zero-config search then failed on PostgreSQL with
 * `operator does not exist: bigint ~~* unknown`.
 */
class AutoDetectTextColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(Schema::getFacadeRoot(), 'getColumns')) {
            $this->markTestSkipped('Schema::getColumns() arrived in Laravel 10.x; detection cannot read column types without it.');
        }

        Schema::dropIfExists('typed_rows');
        Schema::dropIfExists('orders');
        Schema::create('orders', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('total', 10, 2);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        DB::table('orders')->insert([
            ['user_id' => 7, 'total' => 19.99, 'note' => 'Blue widget, gift wrapped', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 8, 'total' => 5.00, 'note' => 'Red gadget', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('typed_rows');

        parent::tearDown();
    }

    public function test_the_fillable_fallback_skips_numeric_columns(): void
    {
        $this->assertSame(['note'], (new AutoDetectOrder)->getSearchableColumns());
    }

    public function test_a_zero_config_search_runs_on_the_text_column_only(): void
    {
        // PostgreSQL rejected the numeric columns' ILIKE here before any row came back.
        $notes = AutoDetectOrder::search('widget')->get()->pluck('note')->all();

        $this->assertContains('Blue widget, gift wrapped', $notes);
    }

    public function test_the_first_column_fallback_skips_numeric_columns(): void
    {
        $this->assertSame(['note'], (new AutoDetectOrderWithoutFillable)->getSearchableColumns());
    }

    public function test_a_priority_column_that_is_not_text_is_skipped(): void
    {
        Schema::create('typed_rows', function ($table) {
            $table->id();
            $table->integer('code');
            $table->string('title');
        });

        $this->assertSame(['title'], (new AutoDetectTypedRow)->getSearchableColumns());
    }

    public function test_integer_decimal_boolean_date_and_json_columns_are_each_skipped(): void
    {
        Schema::create('typed_rows', function ($table) {
            $table->id();
            $table->integer('quantity');
            $table->decimal('amount', 8, 2);
            $table->boolean('active');
            $table->date('shipped_on');
            $table->json('payload');
            $table->string('label');
            $table->char('grade', 1);
            $table->text('remarks');
        });

        $expected = ['label', 'grade', 'remarks'];
        if (in_array($this->dbDriver, ['sqlite', 'mariadb', 'sqlsrv'], true)) {
            // JSON is a text column there (SQLite text, MariaDB longtext, SQL Server nvarchar(max)):
            // a LIKE works on it, and the schema reports it as text. MySQL and PostgreSQL have a json type.
            $expected = ['payload', 'label', 'grade', 'remarks'];
        }

        $this->assertSame($expected, (new AutoDetectTypedColumns)->getSearchableColumns());
    }
}

/** No priority column on its table; everything is in $fillable (the review's repro). */
class AutoDetectOrder extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table    = 'orders';
    protected $fillable = ['user_id', 'total', 'note'];
}

/** No priority column and no $fillable: detection falls back to the first column it may pick. */
class AutoDetectOrderWithoutFillable extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table = 'orders';
}

/** `code` is a priority column name, but an integer here. */
class AutoDetectTypedRow extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table = 'typed_rows';
}

class AutoDetectTypedColumns extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table    = 'typed_rows';
    protected $fillable = ['quantity', 'amount', 'active', 'shipped_on', 'payload', 'label', 'grade', 'remarks'];
}
