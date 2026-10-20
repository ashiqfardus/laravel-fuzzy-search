<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Ashiqfardus\LaravelFuzzySearch\Console\Concerns\ValidatesInput;
use Illuminate\Console\Command;

class ExplainCommand extends Command
{
    use ValidatesInput;

    protected $signature = 'fuzzy-search:explain 
                            {model : The model class to explain}
                            {--term=test : Search term to explain}
                            {--algorithm=fuzzy : Algorithm to use}';

    protected $description = 'Explain a search query for debugging';

    public function handle(): int
    {
        $model = $this->argument('model');

        $model = $this->modelName($model);

        if (!$this->validModel($model, 'searchable')) {
            return self::FAILURE;
        }

        $term = $this->option('term');
        $algorithm = $this->option('algorithm');

        $this->info("Query Explanation for {$model}");
        $this->newLine();

        // Build search query
        $builder = $model::search($term)
            ->using($algorithm)
            ->debugScore()
            ->limit(5);

        // Get SQL
        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        $this->info('Search Configuration:');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Search Term', $term],
                ['Algorithm', $algorithm],
                ['Model', $model],
            ]
        );

        $this->newLine();
        $this->info('Generated SQL:');
        $this->line($sql);

        $this->newLine();
        $this->info('Bindings:');
        foreach ($bindings as $i => $binding) {
            $this->line("  [{$i}] => " . (is_string($binding) ? "\"{$binding}\"" : $binding));
        }

        // Execute and show results
        $this->newLine();
        $this->info('Sample Results (top 5):');

        $results = $builder->get();

        if ($results->isEmpty()) {
            $this->warn('No results found');
        } else {
            $tableData = [];
            foreach ($results as $result) {
                $debug = $result->_debug ?? [];
                $tableData[] = [
                    'ID' => $result->id ?? 'N/A',
                    'Score' => $result->_score ?? 0,
                    'Column Scores' => json_encode($debug['column_scores'] ?? []),
                ];
            }
            $this->table(['ID', 'Score', 'Column Scores'], $tableData);
        }

        return 0;
    }
}

