<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IndexCommand extends Command
{
    protected $signature = 'fuzzy-search:index 
                            {model? : The model class to index}
                            {--all : Index all searchable models}
                            {--fresh : Drop and recreate the index}
                            {--columns= : Specific columns to index (comma-separated)}';

    protected $description = 'Create or update the search index for a model';

    public function handle(): int
    {
        $table = config('fuzzy-search.indexing.table', 'search_index');

        // Create index table if it doesn't exist
        if (!Schema::hasTable($table)) {
            $this->createIndexTable($table);
            $this->info("Created search index table: {$table}");
        }

        if ($this->option('all')) {
            return $this->indexAllModels();
        }

        $model = $this->argument('model');

        if (!$model) {
            $this->error('Please provide a model class or use --all flag');
            return 1;
        }

        return $this->indexModel($model);
    }

    protected function createIndexTable(string $table): void
    {
        Schema::create($table, function ($blueprint) {
            $blueprint->id();
            $blueprint->string('model');
            $blueprint->unsignedBigInteger('model_id');
            $blueprint->longText('content');
            $blueprint->timestamps();

            $blueprint->index(['model', 'model_id']);
            $blueprint->fullText('content');
        });
    }

    protected function indexModel(string $model): int
    {
        if (!class_exists($model)) {
            // Try to resolve short name
            $model = 'App\\Models\\' . $model;
        }

        if (!class_exists($model)) {
            $this->error("Model class not found: {$model}");
            return 1;
        }

        $table = config('fuzzy-search.indexing.table', 'search_index');

        if ($this->option('fresh')) {
            DB::table($table)->where('model', $model)->delete();
            $this->info("Cleared existing index for {$model}");
        }

        $this->info("Indexing {$model}...");

        $instance = new $model;

        // Get columns to index
        $columns = $this->option('columns')
            ? explode(',', $this->option('columns'))
            : $this->getSearchableColumns($instance);

        if (empty($columns)) {
            $this->warn("No searchable columns found for {$model}");
            return 1;
        }

        $this->info("Indexing columns: " . implode(', ', $columns));

        $chunkSize = config('fuzzy-search.indexing.chunk_size', 500);
        $bar = $this->output->createProgressBar($model::count());
        $bar->start();

        $model::query()
            ->select(['id', ...$columns])
            ->chunk($chunkSize, function ($models) use ($columns, $table, $model, $bar) {
                $records = [];

                foreach ($models as $item) {
                    $content = '';
                    foreach ($columns as $column) {
                        $content .= ' ' . ($item->$column ?? '');
                    }

                    $records[] = [
                        'model' => $model,
                        'model_id' => $item->id,
                        'content' => trim($content),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $bar->advance();
                }

                DB::table($table)->insert($records);
            });

        $bar->finish();
        $this->newLine();
        $this->info("Successfully indexed {$model}");

        return 0;
    }

    protected function indexAllModels(): int
    {
        $modelsPath = app_path('Models');

        if (!is_dir($modelsPath)) {
            $this->error('Models directory not found');
            return 1;
        }

        $models = [];
        foreach (glob("{$modelsPath}/*.php") as $file) {
            $className = 'App\\Models\\' . basename($file, '.php');
            if (class_exists($className) && $this->hasSearchableTrait($className)) {
                $models[] = $className;
            }
        }

        if (empty($models)) {
            $this->warn('No searchable models found');
            return 0;
        }

        foreach ($models as $model) {
            $this->indexModel($model);
        }

        return 0;
    }

    protected function hasSearchableTrait(string $class): bool
    {
        $traits = class_uses_recursive($class);
        return in_array(\Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::class, $traits);
    }

    protected function getSearchableColumns($instance): array
    {
        if (isset($instance->searchable['columns'])) {
            return array_keys($instance->searchable['columns']);
        }

        // Fallback to common columns
        $tableColumns = Schema::getColumnListing($instance->getTable());
        $common = ['name', 'title', 'email', 'description', 'content', 'body'];

        return array_intersect($common, $tableColumns);
    }
}

