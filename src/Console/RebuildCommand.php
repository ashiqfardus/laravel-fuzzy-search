<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class RebuildCommand extends Command
{
    protected $signature = 'fuzzy-search:rebuild
                            {model : Fully-qualified model class e.g. App\\Models\\User}
                            {--fresh : Flush existing index before rebuilding}
                            {--async : Dispatch rebuild as queued batch jobs (recommended for large tables)}
                            {--queue= : Queue name for async jobs (overrides config)}';

    protected $description = 'Rebuild the inverted index for a model';

    public function handle(IndexManager $indexManager): int
    {
        $modelClass = $this->argument('model');

        if (!class_exists($modelClass)) {
            $this->error("Model class [{$modelClass}] not found.");
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->info("Flushing existing index for [{$modelClass}]...");
            $indexManager->flush($modelClass);
        }

        $chunkSize = (int) config('fuzzy-search.indexing.chunk_size', 500);
        $queue     = $this->option('queue') ?? config('fuzzy-search.indexing.queue', 'default');
        $total     = $modelClass::count();

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
        $modelClass::orderBy($keyName)->chunk($chunkSize, function ($models) use ($indexManager, $bar) {
            $indexManager->indexBatch($models);
            $bar->advance($models->count());
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');
        return self::SUCCESS;
    }

    private function rebuildAsync(string $modelClass, int $chunkSize, string $queue, int $total): int
    {
        $this->info("Dispatching async rebuild for [{$modelClass}] ({$total} records, chunk: {$chunkSize}, queue: {$queue})...");

        $jobs    = [];
        $keyName = (new $modelClass)->getKeyName();
        $modelClass::orderBy($keyName)->pluck($keyName)->chunk($chunkSize)->each(function ($ids) use ($modelClass, &$jobs) {
            $jobs[] = new RebuildIndexJob($modelClass, $ids->toArray());
        });

        if (empty($jobs)) {
            $this->warn('No records to index.');
            return self::SUCCESS;
        }

        $batch = Bus::batch($jobs)
            ->onQueue($queue)
            ->name("fuzzy-search:rebuild:{$modelClass}")
            ->dispatch();

        $this->info("Batch dispatched: {$batch->id}");
        $this->line("Jobs: " . count($jobs) . " × {$chunkSize} records");
        $this->line("Monitor: php artisan queue:work --queue={$queue}");

        return self::SUCCESS;
    }
}
