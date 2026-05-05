<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Console\Command;

class FlushCommand extends Command
{
    protected $signature   = 'fuzzy-search:flush
                              {model : Fully-qualified model class e.g. App\\Models\\User}';
    protected $description = 'Remove all index entries for a model';

    public function handle(IndexManager $indexManager): int
    {
        $modelClass = $this->argument('model');

        if (!class_exists($modelClass)) {
            $this->error("Model class [{$modelClass}] not found.");
            return self::FAILURE;
        }

        $indexManager->flush($modelClass);
        $this->info("Flushed index for [{$modelClass}].");

        return self::SUCCESS;
    }
}
