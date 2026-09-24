<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;

class IndexCommand extends Command
{
    use ValidatesInput;

    protected $signature = 'fuzzy-search:index 
                            {model? : The model class to index}
                            {--all : Index all searchable models}
                            {--fresh : Drop and recreate the index}
                            {--columns= : Specific columns to index (comma-separated)}';

    protected $description = '[DEPRECATED v1] Use fuzzy-search:rebuild for BM25 inverted index. This command writes to the legacy search_index table.';

    public function handle(): int
    {
        $this->warn('[DEPRECATED] fuzzy-search:index writes to the legacy v1 search_index table.');
        $this->warn('For v2 BM25 inverted index use: php artisan fuzzy-search:rebuild "App\Models\ModelName"');
        $this->newLine();

        // Check the model before creating anything for it.
        $model = (string) $this->argument('model');
        if (!$this->option('all')) {
            if ($model === '') {
                $this->error('Please provide a model class or use --all flag');
                return 1;
            }

            $model = class_exists($model) ? $model : 'App\\Models\\' . $model;
            if (!$this->validModel($model)) {
                return 1;
            }
        }

        $table = config('fuzzy-search.indexing.table', 'search_index');

        // Create index table if it doesn't exist
        if (!Schema::hasTable($table)) {
            // Its FULLTEXT index is only built by Laravel on MySQL/MariaDB and PostgreSQL;
            // elsewhere Schema::create() throws a grammar exception. Say so instead.
            $driver = DB::connection()->getDriverName();
            if (!DbDialect::isMySqlFamily($driver) && $driver !== DbDialect::PGSQL) {
                $this->error("fuzzy-search:index needs a FULLTEXT index on its {$table} table, which Laravel only creates on MySQL, MariaDB and PostgreSQL (this connection is {$driver}).");
                $this->error('Use fuzzy-search:rebuild instead — the BM25 inverted index works on every database.');
                return 1;
            }

            $this->createIndexTable($table);
            $this->info("Created search index table: {$table}");
        }

        if ($this->option('all')) {
            return $this->indexAllModels();
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

        $status = 0;
        foreach ($models as $model) {
            $status = max($status, $this->indexModel($model)); // one failed model fails the run
        }

        return $status;
    }

    protected function hasSearchableTrait(string $class): bool
    {
        $traits = class_uses_recursive($class);
        return in_array(\Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::class, $traits);
    }

    protected function getSearchableColumns($instance): array
    {
        // Through the model's own accessors, never $instance->searchable from outside: the property
        // is protected, so that read went to Eloquent's __isset() and never saw the declared
        // columns, and on a model that also uses Scout's Searchable, __isset() resolved Scout's
        // searchable() method as a relation, which indexed the model and threw. property_exists()
        // first: without the property, the accessor's own read of $this->searchable goes the same way.
        if (property_exists($instance, 'searchable') && method_exists($instance, 'hasDeclaredSearchableColumns')
            && $instance->hasDeclaredSearchableColumns()) {
            // Real columns only: this command selects them, and a relation path is no column.
            return array_values(array_filter($instance->getSearchableColumns(), fn ($column) => !str_contains($column, '.')));
        }

        // Fallback to common columns
        $tableColumns = Schema::getColumnListing($instance->getTable());
        $common = ['name', 'title', 'email', 'description', 'content', 'body'];

        return array_intersect($common, $tableColumns);
    }
}

