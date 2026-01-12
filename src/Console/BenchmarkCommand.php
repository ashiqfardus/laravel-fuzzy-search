<?php

namespace Ashiqfardus\LaravelFuzzySearch\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BenchmarkCommand extends Command
{
    protected $signature = 'fuzzy-search:benchmark 
                            {model : The model class to benchmark}
                            {--term=test : Search term to use}
                            {--iterations=100 : Number of iterations}
                            {--algorithm=fuzzy : Algorithm to benchmark}';

    protected $description = 'Benchmark search performance for a model';

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
        $iterations = (int) $this->option('iterations');
        $algorithm = $this->option('algorithm');

        $this->info("Benchmarking {$model}");
        $this->info("Search term: {$term}");
        $this->info("Algorithm: {$algorithm}");
        $this->info("Iterations: {$iterations}");
        $this->newLine();

        $times = [];
        $memoryStart = memory_get_usage(true);
        $resultCount = 0;

        $bar = $this->output->createProgressBar($iterations);
        $bar->start();

        for ($i = 0; $i < $iterations; $i++) {
            $start = microtime(true);

            $results = $model::search($term)
                ->using($algorithm)
                ->limit(20)
                ->get();

            $end = microtime(true);
            $times[] = ($end - $start) * 1000; // Convert to ms
            $resultCount = $results->count();

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $memoryEnd = memory_get_usage(true);
        $memoryUsed = ($memoryEnd - $memoryStart) / 1024 / 1024;

        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);
        $queriesPerSecond = 1000 / $avgTime;

        $this->table(
            ['Metric', 'Value'],
            [
                ['Algorithm', $algorithm],
                ['Total Records', $model::count()],
                ['Results per Query', $resultCount],
                ['Average Time', sprintf('%.2f ms', $avgTime)],
                ['Min Time', sprintf('%.2f ms', $minTime)],
                ['Max Time', sprintf('%.2f ms', $maxTime)],
                ['Queries/Second', sprintf('%.1f', $queriesPerSecond)],
                ['Memory Usage', sprintf('%.2f MB', $memoryUsed)],
            ]
        );

        // Performance rating
        if ($avgTime < 10) {
            $this->info('⚡ Excellent performance!');
        } elseif ($avgTime < 50) {
            $this->info('✓ Good performance');
        } elseif ($avgTime < 100) {
            $this->warn('⚠ Moderate performance - consider indexing');
        } else {
            $this->error('✗ Poor performance - indexing recommended');
        }

        return 0;
    }
}

