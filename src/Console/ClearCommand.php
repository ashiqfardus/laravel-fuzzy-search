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
            try {
                $truncated = $indexManager->flushAll();
            } catch (\Throwable $e) {
                return $this->unfinished($e, 'php artisan fuzzy-search:clear --all');
            }
            $this->info('Cleared BM25 index for all models.');
            if (!$truncated) {
                $this->warn('It was deleted row by row, not truncated, because the index tables were busy (or TRUNCATE is not allowed). On a large index, run it again when the index is idle, so it truncates, before rebuilding.');
            }
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
        // index, and then the rows still under its own name from before it named that type.
        $type = IndexManager::indexType($model);
        try {
            $indexManager->flush($type);
            $this->info("Cleared BM25 index for [{$type}].");

            if ($type !== $model && $this->indexHolds($model)) {
                $indexManager->flush($model);
                $this->info("Cleared BM25 index for [{$model}].");
            }
        } catch (\Throwable $e) {
            return $this->unfinished($e, "php artisan fuzzy-search:clear \"{$model}\"");
        }

        return self::SUCCESS;
    }

    /**
     * A clear that failed part way (MySQL/MariaDB delete in batches that commit one by one, so the
     * index may be partly emptied) is finished only by running it again: a rebuild or any other
     * index write before that counts the documents left a second time.
     */
    private function unfinished(\Throwable $e, string $command): int
    {
        report($e);
        $this->error("The clear did not finish: {$e->getMessage()}");
        $this->line("Run <comment>{$command}</comment> again before any other index command (a rebuild or a save that is indexed counts what is left a second time).");

        return self::FAILURE;
    }

    /** Whether the index holds rows under $modelType, as the meta row or a document: read from the write connection, as the clear reads. */
    private function indexHolds(string $modelType): bool
    {
        return DB::table('fuzzy_index_meta')->useWritePdo()->where('model_type', $modelType)->exists()
            || DB::table('fuzzy_index_documents')->useWritePdo()->where('model_type', $modelType)->exists();
    }
}
