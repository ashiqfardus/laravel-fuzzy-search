<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * SQL Server: a write reads and deletes a model's postings by (model_type_id, model_id in (...)) and
 * must seek postings_model_idx. After an automatic statistics update with a sampled scan the optimizer
 * estimated the key's density 50 times too high and scanned the clustered index instead, about 13 s
 * a chunk (6M rows). The statements carry FORCESEEK there; the other databases' SQL is unchanged.
 */
class SqlServerPostingsSeekTest extends TestCase
{
    /** @return string[] the SQL a re-index sends to the postings table by model id */
    private function postingStatements(): array
    {
        Artisan::call('fuzzy-search:rebuild', ['model' => User::class]);
        $user = User::first();
        DB::table('users')->where('id', $user->getKey())->update(['name' => 'Quillon Marsh']);

        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            if (preg_match('/^\s*(select|delete)\b.*fuzzy_index_postings/is', $query->sql) && stripos($query->sql, 'model_id') !== false) {
                $sql[] = $query->sql;
            }
        });
        app(IndexManager::class)->indexModel(User::find($user->getKey()));

        return $sql;
    }

    public function test_the_by_model_postings_statements_force_a_seek_on_sql_server_only(): void
    {
        $sql = $this->postingStatements();
        $this->assertNotSame([], $sql, 'a re-index reads the model\'s postings');

        $sqlsrv = DB::connection()->getDriverName() === 'sqlsrv';
        foreach ($sql as $statement) {
            $sqlsrv && stripos(ltrim($statement), 'select') === 0
                ? $this->assertStringContainsStringIgnoringCase('forceseek', $statement)
                : $this->assertStringNotContainsStringIgnoringCase('forceseek', $statement);
        }
        if ($sqlsrv) {
            // The delete of the words the name change removes ran, and goes without the hint: SQL Server refuses FORCESEEK on a DELETE's target.
            $this->assertNotEmpty(array_filter($sql, fn ($s) => stripos(ltrim($s), 'select') === 0 && stripos($s, 'forceseek') !== false));
            $this->assertNotEmpty(array_filter($sql, fn ($s) => stripos(ltrim($s), 'delete') === 0));
        }
    }
}
