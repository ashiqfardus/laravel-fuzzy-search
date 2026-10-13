<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/** A user whose indexing fails inside the write's transaction. */
class DeadlockPriorityFailingUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];

    protected array $searchable = ['columns' => ['name' => 1]];

    public function searchableText(): array
    {
        throw new \RuntimeException('the hook failed');
    }
}

/**
 * SQL Server: an index write the package owns runs at DEADLOCK_PRIORITY LOW (ruling ER-199), so in a
 * deadlock with a transaction of yours that waits for the index write lock (an index write inside
 * it), or with a read of yours, SQL Server rolls the package's write back, which retries, rather
 * than yours. Inside a transaction of yours the priority stays yours. The session's own priority
 * comes back after the write, whether it succeeded or failed.
 */
class IndexWriteDeadlockPriorityTest extends TestCase
{
    private function priority(): int
    {
        return (int) DB::scalar('SELECT deadlock_priority FROM sys.dm_exec_sessions WHERE session_id = @@SPID');
    }

    /** @return list<string> every statement $run sends */
    private function statements(\Closure $run): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql) {
            $sql[] = $query->sql;
        });
        try {
            $run();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $sql;
    }

    private static function position(array $sql, string $pattern): ?int
    {
        foreach ($sql as $i => $statement) {
            if (preg_match($pattern, $statement)) {
                return $i;
            }
        }

        return null;
    }

    public function test_a_write_the_package_owns_runs_at_a_low_priority_then_gives_the_session_its_own_back(): void
    {
        $id = (int) User::query()->value('id');

        if ($this->dbDriver !== 'sqlsrv') {
            $sql = $this->statements(fn () => app(IndexManager::class)->syncModel(User::class, $id));
            $this->assertSame([], preg_grep('/deadlock_priority/i', $sql), 'SQL Server only');

            return;
        }

        DB::unprepared('SET DEADLOCK_PRIORITY HIGH');
        try {
            $this->assertSame(5, $this->priority(), 'an unprepared SET holds for the session');

            $sql = $this->statements(fn () => app(IndexManager::class)->syncModel(User::class, $id));

            $low     = self::position($sql, '/^SET DEADLOCK_PRIORITY LOW$/');
            $lock    = self::position($sql, '/sp_getapplock/');
            $restore = self::position($sql, '/^SET DEADLOCK_PRIORITY 5$/');
            $this->assertNotNull($low, 'LOW before the write');
            $this->assertNotNull($lock);
            $this->assertNotNull($restore, 'the session\'s own priority after it');
            $this->assertTrue($low < $lock && $lock < $restore, implode("\n", $sql));
            $this->assertSame(5, $this->priority());
            $this->assertSame(1, DB::table('fuzzy_index_documents')->where('model_type', User::class)->where('model_id', (string) $id)->count());
        } finally {
            DB::unprepared('SET DEADLOCK_PRIORITY NORMAL');
        }
    }

    public function test_a_write_inside_your_transaction_keeps_your_priority(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('DEADLOCK_PRIORITY is SQL Server\'s (ER-199).');
        }

        $id  = (int) User::query()->value('id');
        $sql = $this->statements(fn () => DB::transaction(fn () => app(IndexManager::class)->syncModel(User::class, $id)));

        $this->assertSame([], preg_grep('/deadlock_priority/i', $sql), 'the package cannot retry your transaction');
        $this->assertSame(0, $this->priority());
    }

    public function test_a_failed_write_still_gives_the_session_its_own_priority_back(): void
    {
        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('DEADLOCK_PRIORITY is SQL Server\'s (ER-199).');
        }

        $id = (int) User::query()->value('id');
        DB::unprepared('SET DEADLOCK_PRIORITY -3');
        try {
            try {
                app(IndexManager::class)->syncModel(DeadlockPriorityFailingUser::class, $id);
                $this->fail('the hook throws');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('the hook failed', $e->getMessage());
            }

            $this->assertSame(-3, $this->priority());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::unprepared('SET DEADLOCK_PRIORITY NORMAL');
        }
    }
}
