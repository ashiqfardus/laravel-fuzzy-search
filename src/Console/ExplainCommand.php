<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;

class ExplainCommand extends Command
{
    protected $signature = 'fuzzy-search:explain 
                            {model : The model class to explain}
                            {--term=test : Search term to explain}
                            {--algorithm=fuzzy : Algorithm to use}';

    protected $description = 'Explain a search query for debugging';

    public function handle(): int
    {
        $model = $this->argument('model');

        if (!class_exists($model)) {
            $model = 'App\\Models\\' . $model;
        }

        if (!class_exists($model)) {
            $this->error("Model class not found: {$model}");
            return 1;
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

        // Pattern analysis
        $this->newLine();
        $this->info('Pattern Analysis:');

        $fuzzySearch = app(FuzzySearch::class);
        $patterns = $this->analyzePatterns($term, $algorithm);

        $this->line("  Generated patterns: " . count($patterns));
        $this->line("  Sample patterns:");
        foreach (array_slice($patterns, 0, 10) as $pattern) {
            $this->line("    - {$pattern}");
        }

        if (count($patterns) > 10) {
            $this->line("    ... and " . (count($patterns) - 10) . " more");
        }

        return 0;
    }

    protected function analyzePatterns(string $term, string $algorithm): array
    {
        $patterns = [];
        $term = strtolower(trim($term));

        // Basic patterns
        $patterns[] = "%{$term}%";
        $patterns[] = "{$term}%";
        $patterns[] = "%{$term}";

        if ($algorithm === 'fuzzy' || $algorithm === 'levenshtein') {
            // Character omission patterns
            for ($i = 0; $i < strlen($term); $i++) {
                $patterns[] = '%' . substr($term, 0, $i) . '%' . substr($term, $i + 1) . '%';
            }

            // Transposition patterns
            for ($i = 0; $i < strlen($term) - 1; $i++) {
                $transposed = substr($term, 0, $i) . $term[$i + 1] . $term[$i] . substr($term, $i + 2);
                $patterns[] = '%' . $transposed . '%';
            }
        }

        return array_unique($patterns);
    }
}

