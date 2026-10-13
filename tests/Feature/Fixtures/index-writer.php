<?php

/*
 * One index writer of IndexWriteLockTest, run in a PHP process of its own (proc_open): it opens
 * its own connection, where a forked child inherits the parent's ODBC driver state, which is not
 * fork-safe (SQL Server). argv[1] names a JSON file: the connection's config, a barrier file, and
 * the steps, each [action, model class, ids]:
 *  - index: one indexBatch() of the rows (a rebuild chunk);
 *  - save:  a save of each row, its index written synchronously by the package's observer;
 *  - update-then-index: an app's transaction that updates the first row, then indexes it before
 *    its commit (Scout with after_commit off), 1.5 s later;
 *  - index-after-update: once that update is in, a write of the first row (IndexModelJob's).
 * Prints "ready" once connected, starts when the barrier file exists, and ends with one JSON line:
 * the failures (thrown or reported) and the transactions it rolled back.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearchServiceProvider;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Foundation\Application;

$job = json_decode(file_get_contents($argv[1]), true);
$app = Application::create(options: ['extra' => ['providers' => [FuzzySearchServiceProvider::class], 'dont-discover' => ['*']]]);

$app['config']->set('fuzzy-search', require __DIR__ . '/../../../config/fuzzy-search.php');
$app['config']->set('fuzzy-search.indexing.enabled', true); // the observer indexes a save
$app['config']->set('fuzzy-search.indexing.async', false);  // in-process, after the save commits
$app['config']->set('database.connections.writer', $job['connection']);
$app['config']->set('database.default', 'writer');

$failed    = [];
$rollbacks = 0;
Event::listen(TransactionRolledBack::class, function () use (&$rollbacks) {
    $rollbacks++;
});
// The observer reports a failed index write rather than throwing it from save().
$app->make(ExceptionHandler::class)->reportable(function (\Throwable $e) use (&$failed) {
    $failed[] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300);

    return false;
});

DB::select('select 1'); // connected before the start
echo "ready\n";
while (!is_file($job['go'])) {
    usleep(1000);
}

foreach ($job['steps'] as [$action, $class, $ids]) {
    try {
        if ($action === 'index') {
            app(IndexManager::class)->indexBatch($class::whereKey($ids)->get());
        } elseif ($action === 'save') {
            foreach ($ids as $i => $id) {
                $class::findOrFail($id)->update(['name' => $job['names'][$i % count($job['names'])]]);
            }
        } elseif ($action === 'update-then-index') {
            DB::transaction(function () use ($class, $ids, $job) {
                $class::whereKey($ids[0])->toBase()->update(['name' => 'zzapp']);
                touch($job['go'] . '.updated');
                usleep(1500000); // the other write claims the row, then waits on it in its re-read
                app(IndexManager::class)->indexModel($class::findOrFail($ids[0]));
            });
        } else {
            while (!is_file($job['go'] . '.updated')) {
                usleep(1000);
            }
            app(IndexManager::class)->syncModel($class, $ids[0]);
        }
    } catch (\Throwable $e) {
        $failed[] = get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 300); // the SQL of a claim runs to pages
    }
}

echo json_encode(['failed' => $failed, 'rollbacks' => $rollbacks]), "\n";
