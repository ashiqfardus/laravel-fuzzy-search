<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Console\Command;

class FlushCommand extends Command
{
    use ValidatesInput;

    protected $signature   = 'fuzzy-search:flush
                              {model : Fully-qualified model class e.g. App\\Models\\User}';
    protected $description = 'Remove all index entries for a model';

    public function handle(IndexManager $indexManager): int
    {
        $modelClass = $this->argument('model');

        if (!$this->validModel($modelClass)) {
            return self::FAILURE;
        }

        $indexManager->flush($modelClass);
        $this->info("Flushed index for [{$modelClass}].");

        return self::SUCCESS;
    }
}
