<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CaseKeyedNote extends Model
{
    use Searchable;

    protected $table   = 'case_keyed_notes';
    protected $guarded = [];
    public $timestamps = false;

    /** Two keys that differ only by case, as JSON paths can (data.Name, data.name). */
    public function searchableText(): array
    {
        return ['Label' => $this->title, 'label' => $this->body];
    }
}

/**
 * The postings name their model type and column by ids from two dictionaries (S8), which
 * IndexManager remembers per process (VJ, pass 5 of the deep review):
 *  - an id is remembered only once its row is committed: under RefreshDatabase or
 *    DatabaseTransactions, Laravel runs after-commit callbacks inside the test's own transaction,
 *    which it then rolls back, and the next test's write used an id with no row behind it (VJ-1);
 *  - on PostgreSQL, per search_path in effect: each tenant schema numbers its own (VJ-2);
 *  - a name is found as the column's collation compares it, two spellings in one write too (VJ-6);
 *  - forgotten when the dictionaries are emptied under the process: a truncate (VC-1), a new
 *    application, migrations or a schema dump (VD-6).
 */
class DictionaryIdsTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('case_keyed_notes');

        parent::tearDown();
    }

    /** VJ-1: the testing transactions manager runs after-commit callbacks at once, inside a transaction it then rolls back. */
    public function test_an_id_inserted_in_a_transaction_that_rolls_back_is_not_remembered(): void
    {
        $connection = DB::connection();
        $connection->setTransactionManager(new class () extends DatabaseTransactionsManager {
            public function addCallback($callback)
            {
                $callback(); // as Laravel's testing manager does inside the test's wrapping transaction
            }
        });

        try {
            $connection->beginTransaction();
            app(IndexManager::class)->indexBatch(User::all());
            $connection->rollBack();
        } finally {
            $connection->setTransactionManager(app('db.transactions'));
        }

        // The next test's write: the model type and the columns are inserted again.
        app(IndexManager::class)->indexBatch(User::all());
        $this->assertSame(['Jane Doe', 'John Doe'], User::search('doe')->useInvertedIndex()->typoTolerance(0)->orderBy('name')->get()->pluck('name')->all());
        $this->assertSame(IndexManager::modelTypeId(User::class), (int) DB::table('fuzzy_index_model_types')->where('model_type', User::class)->value('id'));
    }

    /** VJ-2: PostgreSQL, a search_path switched at runtime (schema-per-tenant), with types first indexed in the other order there. */
    public function test_each_search_path_reads_its_own_ids(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL only: search_path; the CI PostgreSQL job runs this.');
        }

        app(IndexManager::class)->indexBatch(User::all());
        app(IndexManager::class)->indexBatch(Product::all());

        // The tenant's index tables, made the way its own migrate makes them; then the connection
        // is configured for the public schema again, and switched to the tenant at runtime.
        $schema = 'fuzzy_ids_tenant';
        DB::statement("drop schema if exists {$schema} cascade");
        DB::statement("create schema {$schema}");
        try {
            $this->switchSearchPath($schema);
            $this->artisan('migrate', ['--path' => realpath(__DIR__ . '/../../database/migrations'), '--realpath' => true])->assertExitCode(0);
            Schema::create('users', fn (Blueprint $table) => [$table->id(), $table->string('name'), $table->string('email'), $table->timestamps(), $table->timestamp('deleted_at')->nullable()]);
            Schema::create('products', fn (Blueprint $table) => [$table->id(), $table->string('title'), $table->string('description')->nullable(), $table->decimal('price', 10, 2)->default(0), $table->timestamps()]);
            DB::table('users')->insert(['name' => 'Tenant Walrus', 'email' => 'walrus@tenant.test']);
            DB::table('products')->insert(['title' => 'Walrus mug', 'description' => 'tenant item', 'price' => 5]);
            $this->switchSearchPath('public');
            app(IndexManager::class)->indexBatch(User::all()); // the public ids, remembered again

            DB::statement("set search_path to {$schema}");

            // The tenant indexes products first: its ids are the other way round.
            app(IndexManager::class)->indexBatch(Product::all());
            app(IndexManager::class)->indexBatch(User::all());

            $this->assertSame(['Walrus mug'], Product::search('walrus')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all());
            $this->assertSame(['Tenant Walrus'], User::search('walrus')->useInvertedIndex()->typoTolerance(0)->get()->pluck('name')->all());
            $this->assertSame([Product::class, User::class], DB::table('fuzzy_index_model_types')->orderBy('id')->pluck('model_type')->all());
        } finally {
            DB::statement('set search_path to public'); // back, as the tenant switch came: at runtime
            DB::statement("drop schema if exists {$schema} cascade");
        }

        $this->assertSame(['Jane Doe', 'John Doe'], User::search('doe')->useInvertedIndex()->typoTolerance(0)->orderBy('name')->get()->pluck('name')->all(), 'the public schema, as it was');
    }

    private function switchSearchPath(string $schema): void
    {
        config(['database.connections.' . DB::getDefaultConnection() . '.search_path' => $schema]);
        DB::purge();
    }

    /** VJ-6: two keys that differ only by case, one column to a case-insensitive collation. */
    public function test_a_write_whose_column_names_differ_only_by_case_is_indexed(): void
    {
        Schema::create('case_keyed_notes', fn (Blueprint $table) => [$table->id(), $table->string('title'), $table->string('body')]);
        DB::table('case_keyed_notes')->insert(['title' => 'copper kettle', 'body' => 'enamel teapot']);

        app(IndexManager::class)->indexBatch(CaseKeyedNote::all());

        $this->assertSame(['copper kettle'], CaseKeyedNote::search('teapot')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all());
        $this->assertSame(['copper kettle'], CaseKeyedNote::search('kettle')->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all());
    }

    /**
     * Empty every index table as a test suite that truncates between tests does: Laravel's
     * truncate(), foreign keys off. SQL Server cannot truncate a table a foreign key references, so
     * there the rows are deleted and the ids reseeded, which numbers them from 1 again the same way.
     */
    private function emptyIndexTables(): void
    {
        Schema::withoutForeignKeyConstraints(function () {
            foreach (['fuzzy_index_model_terms', 'fuzzy_index_postings', 'fuzzy_index_documents', 'fuzzy_index_meta', 'fuzzy_index_terms', 'fuzzy_index_model_types', 'fuzzy_index_columns'] as $table) {
                if (DB::connection()->getDriverName() !== 'sqlsrv') {
                    DB::table($table)->truncate();
                    continue;
                }
                DB::table($table)->delete();
                if (in_array($table, ['fuzzy_index_terms', 'fuzzy_index_model_types', 'fuzzy_index_columns'], true)) {
                    DB::statement("DBCC CHECKIDENT ('" . DB::getTablePrefix() . $table . "', RESEED, 0)");
                }
            }
        });
    }

    /** @return array<string, list<string>> each model's index search for a word only the other model holds, and for its own */
    private function searches(): array
    {
        $titles = fn (string $model, string $term, string $column) => $model::search($term)->useInvertedIndex()->typoTolerance(0)->get()->pluck($column)->sort()->values()->all();

        return [
            'users john'     => $titles(User::class, 'john', 'name'),
            'products john'  => $titles(Product::class, 'john', 'title'),
            'products apple' => $titles(Product::class, 'apple', 'title'),
            'users apple'    => $titles(User::class, 'apple', 'name'),
        ];
    }

    public static function emptyings(): array
    {
        return [
            'a truncate in the process'                       => ['truncate'],
            'DatabaseTruncation, then the next test\'s app'   => ['next application'],
            'migrations ran in the process'                   => ['migrations'],
            'a schema dump loaded in the process'             => ['schema'],
        ];
    }

    /**
     * VC-1, VD-6. The ids are remembered per process. Emptied under it (a truncate between two
     * tests, a migrate:fresh that loads a schema dump), the dictionaries numbered the names again
     * from 1, and a remembered name kept its old id: the users' postings went under the id the
     * products had just been given, so the products' index search served users' words, or, a user
     * indexed first, the write failed on the foreign key and the save went unindexed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyings')]
    public function test_the_ids_are_forgotten_when_the_dictionaries_are_emptied_under_the_process(string $how): void
    {
        $index    = app(IndexManager::class);
        $index->indexBatch(User::query()->get());
        $index->indexBatch(Product::query()->get());
        $expected = $this->searches();
        $this->assertNotSame([], $expected['users john']);
        $this->assertSame([], $expected['products john']);
        $this->assertNotSame([], $expected['products apple']);

        $empty = function () use ($how) {
            if ($how === 'truncate') {
                $this->emptyIndexTables();

                return;
            }

            // As DatabaseTruncation does: no query event reaches a listener while it truncates.
            $dispatcher = DB::connection()->getEventDispatcher();
            DB::connection()->unsetEventDispatcher();
            $this->emptyIndexTables();
            DB::connection()->setEventDispatcher($dispatcher);

            match ($how) {
                'next application' => (new \Ashiqfardus\LaravelFuzzySearch\FuzzySearchServiceProvider($this->app))->register(),
                'migrations'       => event(new \Illuminate\Database\Events\MigrationsEnded('up')),
                'schema'           => event(new \Illuminate\Database\Events\SchemaLoaded(DB::connection(), 'database/schema/testing-schema.sql')),
            };
        };

        // A user first: its remembered type id has no row behind it.
        $empty();
        $index->indexBatch(User::query()->get());
        $this->assertSame($expected['users john'], $this->searches()['users john'], 'indexed after the tables were emptied');

        // A product first, then a user: the product takes id 1 again.
        $empty();
        $index->indexBatch(Product::query()->get());
        $index->indexBatch(User::query()->get());
        $this->assertSame($expected, $this->searches());
        $this->assertSame(2, DB::table('fuzzy_index_model_types')->count());
        $this->assertSame(0, DB::table('fuzzy_index_postings')->whereNotIn('model_type_id', DB::table('fuzzy_index_model_types')->select('id'))->count());
        $this->assertSame(0, DB::table('fuzzy_index_postings')->whereNotIn('column_id', DB::table('fuzzy_index_columns')->select('id'))->count());
    }
}
