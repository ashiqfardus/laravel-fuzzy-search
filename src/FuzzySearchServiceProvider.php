<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Ashiqfardus\LaravelFuzzySearch\Console\IndexCommand;
use Ashiqfardus\LaravelFuzzySearch\Console\ClearCommand;
use Ashiqfardus\LaravelFuzzySearch\Console\BenchmarkCommand;
use Ashiqfardus\LaravelFuzzySearch\Console\ExplainCommand;

class FuzzySearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fuzzy-search.php', 'fuzzy-search');

        $this->app->singleton(FuzzySearch::class, function ($app) {
            return new FuzzySearch(config('fuzzy-search'));
        });

        // Register SearchBuilder for dependency injection
        $this->app->bind(SearchBuilder::class, function ($app) {
            return new SearchBuilder(
                $app['db']->query(),
                $app->make(FuzzySearch::class)
            );
        });
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/fuzzy-search.php' => config_path('fuzzy-search.php'),
        ], 'fuzzy-search-config');

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                IndexCommand::class,
                ClearCommand::class,
                BenchmarkCommand::class,
                ExplainCommand::class,
            ]);
        }

        // Register Query Builder macros
        $this->registerQueryBuilderMacros();

        // Register Eloquent Builder macros
        $this->registerEloquentBuilderMacros();
    }

    protected function registerQueryBuilderMacros(): void
    {
        $fuzzySearch = $this->app->make(FuzzySearch::class);

        // Basic fuzzy where
        QueryBuilder::macro('whereFuzzy', function (string $column, string $value, ?string $algorithm = null, ?array $options = []) use ($fuzzySearch) {
            return $fuzzySearch->applyFuzzyWhere($this, $column, $value, $algorithm, $options);
        });

        // Fuzzy where with OR
        QueryBuilder::macro('orWhereFuzzy', function (string $column, string $value, ?string $algorithm = null, ?array $options = []) use ($fuzzySearch) {
            return $fuzzySearch->applyFuzzyWhere($this, $column, $value, $algorithm, $options, 'or');
        });

        // Fuzzy search across multiple columns
        QueryBuilder::macro('whereFuzzyMultiple', function (array $columns, string $value, ?string $algorithm = null, ?array $options = []) use ($fuzzySearch) {
            return $fuzzySearch->applyFuzzyWhereMultiple($this, $columns, $value, $algorithm, $options);
        });

        // Order by fuzzy relevance
        QueryBuilder::macro('orderByFuzzy', function (string $column, string $value, string $direction = 'asc') use ($fuzzySearch) {
            return $fuzzySearch->applyFuzzyOrder($this, $column, $value, $direction);
        });

        // Search builder macro
        QueryBuilder::macro('fuzzySearch', function (string|array $columns, string $value, ?array $options = []) use ($fuzzySearch) {
            $columns = is_array($columns) ? $columns : [$columns];
            return $fuzzySearch->applyFuzzyWhereMultiple($this, $columns, $value, null, $options);
        });
    }

    protected function registerEloquentBuilderMacros(): void
    {
        $fuzzySearch = $this->app->make(FuzzySearch::class);

        // Basic fuzzy where for Eloquent
        EloquentBuilder::macro('whereFuzzy', function (string $column, string $value, ?string $algorithm = null, ?array $options = []) use ($fuzzySearch) {
            $fuzzySearch->applyFuzzyWhere($this->getQuery(), $column, $value, $algorithm, $options);
            return $this;
        });

        // Fuzzy where with OR for Eloquent
        EloquentBuilder::macro('orWhereFuzzy', function (string $column, string $value, ?string $algorithm = null, ?array $options = []) use ($fuzzySearch) {
            $fuzzySearch->applyFuzzyWhere($this->getQuery(), $column, $value, $algorithm, $options, 'or');
            return $this;
        });

        // Fuzzy search across multiple columns for Eloquent
        EloquentBuilder::macro('whereFuzzyMultiple', function (array $columns, string $value, ?string $algorithm = null, ?array $options = []) use ($fuzzySearch) {
            $fuzzySearch->applyFuzzyWhereMultiple($this->getQuery(), $columns, $value, $algorithm, $options);
            return $this;
        });

        // Order by fuzzy relevance for Eloquent
        EloquentBuilder::macro('orderByFuzzy', function (string $column, string $value, string $direction = 'asc') use ($fuzzySearch) {
            $fuzzySearch->applyFuzzyOrder($this->getQuery(), $column, $value, $direction);
            return $this;
        });

        // Scope-based fuzzy search for Eloquent
        EloquentBuilder::macro('fuzzySearch', function (string|array $columns, string $value, ?array $options = []) use ($fuzzySearch) {
            $columns = is_array($columns) ? $columns : [$columns];
            $fuzzySearch->applyFuzzyWhereMultiple($this->getQuery(), $columns, $value, null, $options);
            return $this;
        });
    }
}

