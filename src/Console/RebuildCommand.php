<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\RebuildIndexJob;
use Ashiqfardus\LaravelFuzzySearch\Support\IndexQuery;
use Illuminate\Bus\Batch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

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
        $modelClass = $this->argument('model');

        if (!$this->validModel($modelClass, 'indexable')) {
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
        // chunkById() is keyset-based: rows inserted or deleted while the rebuild runs cannot
        // shift the window, unlike offset chunking. Works for integer, UUID and ULID keys.
        IndexQuery::for($modelClass)->chunkById($chunkSize, function ($models) use ($indexManager, $bar, &$indexed) {
            $indexed += $indexManager->indexBatch($models);
            $bar->advance($models->count());
        }, $keyName);

        $bar->finish();
        $this->newLine();

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

    private function rebuildAsync(string $modelClass, int $chunkSize, string $queue, int $total): int
    {
        $this->info("Dispatching async rebuild for [{$modelClass}] ({$total} records, chunk: {$chunkSize}, queue: {$queue})...");

        $jobs    = [];
        $keyName = (new $modelClass)->getKeyName();
        IndexQuery::for($modelClass)->orderBy($keyName)->pluck($keyName)->chunk($chunkSize)->each(function ($ids) use ($modelClass, &$jobs) {
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
