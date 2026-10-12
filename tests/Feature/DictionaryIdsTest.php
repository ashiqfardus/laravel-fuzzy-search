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
 *  - a name is found as the column's collation compares it, two spellings in one write too (VJ-6).
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
            $this->switchSearchPath('public');
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
}
