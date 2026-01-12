<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCommand extends Command
{
    protected $signature = 'fuzzy-search:clear 
                            {model? : The model class to clear}
                            {--all : Clear all indexed models}';

    protected $description = 'Clear the search index for a model';

    public function handle(): int
    {
        $table = config('fuzzy-search.indexing.table', 'search_index');

        if ($this->option('all')) {
            DB::table($table)->truncate();
            $this->info('Cleared all search indexes');
            return 0;
        }

        $model = $this->argument('model');

        if (!$model) {
            $this->error('Please provide a model class or use --all flag');
            return 1;
        }

        if (!class_exists($model)) {
            $model = 'App\\Models\\' . $model;
        }

        $deleted = DB::table($table)->where('model', $model)->delete();
        $this->info("Cleared {$deleted} index records for {$model}");

        return 0;
    }
}

