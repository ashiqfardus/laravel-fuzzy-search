<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Ruling ER-111, under an app's own DatabaseTransactions tests. Laravel 11+'s testing transaction
 * manager runs an afterCommit() callback at once when only the test's transaction is open, so a
 * check deferred to the commit ran inside the transaction and deferred itself again, until memory
 * ran out. It now never runs inside a transaction: these writes run no catalog read and no ANALYZE.
 */
class PostgresIndexStatisticsTransactionTest extends TestCase
{
    use DatabaseTransactions;

    /** The test transaction only where it is under test: DDL in setUp() commits it on MySQL and MariaDB. */
    protected function connectionsToTransact()
    {
        return $this->dbDriver === 'pgsql' ? [null] : [];
    }

    public function test_index_writes_inside_a_test_transaction_neither_analyze_nor_recurse(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('The deferred ANALYZE check is PostgreSQL\'s; the CI PostgreSQL jobs run this.');
        }

        $checks = 0;
        DB::listen(function ($query) use (&$checks) {
            $checks += (int) (str_contains($query->sql, 'pg_relation_size') || stripos($query->sql, 'analyze') === 0);
        });

        for ($i = 0; $i < 3; $i++) {
            app(IndexManager::class)->indexBatch(User::all());
        }
        DB::transaction(fn () => app(IndexManager::class)->indexBatch(User::all()));

        $this->assertSame(0, $checks);
        $this->assertSame(1, User::search('john')->useInvertedIndex()->typoTolerance(0)->count());
    }
}
