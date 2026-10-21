<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQL Server: the read of a model's postings names FORCESEEK, which needs an index led by
 * (model_type_id, model_id). While the compact postings migration has dropped postings_model_idx
 * and not yet rebuilt it, an index write that reads postings fails with error 8622 (documented
 * in the upgrade guide as the window to stop writers and run migrate again).
 */
class SqlServerReindexWithoutModelIndexTest extends TestCase
{
    private bool $dropped = false;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('SQL Server only: FORCESEEK on the postings read is a SQL Server hint.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->dropped) {
            $this->createIndex();
        }

        parent::tearDown();
    }

    private function createIndex(): void
    {
        Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->index(['model_type_id', 'model_id'], DbDialect::indexName('postings_model_idx')));
        $this->dropped = false;
    }

    public function test_a_reindex_fails_with_error_8622_while_the_model_index_is_missing_and_works_once_it_is_back(): void
    {
        app(IndexManager::class)->indexBatch(User::all());

        Schema::table('fuzzy_index_postings', fn (Blueprint $table) => $table->dropIndex(DbDialect::indexName('postings_model_idx')));
        $this->dropped = true;

        DB::table('users')->where('name', 'John Doe')->update(['name' => 'Johnathan Doe']);
        $user = User::where('name', 'Johnathan Doe')->first();

        try {
            app(IndexManager::class)->indexModel($user);
            $this->fail('The re-index read the postings without postings_model_idx.');
        } catch (\Throwable $e) {
            // The message holds the text, not the number: the driver's native code is on the PDOException.
            $pdo = $e->getPrevious() ?? $e;
            $this->assertSame(8622, (int) ($pdo->errorInfo[1] ?? 0), $e->getMessage());
        }

        $this->createIndex();
        app(IndexManager::class)->indexModel($user);
        $this->assertSame(1, User::search('johnathan')->useInvertedIndex()->typoTolerance(0)->count());
    }
}
