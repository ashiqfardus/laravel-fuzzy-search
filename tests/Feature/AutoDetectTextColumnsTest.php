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
        Schema::dropIfExists('untyped_rows');
        if ($this->dbDriver === 'pgsql') {
            DB::statement('DROP TYPE IF EXISTS charge_status');
        }

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

    public function test_a_postgresql_enum_whose_name_contains_char_is_not_text(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only (CI runs it): a user-defined enum type.');
        }

        DB::statement('DROP TYPE IF EXISTS charge_status');
        DB::statement("CREATE TYPE charge_status AS ENUM ('open', 'paid')");
        Schema::create('typed_rows', function ($table) {
            $table->id();
            $table->text('remarks')->nullable();
        });
        DB::statement('ALTER TABLE typed_rows ADD COLUMN status charge_status');
        DB::table('typed_rows')->insert(['remarks' => 'open invoice', 'status' => 'open']);

        $this->assertSame(['remarks'], (new AutoDetectChargeRow)->getSearchableColumns());
        $this->assertSame(['open invoice'], AutoDetectChargeRow::search('open')->get()->pluck('remarks')->all());
    }

    /** Ruling ER-60: SQLite stores text in a column declared without a type, as 2.0 searched it. */
    public function test_an_untyped_sqlite_column_is_still_detected(): void
    {
        if ($this->dbDriver !== 'sqlite') {
            $this->markTestSkipped('SQLite only: no other database has a column without a type.');
        }

        DB::statement('CREATE TABLE untyped_rows (id integer primary key, amount integer, note)');

        $this->assertSame(['note'], (new AutoDetectUntypedRow)->getSearchableColumns());
    }

    /** Detection's column types and onTable()'s listing come from one schema read per table. */
    public function test_the_schema_is_read_once_per_table(): void
    {
        Schema::create('typed_rows', function ($table) {
            $table->id();
            $table->integer('quantity');
        });
        \Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns::reset();

        $schemaReads = function (\Closure $run): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $run();
            DB::disableQueryLog();

            return array_values(array_filter(
                array_column(DB::getQueryLog(), 'query'),
                fn (string $sql) => (bool) preg_match('/pragma|information_schema|pg_catalog|pg_attribute|pg_class|sys\.columns|sqlite_master/i', $sql)
            ));
        };

        // One getColumns() call is one read (two queries on SQLite).
        $oneRead = $schemaReads(fn () => Schema::getColumns('typed_rows'));

        // No text column: detection finds none, then hasNoSearchableColumn() lists the table.
        $reads = $schemaReads(fn () => $this->assertCount(0, AutoDetectNumericRow::search('42')->get()));

        $this->assertSame($oneRead, $reads);
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

/** A PostgreSQL enum column (charge_status) beside a text one, both in $fillable. */
class AutoDetectChargeRow extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table    = 'typed_rows';
    protected $fillable = ['status', 'remarks'];
}

class AutoDetectUntypedRow extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table    = 'untyped_rows';
    protected $fillable = ['amount', 'note'];
}

/** Numbers only: nothing to auto-detect. */
class AutoDetectNumericRow extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table = 'typed_rows';
}
