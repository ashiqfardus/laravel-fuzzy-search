<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Console\Command;

class RebuildCommand extends Command
{
    protected $signature = 'fuzzy-search:rebuild
                            {model : Fully-qualified model class e.g. App\\Models\\User}
                            {--fresh : Flush existing index before rebuilding}';

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
        $total     = $modelClass::count();

        $this->info("Rebuilding index for [{$modelClass}] ({$total} records, chunk: {$chunkSize})...");
        $bar = $this->output->createProgressBar($total);

        $modelClass::orderBy('id')->chunk($chunkSize, function ($models) use ($indexManager, $bar) {
            foreach ($models as $model) {
                $indexManager->indexModel($model);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Done.');

        return self::SUCCESS;
    }
}
