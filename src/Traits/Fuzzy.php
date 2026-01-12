<?php

namespace Ashiqfardus\LaravelFuzzySearch\Traits;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;

trait Fuzzy
{
    /**
     * Get the columns that should be fuzzy searchable
     * Override this in your model to customize
     */
    public function getFuzzySearchableColumns(): array
    {
        return $this->fuzzySearchable ?? ['name'];
    }

    /**
     * Get the default fuzzy algorithm for this model
     */
    public function getFuzzyAlgorithm(): string
    {
        return $this->fuzzyAlgorithm ?? config('fuzzy-search.default_algorithm', 'levenshtein');
    }

    /**
     * Get fuzzy search options for this model
     */
    public function getFuzzyOptions(): array
    {
        return $this->fuzzyOptions ?? [];
    }

    /**
     * Scope for fuzzy search on defined columns
     */
    public function scopeFuzzy($query, string $searchTerm, ?array $columns = null, ?string $algorithm = null)
    {
        $columns = $columns ?? $this->getFuzzySearchableColumns();
        $algorithm = $algorithm ?? $this->getFuzzyAlgorithm();
        $options = $this->getFuzzyOptions();

        return $query->whereFuzzyMultiple($columns, $searchTerm, $algorithm, $options);
    }

    /**
     * Scope for fuzzy search with custom algorithm
     */
    public function scopeFuzzyWith($query, string $algorithm, string $searchTerm, ?array $columns = null)
    {
        $columns = $columns ?? $this->getFuzzySearchableColumns();
        return $query->whereFuzzyMultiple($columns, $searchTerm, $algorithm);
    }

    /**
     * Scope for Levenshtein search
     */
    public function scopeFuzzyLevenshtein($query, string $searchTerm, ?array $columns = null, ?int $maxDistance = null)
    {
        $columns = $columns ?? $this->getFuzzySearchableColumns();
        $options = $maxDistance ? ['max_distance' => $maxDistance] : [];

        return $query->whereFuzzyMultiple($columns, $searchTerm, 'levenshtein', $options);
    }

    /**
     * Scope for Soundex search
     */
    public function scopeFuzzySoundex($query, string $searchTerm, ?array $columns = null)
    {
        $columns = $columns ?? $this->getFuzzySearchableColumns();
        return $query->whereFuzzyMultiple($columns, $searchTerm, 'soundex');
    }

    /**
     * Scope for Similar text search
     */
    public function scopeFuzzySimilar($query, string $searchTerm, ?array $columns = null, ?int $minPercentage = null)
    {
        $columns = $columns ?? $this->getFuzzySearchableColumns();
        $options = $minPercentage ? ['min_percentage' => $minPercentage] : [];

        return $query->whereFuzzyMultiple($columns, $searchTerm, 'similar_text', $options);
    }

    /**
     * Filter a collection by fuzzy matching (post-query filtering)
     */
    public static function filterFuzzy($collection, string $column, string $searchTerm, int $maxDistance = 3)
    {
        return $collection->filter(function ($item) use ($column, $searchTerm, $maxDistance) {
            $value = is_array($item) ? ($item[$column] ?? '') : ($item->$column ?? '');
            return FuzzySearch::levenshteinDistance($value, $searchTerm) <= $maxDistance;
        });
    }

    /**
     * Sort a collection by fuzzy relevance (post-query sorting)
     */
    public static function sortByFuzzy($collection, string $column, string $searchTerm, string $direction = 'asc')
    {
        return $collection->sortBy(function ($item) use ($column, $searchTerm) {
            $value = is_array($item) ? ($item[$column] ?? '') : ($item->$column ?? '');
            return FuzzySearch::levenshteinDistance($value, $searchTerm);
        }, SORT_REGULAR, $direction === 'desc');
    }
}

