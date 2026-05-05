<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCommand extends Command
{
    protected $signature = 'fuzzy-search:clear
                            {model? : The model class to clear (e.g. "App\\Models\\User")}
                            {--all : Clear BM25 index for all models}';

    protected $description = 'Clear the BM25 search index for a model';

    public function handle(IndexManager $indexManager): int
    {
        if ($this->option('all')) {
            // Delete per-table rather than truncate so that foreign-key constraints
            // are respected and only this package's tables are affected.
            DB::table('fuzzy_index_postings')->delete();
            DB::table('fuzzy_index_documents')->delete();
            DB::table('fuzzy_index_meta')->delete();
            DB::table('fuzzy_index_terms')->delete();
            $this->info('Cleared BM25 index for all models.');
            return self::SUCCESS;
        }

        $model = $this->argument('model');

        if (!$model) {
            $this->error('Please provide a model class or use --all flag.');
            return self::FAILURE;
        }

        if (!class_exists($model)) {
            $model = 'App\\Models\\' . $model;
        }

        if (!class_exists($model)) {
            $this->error("Model class [{$model}] not found.");
            return self::FAILURE;
        }

        $indexManager->flush($model);
        $this->info("Cleared BM25 index for [{$model}].");

        return self::SUCCESS;
    }
}
