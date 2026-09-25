<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Declares its columns in a protected $searchable, as every Searchable model does. */
class IndexCommandNameOnlyUser extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 10]];
}

/** Scout's Searchable plus the package's, and no $searchable property (docs/integrations.md). */
class IndexCommandScoutUser extends Model
{
    use \Laravel\Scout\Searchable, \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable {
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::search insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::search as scoutSearch;
        \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::bootSearchable insteadof \Laravel\Scout\Searchable;
        \Laravel\Scout\Searchable::bootSearchable as bootScoutSearchable;
    }

    protected $table   = 'users';
    protected $guarded = [];

    protected static function booted(): void
    {
        static::bootScoutSearchable();
    }
}

/** Chooses its columns by overriding getSearchableColumns(), with no $searchable property. */
class IndexCommandOverridingUser extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    public function getSearchableColumns(): array
    {
        return ['email'];
    }
}

/**
 * The deprecated v1 `fuzzy-search:index` creates its search_index table with a FULLTEXT index,
 * which Laravel only builds on MySQL, MariaDB and PostgreSQL. Elsewhere it must stop with an
 * explanation instead of a schema-grammar exception.
 */
class IndexCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('search_index');

        parent::tearDown();
    }

    public function test_it_creates_the_legacy_table_only_where_fulltext_exists(): void
    {
        $driver = DB::connection()->getDriverName();

        if (DbDialect::isMySqlFamily($driver) || $driver === DbDialect::PGSQL) {
            $this->artisan('fuzzy-search:index', ['model' => User::class])->assertExitCode(0);
            $this->assertSame(User::count(), DB::table('search_index')->count());

            return;
        }

        $this->artisan('fuzzy-search:index', ['model' => User::class])
            ->expectsOutputToContain('fuzzy-search:rebuild')
            ->assertExitCode(1);
        $this->assertFalse(Schema::hasTable('search_index'));
    }

    private function createLegacyTable(): void
    {
        Schema::create('search_index', function ($table) {
            $table->id();
            $table->string('model');
            $table->unsignedBigInteger('model_id');
            $table->text('content');
            $table->timestamps();
        });
    }

    public function test_an_existing_table_is_used_on_every_driver(): void
    {
        $this->createLegacyTable();

        $this->artisan('fuzzy-search:index', ['model' => User::class])->assertExitCode(0);

        $this->assertSame(User::count(), DB::table('search_index')->count());
    }

    /**
     * The command read $instance->searchable from outside the model. The property is protected,
     * so the read went to Eloquent's __isset() and the declared columns were never seen; on a
     * model that also uses Scout's Searchable, __isset() resolved Scout's searchable() method
     * as a relation, which indexed the model and threw a LogicException.
     */
    public function test_it_reads_the_declared_columns_and_does_not_trip_over_scouts_searchable(): void
    {
        $this->createLegacyTable();

        config(['scout.driver' => 'null', 'scout.queue' => false]);
        $this->artisan('fuzzy-search:index', ['model' => IndexCommandScoutUser::class])->assertExitCode(0);
        $this->assertSame('John Doe john@example.com', DB::table('search_index')->where('model', IndexCommandScoutUser::class)->orderBy('model_id')->value('content')); // no columns declared: the common-column fallback

        $this->artisan('fuzzy-search:index', ['model' => IndexCommandNameOnlyUser::class])->assertExitCode(0);
        $this->assertSame('John Doe', DB::table('search_index')->where('model', IndexCommandNameOnlyUser::class)->orderBy('model_id')->value('content'));
    }

    /**
     * A model that overrides getSearchableColumns() has chosen its columns
     * (hasDeclaredSearchableColumns()), as the indexer reads them. The command skipped them for
     * the common-column fallback whenever the model declared no $searchable property.
     */
    public function test_it_indexes_the_columns_an_overridden_get_searchable_columns_returns(): void
    {
        $this->createLegacyTable();

        $this->artisan('fuzzy-search:index', ['model' => IndexCommandOverridingUser::class])->assertExitCode(0);
        $this->assertSame('john@example.com', DB::table('search_index')->where('model', IndexCommandOverridingUser::class)->orderBy('model_id')->value('content'));
    }
}
