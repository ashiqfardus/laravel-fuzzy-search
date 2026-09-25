<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Tests\DeclaredCastTicket;
use Ashiqfardus\LaravelFuzzySearch\Tests\ListColumnsUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\TicketStatus;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Tests\ZeroConfigAccessorUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\ZeroConfigTicket;
use Ashiqfardus\LaravelFuzzySearch\Tests\ZeroConfigUser;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__ . '/../TestModels.php';

/**
 * A model that uses the trait without declaring $searchable['columns'] — the README Quick
 * Start — must be treated as searchable everywhere, not only by search() itself:
 * getSearchableColumns() feeds the indexer, the shadow-column observer,
 * FuzzySearch::tableSearch() and extended search.
 */
class ZeroConfigSearchableTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('tickets');

        parent::tearDown();
    }

    /** A table with none of the auto-detector's priority columns. */
    private function createTicketsTable(): void
    {
        Schema::dropIfExists('tickets');
        Schema::create('tickets', function ($table) {
            $table->id();
            $table->string('status')->nullable();
            $table->text('payload')->nullable();
            $table->string('subject_line')->nullable();
            $table->timestamps();
        });
    }

    /** @return string[] the SQL of every query executed while $run ran */
    private function sqlOf(Closure $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $run();
        } finally {
            DB::disableQueryLog();
        }

        return array_column(DB::getQueryLog(), 'query');
    }

    /**
     * Schema introspection, on any driver: SQLite pragmas, MySQL/PostgreSQL information_schema,
     * PostgreSQL catalogs, SQL Server sys.columns.
     *
     * @param  string[] $sql
     * @return string[]
     */
    private function schemaQueries(array $sql): array
    {
        return array_values(array_filter(
            $sql,
            fn (string $query) => (bool) preg_match('/pragma|information_schema|pg_catalog|pg_attribute|pg_class|sys\.columns|sqlite_master/i', $query)
        ));
    }

    public function test_zero_config_model_reports_its_auto_detected_columns(): void
    {
        $this->assertSame(['name', 'email'], (new ZeroConfigUser)->getSearchableColumns());
    }

    public function test_list_form_columns_report_names_not_positions(): void
    {
        $this->assertSame(['name', 'email'], (new ListColumnsUser)->getSearchableColumns());
    }

    public function test_weighted_columns_still_report_their_keys(): void
    {
        $this->assertSame(['name', 'email'], (new User)->getSearchableColumns());
    }

    public function test_zero_config_model_is_indexed(): void
    {
        app(IndexManager::class)->indexModel(ZeroConfigUser::query()->first());

        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_list_form_model_is_indexed(): void
    {
        app(IndexManager::class)->indexModel(ListColumnsUser::query()->first());

        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_table_search_applies_a_predicate_for_a_zero_config_model(): void
    {
        $query = ZeroConfigUser::query();
        $before = $query->toSql();

        FuzzySearch::tableSearch()($query, 'john');

        $this->assertNotSame($before, $query->toSql(), 'tableSearch() added no predicate');

        $names = $query->pluck('name')->all();
        $this->assertContains('John Doe', $names);
        $this->assertNotContains('Charlie Brown', $names);
    }

    public function test_shadow_columns_are_maintained_for_a_zero_config_model(): void
    {
        Schema::table('users', fn ($table) => $table->string('name_metaphone')->nullable());
        SearchableObserver::resetColumnCache();

        $zero = ZeroConfigUser::create(['name' => 'Stephen', 'email' => 'stephen@example.com']);

        $this->assertSame(
            metaphone('Stephen'),
            DB::table('users')->where('id', $zero->getKey())->value('name_metaphone')
        );
    }

    public function test_rebuild_warns_when_no_records_produced_index_terms(): void
    {
        // Searchable columns present but empty: every row is skipped, so "Done." alone would
        // report an index that was never written.
        DB::table('users')->update(['name' => '', 'email' => '']);
        $total = DB::table('users')->count();

        $this->artisan('fuzzy-search:rebuild', ['model' => ZeroConfigUser::class])
            ->expectsOutputToContain("Indexed 0 of {$total} records")
            ->assertSuccessful();

        $this->assertSame(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_rebuild_reports_what_it_indexed(): void
    {
        $total = DB::table('users')->count();

        $this->artisan('fuzzy-search:rebuild', ['model' => ZeroConfigUser::class])
            ->expectsOutputToContain("Done. Indexed {$total} of {$total} records.")
            ->assertSuccessful();

        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());
    }

    public function test_mixed_form_columns_report_every_name(): void
    {
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

            protected $table = 'users';

            protected array $searchable = [
                'columns' => ['name' => 10, 'email'],
            ];
        };

        $this->assertSame(['name', 'email'], $model->getSearchableColumns());
    }

    public function test_auto_detection_skips_columns_that_cannot_be_indexed_as_text(): void
    {
        $this->createTicketsTable();
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        // An enum cast and an array cast are not text; before this guard they reached the
        // indexer and threw straight out of save().
        $ticket = ZeroConfigTicket::create([
            'status'       => TicketStatus::Open,
            'payload'      => ['sku' => 'A-1'],
            'subject_line' => 'Broken widget',
        ]);

        $this->assertSame(['subject_line'], (new ZeroConfigTicket)->getSearchableColumns());
        $this->assertGreaterThan(
            0,
            DB::table('fuzzy_index_postings')->where('model_id', $ticket->getKey())->count(),
            'the one text column was not indexed'
        );
    }

    public function test_a_declared_column_that_is_not_text_names_the_column(): void
    {
        $this->createTicketsTable();

        $ticket = DeclaredCastTicket::create([
            'status'       => TicketStatus::Open,
            'payload'      => ['sku' => 'A-1'],
            'subject_line' => 'Broken widget',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('searchable column "status"');

        app(IndexManager::class)->indexModel($ticket);
    }

    public function test_column_detection_is_resolved_once_per_model_class(): void
    {
        config(['fuzzy-search.indexing.enabled' => false]);

        ZeroConfigUser::create(['name' => 'First', 'email' => 'first@example.com']);

        $sql = $this->sqlOf(fn () => ZeroConfigUser::create(['name' => 'Second', 'email' => 'second@example.com']));

        $this->assertSame([], $this->schemaQueries($sql), 'a save re-introspected the schema');
    }

    public function test_index_batch_does_not_introspect_the_schema_per_row(): void
    {
        $models = ZeroConfigUser::all();

        $sql = $this->sqlOf(fn () => app(IndexManager::class)->indexBatch($models));

        $this->assertLessThanOrEqual(
            2,
            count($this->schemaQueries($sql)),
            'schema introspection scaled with the number of rows indexed'
        );
        $this->assertGreaterThan(0, DB::table('fuzzy_index_postings')->count());
    }


    public function test_an_auto_detected_value_that_is_not_text_is_skipped_not_thrown(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);

        // The accessor returns an object, but an auto-detected column is read as stored (ER-32),
        // so the accessor never runs: both columns are indexed and the save does not throw.
        $user = ZeroConfigAccessorUser::create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $indexed = DB::table('fuzzy_index_postings')
            ->where('model_id', $user->getKey())
            ->pluck('column_name')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['email', 'name'], $indexed);

        // A stored value that is still not text is skipped, never thrown: nobody asked for that
        // column to be indexed — detection picked it. An unsaved instance, which the indexer
        // takes as given (a saved one is re-read from the database, ER-68).
        $copy = (new ZeroConfigAccessorUser())->setRawAttributes(['id' => $user->getKey(), 'name' => new \stdClass, 'email' => 'ada@example.com']);
        app(IndexManager::class)->indexModel($copy);

        $this->assertSame(['email'], DB::table('fuzzy_index_postings')->where('model_id', $user->getKey())->distinct()->pluck('column_name')->all());
    }

    public function test_an_empty_detection_is_not_cached(): void
    {
        Schema::dropIfExists('tickets');

        $this->assertSame([], (new ZeroConfigTicket)->getSearchableColumns());

        $this->createTicketsTable();

        $this->assertSame(
            ['subject_line'],
            (new ZeroConfigTicket)->getSearchableColumns(),
            'a detection that ran before the table existed was cached forever'
        );
    }

    public function test_detection_reads_the_models_own_connection(): void
    {
        // Always SQLite, whatever the suite's default connection is: the point is that the model's
        // own connection is used, not that a second server exists.
        config(['database.connections.tenant' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

        Schema::connection('tenant')->create('users', function ($table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

            protected $connection = 'tenant';
            protected $table = 'users';
        };

        $this->assertSame(['title'], $model->getSearchableColumns());
    }
}
