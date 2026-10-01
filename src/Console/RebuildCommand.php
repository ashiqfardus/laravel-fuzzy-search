<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Support\IndexQuery;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RebuildCommand extends Command
{
    use ValidatesInput;

    protected $signature = 'fuzzy-search:rebuild
                            {model : Fully-qualified model class e.g. App\\Models\\User}
                            {--fresh : Flush existing index before rebuilding}
                            {--async : Dispatch rebuild as queued batch jobs (recommended for large tables)}
                            {--queue= : Queue name for async jobs (overrides config)}';

    protected $description = 'Rebuild the inverted index for a model';

    public function handle(IndexManager $indexManager): int
    {
        $modelClass = $this->modelName($this->argument('model'));

        if (!$this->validModel($modelClass, 'indexable')) {
            return self::FAILURE;
        }

        // A class indexed under another type (searchIndexType(): a single-table-inheritance child
        // names its parent) shares that type's index, which --fresh flushes whole: the rebuild reads
        // every row of it, through the type's own query, or the type's other rows were lost (SA-1).
        $type = IndexManager::indexType($modelClass);
        if ($type !== $modelClass) {
            if (!$this->validModel($type, 'indexable')) {
                return self::FAILURE;
            }
            $this->line("[{$modelClass}] is indexed as [{$type}] (searchIndexType()): rebuilding [{$type}].");
            $modelClass = $type;
        }

        // Before --fresh flushes anything: an --async run that cannot dispatch leaves no index.
        if ($this->option('async') && !$this->batchTableExists()) {
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->info("Flushing existing index for [{$modelClass}]...");
            $indexManager->flush($modelClass);
        }

        $chunkSize = (int) config('fuzzy-search.indexing.chunk_size', 500);
        $queue     = $this->option('queue') ?? config('fuzzy-search.indexing.queue', 'default');
        $total     = IndexQuery::for($modelClass)->count();

        if ($this->option('async')) {
            return $this->rebuildAsync($modelClass, $chunkSize, $queue, $total);
        }

        return $this->rebuildSync($modelClass, $chunkSize, $indexManager, $total);
    }

    private function rebuildSync(string $modelClass, int $chunkSize, IndexManager $indexManager, int $total): int
    {
        $this->info("Rebuilding index for [{$modelClass}] synchronously ({$total} records, chunk: {$chunkSize})...");
        $bar = $this->output->createProgressBar($total);

        $keyName = (new $modelClass)->getKeyName();
        $indexed = 0;
        $shadows = app(SearchableObserver::class);
        // chunkById() is keyset-based: rows inserted or deleted while the rebuild runs cannot
        // shift the window, unlike offset chunking. Works for integer, UUID and ULID keys.
        IndexQuery::for($modelClass)->chunkById($chunkSize, function ($models) use ($indexManager, $bar, &$indexed, $shadows) {
            $indexed += $indexManager->indexBatch($models); // each row under its index type, not the chunk's first model's class (RC-3, SA-1)
            $shadows->backfillShadowColumns($models); // fills *_metaphone for rows saved before it existed
            $bar->advance($models->count());
        }, $keyName);

        $bar->finish();
        $this->newLine();

        // Statistics for the rebuilt index (PostgreSQL; see IndexManager::analyzeIndex()).
        $indexManager->analyzeIndex();

        // "Done." on an index that stayed empty reads like success. Say what happened and why.
        if ($indexed === 0) {
            $this->warn($total === 0
                ? "No records to index for [{$modelClass}]."
                : "Indexed 0 of {$total} records for [{$modelClass}] — no index terms were produced. "
                  . "Check that its searchable columns hold text (\$searchable['columns'], or the "
                  . "auto-detected columns when none are declared).");

            return self::SUCCESS;
        }

        $this->info("Done. Indexed {$indexed} of {$total} records.");
        return self::SUCCESS;
    }

    /**
     * --async dispatches a job batch, which Laravel stores in queue.batching.table (job_batches)
     * on queue.batching.database; DynamoDB-backed batching needs no table.
     */
    private function batchTableExists(): bool
    {
        $batching = (array) config('queue.batching', []);
        if (($batching['driver'] ?? 'database') === 'dynamodb'
            || Schema::connection($batching['database'] ?? null)->hasTable($batching['table'] ?? 'job_batches')) {
            return true;
        }

        $this->error('--async dispatches a job batch, and the ' . ($batching['table'] ?? 'job_batches') . ' table that stores batches does not exist.');
        $this->line('Create it with <comment>php artisan make:queue-batches-table</comment> (Laravel 10: <comment>php artisan queue:batches-table</comment>), then <comment>php artisan migrate</comment>.');

        return false;
    }

    /**
     * Jobs queued at once. Laravel's database queue inserts them in one statement, six bindings a
     * job: one batch of every job passed the driver's bound-parameter limit (350 jobs on SQL
     * Server, whose 2,100 is the lowest) after --fresh had flushed the index, with nothing queued.
     */
    private const JOBS_PER_DISPATCH = 300;

    private function rebuildAsync(string $modelClass, int $chunkSize, string $queue, int $total): int
    {
        $this->info("Dispatching async rebuild for [{$modelClass}] ({$total} records, chunk: {$chunkSize}, queue: {$queue})...");

        $model  = new $modelClass;
        $batch  = null;
        $jobs   = [];
        $queued = 0;

        // The batch goes out with its first slice of jobs, and each later slice is added to it. A
        // worker may run the jobs queued so far before the next slice is added: the batch then
        // reports itself finished until add() raises its counts again, and finally() runs once
        // each time, which only repeats the ANALYZE (best-effort). The batch's id is printed once
        // every slice is queued, so a caller that watches it sees the whole rebuild.
        //
        // Once the last job has run, statistics for the rebuilt index (PostgreSQL; see
        // IndexManager::analyzeIndex()). Static: the callback is serialized with the batch. After
        // the commit: on the sync queue the jobs and this callback run inside the batch store's
        // transaction, and a connection lost during the ANALYZE took the rebuilt index with it,
        // while Laravel's batch reported the failure and the command exited 0. A worker runs the
        // callback outside any transaction, where afterCommit() calls it at once.
        $dispatch = function () use (&$batch, &$jobs, &$queued, $modelClass, $queue) {
            if ($batch === null) {
                $batch = Bus::batch($jobs)
                    ->onQueue($queue)
                    ->name("fuzzy-search:rebuild:{$modelClass}")
                    ->finally(static fn () => DB::afterCommit(static fn () => app(IndexManager::class)->analyzeIndex()))
                    ->dispatch();
            } else {
                $batch->add($jobs);
            }
            $queued += count($jobs);
            $jobs    = [];
        };

        try {
            // The keys a chunk at a time (keyset, as the sync rebuild reads its rows), never all at
            // once: only the key column, with no eager loads, each key as the model casts it.
            IndexQuery::for($modelClass)->setEagerLoads([])->select($model->getQualifiedKeyName())->chunkById(
                $chunkSize,
                function ($models) use (&$jobs, $modelClass, $dispatch) {
                    $jobs[] = new RebuildIndexJob($modelClass, $models->modelKeys());
                    if (count($jobs) === self::JOBS_PER_DISPATCH) {
                        $dispatch();
                    }
                },
                $model->getQualifiedKeyName(),
                $model->getKeyName()
            );
            if ($jobs !== []) {
                $dispatch();
            }
        } catch (\Throwable $e) {
            // --fresh flushed the index before the first job was queued (the jobs could otherwise run
            // before the flush), so say what was queued and how to finish, not only the exception.
            report($e);
            $this->error("Dispatching stopped after {$queued} jobs were queued: {$e->getMessage()}");
            $this->line("The jobs queued index their rows; the rest of [{$modelClass}] was not queued. Once the cause is fixed, run <comment>php artisan fuzzy-search:rebuild \"{$modelClass}\"</comment> again (without --async it indexes every row in this process).");

            return self::FAILURE;
        }

        if ($batch === null) {
            $this->warn('No records to index.');
            return self::SUCCESS;
        }

        $this->info("Batch dispatched: {$batch->id}");
        $this->line("Jobs: {$queued} × {$chunkSize} records");
        $this->line("Monitor: php artisan queue:work --queue={$queue}");

        return self::SUCCESS;
    }
}
