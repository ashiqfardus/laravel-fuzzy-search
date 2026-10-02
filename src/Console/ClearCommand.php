<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearCommand extends Command
{
    use ValidatesInput;

    protected $signature = 'fuzzy-search:clear
                            {model? : The model class to clear (e.g. "App\\Models\\User")}
                            {--all : Clear BM25 index for all models}';

    protected $description = 'Clear the BM25 search index for a model (fuzzy-search:flush <model> does the same), or for every model with --all';

    public function handle(IndexManager $indexManager): int
    {
        if ($this->option('all')) {
            $indexManager->flushAll();
            $this->info('Cleared BM25 index for all models.');
            return self::SUCCESS;
        }

        $model = $this->argument('model');

        if (!$model) {
            $this->error('Please provide a model class or use --all flag.');
            return self::FAILURE;
        }

        $model = $this->modelName($model);

        // A model_type the index holds is cleared whether or not its class still exists: the rows
        // of a renamed or deleted model could otherwise go only with --all, every other model's
        // index with them. A name that is neither a class nor in the index fails as before.
        if (!$this->indexHolds($model) && !$this->validModel($model)) {
            return self::FAILURE;
        }

        // The index the class is searched through: a class indexed under another type
        // (searchIndexType(): a single-table-inheritance child names its parent) clears that type's
        // index, and then the rows still under its own name from before it named that type (SA-1).
        $type = IndexManager::indexType($model);
        $indexManager->flush($type);
        $this->info("Cleared BM25 index for [{$type}].");

        if ($type !== $model && $this->indexHolds($model)) {
            $indexManager->flush($model);
            $this->info("Cleared BM25 index for [{$model}].");
        }

        return self::SUCCESS;
    }

    /** Whether the index holds rows under $modelType, as the meta row or a document. */
    private function indexHolds(string $modelType): bool
    {
        return DB::table('fuzzy_index_meta')->where('model_type', $modelType)->exists()
            || DB::table('fuzzy_index_documents')->where('model_type', $modelType)->exists();
    }
}
