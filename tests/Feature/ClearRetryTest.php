<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

class RetryNote extends Model
{
    use Searchable;

    protected $table   = 'retry_notes';
    protected $guarded = [];
    public $timestamps = false;
    protected array $searchable = ['columns' => ['title' => 10, 'body' => 5]];
}

/**
 * A clear whose transaction is retried after its TRUNCATE ran (PostgreSQL and SQL Server truncate
 * inside it, so the deadlock rolls the TRUNCATE back). Before the retry another model gets a
 * document, so the second attempt is not alone and may not truncate: it must delete the model's
 * rows itself, not skip the tables the first attempt had truncated. clear --all is forced through
 * a retry too (it truncates on both attempts).
 */
class ClearRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('retry_notes');
        Schema::create('retry_notes', fn ($t) => [$t->id(), $t->string('title'), $t->string('body')]);
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        foreach (range(1, 40) as $i) {
            DB::table('retry_notes')->insert(['title' => "amber cedar n{$i}", 'body' => "delta ember fjord {$i}"]);
        }
        $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => RetryNote::class]));
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('retry_notes');
        parent::tearDown();
    }

    /**
     * Throw a deadlock inside the clear's transaction, so it is retried: on the first statement after a
     * TRUNCATE (or, with none, after the first DELETE), or with $onTruncate on the TRUNCATE itself.
     * Statements outside a transaction are never thrown on: nothing would retry them. Returns the log.
     */
    private function forceOneRetry(?\Closure $beforeRetry, bool $onTruncate = false): \ArrayObject
    {
        $log = new \ArrayObject(['statements' => [], 'thrown' => false, 'rolledBack' => 0, 'armed' => false]);
        DB::listen(function (QueryExecuted $q) use ($log, $onTruncate) {
            $sql = strtolower(preg_replace('/\s+/', ' ', $q->sql));
            $log['statements'] = [...$log['statements'], substr($sql, 0, 90)];
            if ($log['thrown'] || DB::transactionLevel() === 0) {
                return;
            }
            if ($log['armed'] || ($onTruncate && str_starts_with($sql, 'truncate'))) {
                $log['thrown'] = true;
                throw new DeadlockException('Deadlock found when trying to get lock (simulated)', 40001);
            }
            if (str_starts_with($sql, 'truncate') || (str_starts_with($sql, 'delete') && str_contains($sql, 'fuzzy_index_'))) {
                $log['armed'] = true;
            }
        });
        Event::listen(TransactionRolledBack::class, function () use ($log, $beforeRetry) {
            $log['rolledBack']++;
            if ($log['rolledBack'] === 1 && $beforeRetry !== null && DB::transactionLevel() === 0) {
                $beforeRetry();
            }
        });

        return $log;
    }

    private function counts(): array
    {
        return [
            'documents RetryNote' => DB::table('fuzzy_index_documents')->where('model_type', RetryNote::class)->count(),
            'postings'             => DB::table('fuzzy_index_postings')->count(),
            'meta RetryNote'      => DB::table('fuzzy_index_meta')->where('model_type', RetryNote::class)->count(),
        ];
    }

    public function test_clear_model_retried_after_its_truncate_still_empties_the_model(): void
    {
        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL and MariaDB clear a model in batches, not in one retried transaction.');
        }
        $other = ['model_type' => 'Retry\\Other', 'model_id' => '1', 'doc_length' => 1];
        $log = $this->forceOneRetry(function () use ($other) {
            DB::table('fuzzy_index_documents')->insert($other); // the retry is no longer alone
        });

        try {
            $code = Artisan::call('fuzzy-search:clear', ['model' => RetryNote::class]);
        } catch (\Throwable $e) {
            $code = 'EXC ' . class_basename($e) . ': ' . substr($e->getMessage(), 0, 160);
        }
        $counts = $this->counts();

        $this->assertTrue($log['thrown'], 'a retry was forced');
        $this->assertSame(0, $code, (string) Artisan::output());
        $this->assertSame(['documents RetryNote' => 0, 'postings' => 0, 'meta RetryNote' => 0], $counts, 'the retried clear left the model\'s index');
        $this->assertSame(1, DB::table('fuzzy_index_documents')->where('model_type', $other['model_type'])->count(), 'the other model\'s document stays');
    }

    public function test_clear_all_retried_empties_everything(): void
    {
        $log = $this->forceOneRetry(null, onTruncate: true);
        try {
            $code = Artisan::call('fuzzy-search:clear', ['--all' => true]);
        } catch (\Throwable $e) {
            $code = 'EXC ' . class_basename($e) . ': ' . substr($e->getMessage(), 0, 160);
        }
        $left = DB::table('fuzzy_index_documents')->count() + DB::table('fuzzy_index_postings')->count() + DB::table('fuzzy_index_meta')->count();

        $this->assertSame(0, $code, (string) Artisan::output());
        $this->assertSame(0, $left);
        // and the index works afterwards
        $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => RetryNote::class]));
        $this->assertSame(40, DB::table('fuzzy_index_documents')->where('model_type', RetryNote::class)->count());
    }
}
