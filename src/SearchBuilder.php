<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Closure;

/**
 * SearchBuilder - Fluent API for building fuzzy search queries
 */
class SearchBuilder
{
    protected Builder|EloquentBuilder $query;
    protected FuzzySearch $fuzzySearch;
    protected string $searchTerm = '';
    protected array $searchableColumns = [];
    protected array $columnWeights = [];
    protected ?string $algorithm = null;
    protected array $options = [];
    protected bool $withRelevance = true;
    protected int $limit = 15;
    protected int $offset = 0;
    protected array $filters = [];
    protected array $facets = [];
    protected ?string $highlightTagOpen = null;
    protected ?string $highlightTagClose = null;
    protected array $sortBy = [];

    // New feature properties
    protected int $typoTolerance = 2;
    protected bool $tokenizeSearch = false;
    protected string $tokenMatchMode = 'any'; // 'any' or 'all'
    protected float $prefixBoostMultiplier = 1.0;
    protected bool $partialMatchEnabled = false;
    protected int $minMatchLength = 2;
    protected ?Closure $customScoreCallback = null;
    protected array $stopWords = [];
    protected ?string $stopWordLocale = null;
    protected array $synonyms = [];
    protected array $synonymGroups = [];
    protected ?string $locale = null;
    protected bool $accentInsensitiveEnabled = false;
    protected bool $unicodeNormalizeEnabled = false;
    protected bool $debugMode = false;
    protected bool $useSearchIndex = false;
    protected ?int $cacheMinutes = null;
    protected ?string $cacheKey = null;
    protected bool $stableRankingEnabled = false;
    protected array $fallbackAlgorithms = [];
    protected ?int $debounceMs = null;
    protected int $maxPatterns = 100;

    // Default stop words by locale
    protected array $defaultStopWords = [
        'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'it', 'be', 'as', 'was', 'with', 'that', 'have', 'this', 'will', 'from', 'they', 'we', 'been', 'has', 'her', 'she', 'he', 'him', 'his', 'my', 'your', 'our', 'their'],
        'de' => ['der', 'die', 'das', 'und', 'oder', 'aber', 'in', 'auf', 'an', 'zu', 'für', 'von', 'ist', 'es', 'sein', 'als', 'war', 'mit', 'dass', 'haben', 'dies', 'wird', 'aus', 'sie', 'wir', 'ich', 'du', 'er', 'ihr', 'sein', 'mein', 'dein', 'unser'],
        'fr' => ['le', 'la', 'les', 'un', 'une', 'des', 'et', 'ou', 'mais', 'dans', 'sur', 'à', 'pour', 'de', 'est', 'ce', 'être', 'comme', 'était', 'avec', 'que', 'avoir', 'ceci', 'sera', 'ils', 'nous', 'je', 'tu', 'il', 'elle', 'son', 'mon', 'ton', 'notre'],
        'es' => ['el', 'la', 'los', 'las', 'un', 'una', 'y', 'o', 'pero', 'en', 'sobre', 'a', 'para', 'de', 'es', 'ser', 'como', 'era', 'con', 'que', 'tener', 'esto', 'será', 'ellos', 'nosotros', 'yo', 'tú', 'él', 'ella', 'su', 'mi', 'tu', 'nuestro'],
    ];

    public function __construct(Builder|EloquentBuilder $query, FuzzySearch $fuzzySearch)
    {
        $this->query = $query;
        $this->fuzzySearch = $fuzzySearch;
    }

    /**
     * Set the search term
     */
    public function search(string $term): self
    {
        $this->searchTerm = trim($term);
        return $this;
    }

    /**
     * Set searchable columns with optional weights
     */
    public function searchIn(array $columns): self
    {
        foreach ($columns as $key => $value) {
            if (is_string($key)) {
                $this->searchableColumns[] = $key;
                $this->columnWeights[$key] = (int) $value;
            } else {
                $this->searchableColumns[] = $value;
                $this->columnWeights[$value] = 1;
            }
        }
        return $this;
    }

    /**
     * Set search algorithm
     */
    public function using(string $algorithm): self
    {
        $this->algorithm = $algorithm;
        return $this;
    }

    /**
     * Set typo tolerance level
     */
    public function typoTolerance(int $level): self
    {
        $this->typoTolerance = max(0, min(5, $level));
        $this->options['max_distance'] = $this->typoTolerance;
        return $this;
    }

    /**
     * Enable token-based search (split search term into words)
     */
    public function tokenize(): self
    {
        $this->tokenizeSearch = true;
        return $this;
    }

    /**
     * All tokens must match
     */
    public function matchAll(): self
    {
        $this->tokenMatchMode = 'all';
        return $this;
    }

    /**
     * Any token can match
     */
    public function matchAny(): self
    {
        $this->tokenMatchMode = 'any';
        return $this;
    }

    /**
     * Set prefix boost multiplier
     */
    public function prefixBoost(float $multiplier): self
    {
        $this->prefixBoostMultiplier = max(1.0, $multiplier);
        return $this;
    }

    /**
     * Enable partial matching
     */
    public function partialMatch(): self
    {
        $this->partialMatchEnabled = true;
        return $this;
    }

    /**
     * Set minimum match length for partial matching
     */
    public function minMatchLength(int $length): self
    {
        $this->minMatchLength = max(1, $length);
        return $this;
    }

    /**
     * Add custom scoring callback
     */
    public function customScore(Closure $callback): self
    {
        $this->customScoreCallback = $callback;
        return $this;
    }

    /**
     * Set stop words to ignore
     */
    public function ignoreStopWords(array|string|null $stopWords = null): self
    {
        if (is_string($stopWords)) {
            // Locale code
            $this->stopWordLocale = $stopWords;
            $this->stopWords = $this->defaultStopWords[$stopWords] ?? [];
        } elseif (is_array($stopWords)) {
            $this->stopWords = $stopWords;
        } else {
            // Default English
            $this->stopWords = $this->defaultStopWords['en'];
        }
        return $this;
    }

    /**
     * Set synonyms
     */
    public function withSynonyms(array $synonyms): self
    {
        $this->synonyms = array_merge($this->synonyms, $synonyms);
        return $this;
    }

    /**
     * Add synonym group (all words in group are treated as equivalent)
     */
    public function synonymGroup(array $words): self
    {
        $this->synonymGroups[] = array_map('strtolower', $words);
        return $this;
    }

    /**
     * Set locale for language-aware processing
     */
    public function locale(string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * Enable accent-insensitive search
     */
    public function accentInsensitive(): self
    {
        $this->accentInsensitiveEnabled = true;
        return $this;
    }

    /**
     * Enable unicode normalization
     */
    public function unicodeNormalize(): self
    {
        $this->unicodeNormalizeEnabled = true;
        return $this;
    }

    /**
     * Enable highlighting
     */
    public function highlight(string $tagOrOpen = 'em', ?string $close = null): self
    {
        if ($close === null) {
            $this->highlightTagOpen = "<{$tagOrOpen}>";
            $this->highlightTagClose = "</{$tagOrOpen}>";
        } else {
            $this->highlightTagOpen = $tagOrOpen;
            $this->highlightTagClose = $close;
        }
        return $this;
    }

    /**
     * Enable debug/explain score mode
     */
    public function debugScore(): self
    {
        $this->debugMode = true;
        $this->withRelevance = true;
        return $this;
    }

    /**
     * Use search index table
     */
    public function useIndex(): self
    {
        $this->useSearchIndex = true;
        return $this;
    }

    /**
     * Cache results
     */
    public function cache(?int $minutes = 60, ?string $key = null): self
    {
        $this->cacheMinutes = $minutes;
        $this->cacheKey = $key;
        return $this;
    }

    /**
     * Enable stable ranking across pages
     */
    public function stableRanking(): self
    {
        $this->stableRankingEnabled = true;
        return $this;
    }

    /**
     * Add fallback algorithm
     */
    public function fallback(string $algorithm): self
    {
        $this->fallbackAlgorithms[] = $algorithm;
        return $this;
    }

    /**
     * Set debounce for real-time search
     */
    public function debounce(int $ms): self
    {
        $this->debounceMs = $ms;
        return $this;
    }

    /**
     * Limit maximum patterns generated
     */
    public function maxPatterns(int $max): self
    {
        $this->maxPatterns = max(10, $max);
        return $this;
    }

    /**
     * Add filter condition
     */
    public function filter(string $column, mixed $operator, mixed $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }
        $this->filters[] = compact('column', 'operator', 'value');
        return $this;
    }

    /**
     * Add where in filter
     */
    public function filterIn(string $column, array $values): self
    {
        $this->filters[] = ['column' => $column, 'operator' => 'IN', 'value' => $values];
        return $this;
    }

    /**
     * Add faceted search
     */
    public function facet(string $column): self
    {
        $this->facets[] = $column;
        return $this;
    }

    /**
     * Include relevance score
     */
    public function withRelevance(bool $include = true): self
    {
        $this->withRelevance = $include;
        return $this;
    }

    /**
     * Set limit
     */
    public function take(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Alias for take()
     */
    public function limit(int $limit): self
    {
        return $this->take($limit);
    }

    /**
     * Set offset
     */
    public function skip(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Alias for skip()
     */
    public function offset(int $offset): self
    {
        return $this->skip($offset);
    }

    /**
     * Page-based pagination
     */
    public function page(int $page, int $perPage = 15): self
    {
        $this->limit = $perPage;
        $this->offset = ($page - 1) * $perPage;
        return $this;
    }

    /**
     * Order by column
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->sortBy[] = compact('column', 'direction');
        return $this;
    }

    /**
     * Set algorithm options
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Execute search and get results
     */
    public function get(): Collection
    {
        // Check cache
        if ($this->cacheMinutes !== null) {
            $cacheKey = $this->cacheKey ?? $this->generateCacheKey();
            return Cache::remember($cacheKey, $this->cacheMinutes * 60, function () {
                return $this->executeSearch();
            });
        }

        return $this->executeSearch();
    }

    /**
     * Execute the search
     */
    protected function executeSearch(): Collection
    {
        $this->buildQuery();

        $results = $this->query
            ->limit($this->limit)
            ->offset($this->offset)
            ->get();

        // Post-process results
        if ($this->withRelevance && !empty($this->searchTerm)) {
            $results = $this->calculateRelevanceScores($results);
        }

        if ($this->highlightTagOpen) {
            $results = $this->applyHighlighting($results);
        }

        if ($this->debugMode) {
            $results = $this->addDebugInfo($results);
        }

        return $results;
    }

    /**
     * Get paginated results
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $this->buildQuery();

        $page = $page ?: request()->input($pageName, 1);

        $results = $this->query->paginate($perPage, ['*'], $pageName, $page);

        if ($this->withRelevance && !empty($this->searchTerm)) {
            $items = $this->calculateRelevanceScores(collect($results->items()));
            $results->setCollection($items);
        }

        return $results;
    }

    /**
     * Simple pagination
     */
    public function simplePaginate(int $perPage = 15): \Illuminate\Contracts\Pagination\Paginator
    {
        $this->buildQuery();
        return $this->query->simplePaginate($perPage);
    }

    /**
     * Cursor pagination
     */
    public function cursorPaginate(int $perPage = 15): \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $this->buildQuery();
        return $this->query->cursorPaginate($perPage);
    }

    /**
     * Get first result
     */
    public function first(): ?Model
    {
        return $this->take(1)->get()->first();
    }

    /**
     * Get count
     */
    public function count(): int
    {
        $this->buildQuery();
        return $this->query->count();
    }

    /**
     * Get facet counts
     */
    public function getFacets(): array
    {
        if (empty($this->facets)) {
            return [];
        }

        $this->buildQuery();
        $facetResults = [];

        foreach ($this->facets as $facet) {
            $facetResults[$facet] = $this->query
                ->clone()
                ->select($facet, DB::raw('COUNT(*) as count'))
                ->groupBy($facet)
                ->pluck('count', $facet)
                ->toArray();
        }

        return $facetResults;
    }

    /**
     * Build the query
     */
    protected function buildQuery(): void
    {
        // Process search term
        $searchTerm = $this->processSearchTerm($this->searchTerm);

        if (!empty($searchTerm) && !empty($this->searchableColumns)) {
            $this->applySearchConditions($searchTerm);
        }

        // Apply filters
        foreach ($this->filters as $filter) {
            if ($filter['operator'] === 'IN') {
                $this->query->whereIn($filter['column'], $filter['value']);
            } else {
                $this->query->where($filter['column'], $filter['operator'], $filter['value']);
            }
        }

        // Apply sorting
        if (empty($this->sortBy) && $this->withRelevance && !empty($this->searchTerm)) {
            $this->applyRelevanceOrdering();
        } else {
            foreach ($this->sortBy as $sort) {
                $this->query->orderBy($sort['column'], $sort['direction']);
            }
        }

        // Stable ranking
        if ($this->stableRankingEnabled) {
            $this->query->orderBy('id', 'asc');
        }
    }

    /**
     * Process search term (stop words, accents, etc.)
     */
    protected function processSearchTerm(string $term): string
    {
        // Unicode normalization
        if ($this->unicodeNormalizeEnabled && function_exists('normalizer_normalize')) {
            $term = normalizer_normalize($term, \Normalizer::FORM_C);
        }

        // Accent insensitivity
        if ($this->accentInsensitiveEnabled) {
            $term = $this->removeAccents($term);
        }

        // Remove stop words
        if (!empty($this->stopWords)) {
            $words = preg_split('/\s+/', $term);
            $words = array_filter($words, function ($word) {
                return !in_array(strtolower($word), $this->stopWords);
            });
            $term = implode(' ', $words);
        }

        return trim($term);
    }

    /**
     * Remove accents from string
     */
    protected function removeAccents(string $string): string
    {
        $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
        ];

        return strtr($string, $accents);
    }

    /**
     * Expand search terms with synonyms
     */
    protected function expandWithSynonyms(string $term): array
    {
        $terms = [$term];
        $lowerTerm = strtolower($term);

        // Check direct synonyms
        if (isset($this->synonyms[$lowerTerm])) {
            $terms = array_merge($terms, $this->synonyms[$lowerTerm]);
        }

        // Check synonym groups
        foreach ($this->synonymGroups as $group) {
            if (in_array($lowerTerm, $group)) {
                $terms = array_merge($terms, $group);
            }
        }

        return array_unique($terms);
    }

    /**
     * Apply search conditions
     */
    protected function applySearchConditions(string $searchTerm): void
    {
        $queryBuilder = $this->query instanceof EloquentBuilder
            ? $this->query->getQuery()
            : $this->query;

        // Tokenize if enabled
        if ($this->tokenizeSearch) {
            $tokens = preg_split('/\s+/', $searchTerm);
            $tokens = array_filter($tokens);
        } else {
            $tokens = [$searchTerm];
        }

        // Expand with synonyms
        $allTerms = [];
        foreach ($tokens as $token) {
            $allTerms = array_merge($allTerms, $this->expandWithSynonyms($token));
        }
        $allTerms = array_unique($allTerms);

        // Build query based on match mode
        if ($this->tokenMatchMode === 'all' && $this->tokenizeSearch) {
            // All tokens must match
            foreach ($tokens as $token) {
                $tokenTerms = $this->expandWithSynonyms($token);
                $this->query->where(function ($q) use ($queryBuilder, $tokenTerms) {
                    foreach ($tokenTerms as $idx => $term) {
                        foreach ($this->searchableColumns as $colIdx => $column) {
                            $boolean = ($idx === 0 && $colIdx === 0) ? 'and' : 'or';
                            $subQuery = $q instanceof EloquentBuilder ? $q->getQuery() : $q;
                            $this->fuzzySearch->applyFuzzyWhere(
                                $subQuery,
                                $column,
                                $term,
                                $this->algorithm,
                                $this->options,
                                $boolean
                            );
                        }
                    }
                });
            }
        } else {
            // Any token can match
            $this->query->where(function ($q) use ($queryBuilder, $allTerms) {
                $first = true;
                foreach ($allTerms as $term) {
                    foreach ($this->searchableColumns as $column) {
                        $boolean = $first ? 'and' : 'or';
                        $first = false;
                        $subQuery = $q instanceof EloquentBuilder ? $q->getQuery() : $q;
                        $this->fuzzySearch->applyFuzzyWhere(
                            $subQuery,
                            $column,
                            $term,
                            $this->algorithm,
                            $this->options,
                            $boolean
                        );
                    }
                }
            });
        }
    }

    /**
     * Apply relevance ordering
     */
    protected function applyRelevanceOrdering(): void
    {
        $driver = $this->query->getConnection()->getDriverName();
        $term = $this->searchTerm;

        $scoreExpressions = [];
        $bindings = [];

        foreach ($this->searchableColumns as $column) {
            $weight = $this->columnWeights[$column] ?? 1;
            $prefixBoost = $this->prefixBoostMultiplier;
            $col = $this->quoteColumn($column, $driver);

            switch ($driver) {
                case 'mysql':
                    $scoreExpressions[] = "(CASE WHEN {$col} = ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $bindings = array_merge($bindings, [
                        $term, $weight * 100,
                        $term . '%', $weight * 50 * $prefixBoost,
                        '%' . $term . '%', $weight * 10,
                    ]);
                    break;

                case 'pgsql':
                    $scoreExpressions[] = "(CASE WHEN {$col} = ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} ILIKE ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} ILIKE ? THEN ? ELSE 0 END)";
                    $bindings = array_merge($bindings, [
                        $term, $weight * 100,
                        $term . '%', $weight * 50 * $prefixBoost,
                        '%' . $term . '%', $weight * 10,
                    ]);
                    break;

                default:
                    $scoreExpressions[] = "(CASE WHEN {$col} = ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $bindings = array_merge($bindings, [
                        $term, $weight * 100,
                        $term . '%', $weight * 50 * $prefixBoost,
                        '%' . $term . '%', $weight * 10,
                    ]);
            }
        }

        if (!empty($scoreExpressions)) {
            $this->query->orderByRaw('(' . implode(' + ', $scoreExpressions) . ') DESC', $bindings);
        }
    }

    /**
     * Quote column based on database driver
     */
    protected function quoteColumn(string $column, string $driver): string
    {
        return match ($driver) {
            'mysql' => "`{$column}`",
            'pgsql' => "\"{$column}\"",
            'sqlsrv' => "[{$column}]",
            default => $column,
        };
    }

    /**
     * Calculate relevance scores for results
     */
    protected function calculateRelevanceScores(Collection $results): Collection
    {
        $term = strtolower($this->searchTerm);

        return $results->map(function ($item) use ($term) {
            $score = 0;
            $columnScores = [];

            foreach ($this->searchableColumns as $column) {
                $value = strtolower((string) data_get($item, $column, ''));
                $weight = $this->columnWeights[$column] ?? 1;
                $colScore = 0;

                // Exact match
                if ($value === $term) {
                    $colScore = 100 * $weight;
                }
                // Prefix match
                elseif (str_starts_with($value, $term)) {
                    $colScore = 50 * $weight * $this->prefixBoostMultiplier;
                }
                // Contains
                elseif (str_contains($value, $term)) {
                    $colScore = 25 * $weight;
                }
                // Fuzzy match
                else {
                    $distance = FuzzySearch::levenshteinDistance($value, $term);
                    if ($distance <= $this->typoTolerance) {
                        $colScore = max(0, (10 - $distance * 3)) * $weight;
                    }
                }

                $columnScores[$column] = $colScore;
                $score += $colScore;
            }

            // Apply custom scoring
            if ($this->customScoreCallback) {
                $score = ($this->customScoreCallback)($item, $score);
            }

            // Apply recency boost
            $recencyMultiplier = $this->calculateRecencyBoost($item);
            $score *= $recencyMultiplier;

            // Set score on item
            if (is_object($item)) {
                $item->_score = $score;
                if ($this->debugMode) {
                    $item->_column_scores = $columnScores;
                }
            } elseif (is_array($item)) {
                $item['_score'] = $score;
                if ($this->debugMode) {
                    $item['_column_scores'] = $columnScores;
                }
            }

            return $item;
        })->sortByDesc(function ($item) {
            return is_object($item) ? ($item->_score ?? 0) : ($item['_score'] ?? 0);
        })->values();
    }

    /**
     * Apply highlighting to results
     */
    protected function applyHighlighting(Collection $results): Collection
    {
        $term = $this->searchTerm;
        $open = $this->highlightTagOpen;
        $close = $this->highlightTagClose;

        return $results->map(function ($item) use ($term, $open, $close) {
            $highlighted = [];

            foreach ($this->searchableColumns as $column) {
                $value = (string) data_get($item, $column, '');
                $pattern = '/(' . preg_quote($term, '/') . ')/i';
                $highlighted[$column] = preg_replace($pattern, "{$open}$1{$close}", $value);
            }

            if (is_object($item)) {
                $item->_highlighted = $highlighted;
            } elseif (is_array($item)) {
                $item['_highlighted'] = $highlighted;
            }

            return $item;
        });
    }

    /**
     * Add debug information
     */
    protected function addDebugInfo(Collection $results): Collection
    {
        return $results->map(function ($item) {
            $debug = [
                'term' => $this->searchTerm,
                'algorithm' => $this->algorithm ?? 'fuzzy',
                'typo_tolerance' => $this->typoTolerance,
                'prefix_boost' => $this->prefixBoostMultiplier,
                'columns' => $this->searchableColumns,
                'weights' => $this->columnWeights,
                'column_scores' => is_object($item) ? ($item->_column_scores ?? []) : ($item['_column_scores'] ?? []),
                'final_score' => is_object($item) ? ($item->_score ?? 0) : ($item['_score'] ?? 0),
            ];

            if (is_object($item)) {
                $item->_debug = $debug;
                unset($item->_column_scores);
            } elseif (is_array($item)) {
                $item['_debug'] = $debug;
                unset($item['_column_scores']);
            }

            return $item;
        });
    }

    /**
     * Generate cache key
     */
    protected function generateCacheKey(): string
    {
        $data = [
            'term' => $this->searchTerm,
            'columns' => $this->searchableColumns,
            'algorithm' => $this->algorithm,
            'options' => $this->options,
            'filters' => $this->filters,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];

        return 'fuzzy_search_' . md5(serialize($data));
    }

    /**
     * Get raw SQL for debugging
     */
    public function toSql(): string
    {
        $this->buildQuery();
        return $this->query->toSql();
    }

    /**
     * Get bindings for debugging
     */
    public function getBindings(): array
    {
        $this->buildQuery();
        return $this->query->getBindings();
    }

    protected bool $recencyBoostEnabled = false;
    protected float $recencyBoostMultiplier = 1.5;
    protected string $recencyColumn = 'created_at';
    protected int $recencyDays = 30;

    /**
     * Enable recency boost - newer records get higher scores
     *
     * @param float $multiplier Score multiplier for recent records (default 1.5)
     * @param string $column Date column to use (default 'created_at')
     * @param int $days Records within this many days get boosted (default 30)
     */
    public function boostRecent(float $multiplier = 1.5, string $column = 'created_at', int $days = 30): self
    {
        $this->recencyBoostEnabled = true;
        $this->recencyBoostMultiplier = max(1.0, $multiplier);
        $this->recencyColumn = $column;
        $this->recencyDays = max(1, $days);
        return $this;
    }

    /**
     * Get search suggestions / autocomplete
     *
     * Returns an array of suggested completions based on the search term.
     *
     * @param int $limit Maximum number of suggestions
     * @return array Array of suggestion strings
     */
    public function suggest(int $limit = 5): array
    {
        if (empty($this->searchTerm) || strlen($this->searchTerm) < 2) {
            return [];
        }

        if (empty($this->searchableColumns)) {
            return [];
        }

        $suggestions = [];
        $term = strtolower($this->searchTerm);

        // Clone query to avoid modifying the original
        $suggestQuery = clone $this->query;

        // Build a simple prefix query
        $suggestQuery->where(function ($q) use ($term) {
            foreach ($this->searchableColumns as $column) {
                $q->orWhere($column, 'LIKE', $term . '%');
            }
        });

        $results = $suggestQuery->limit($limit * 3)->get();

        // Extract unique suggestions from results
        foreach ($results as $result) {
            foreach ($this->searchableColumns as $column) {
                $value = (string) data_get($result, $column, '');
                if (!empty($value)) {
                    // Extract the matching word/phrase
                    $words = preg_split('/\s+/', $value);
                    foreach ($words as $word) {
                        $wordLower = strtolower($word);
                        if (str_starts_with($wordLower, $term) && strlen($word) > strlen($term)) {
                            $suggestions[$wordLower] = $word;
                        }
                    }

                    // Also add full column value if it starts with term
                    $valueLower = strtolower($value);
                    if (str_starts_with($valueLower, $term)) {
                        $suggestions[$valueLower] = $value;
                    }
                }
            }

            if (count($suggestions) >= $limit * 2) {
                break;
            }
        }

        // Sort by length (shorter = more relevant) then alphabetically
        $sortedSuggestions = array_values($suggestions);
        usort($sortedSuggestions, function ($a, $b) {
            $lenDiff = strlen($a) - strlen($b);
            return $lenDiff !== 0 ? $lenDiff : strcasecmp($a, $b);
        });

        return array_slice($sortedSuggestions, 0, $limit);
    }

    /**
     * Get "Did you mean" spell corrections
     *
     * Returns alternative spellings when the current search yields few or no results.
     *
     * @param int $limit Maximum number of alternatives
     * @return array Array of alternative search terms with distance and confidence
     */
    public function didYouMean(int $limit = 3): array
    {
        if (empty($this->searchTerm) || strlen($this->searchTerm) < 2) {
            return [];
        }

        if (empty($this->searchableColumns)) {
            return [];
        }

        $term = strtolower($this->searchTerm);
        $termLen = strlen($term);
        $alternatives = [];
        $candidateWords = [];

        // Clone query to get sample values
        $sampleQuery = clone $this->query;
        $samples = $sampleQuery->limit(200)->get();

        // Extract unique words from samples efficiently
        foreach ($samples as $sample) {
            foreach ($this->searchableColumns as $column) {
                $value = (string) data_get($sample, $column, '');
                // Split on non-alphanumeric characters
                $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($value), -1, PREG_SPLIT_NO_EMPTY);
                foreach ($words as $word) {
                    // Only consider words of similar length (+/- 3 characters)
                    $wordLen = strlen($word);
                    if ($wordLen >= 2 && abs($wordLen - $termLen) <= 3) {
                        $candidateWords[$word] = true;
                    }
                }
            }
        }

        // Find words with small edit distance
        foreach (array_keys($candidateWords) as $word) {
            // Skip if word is same as search term
            if ($word === $term) {
                continue;
            }

            $distance = levenshtein($term, $word);
            $maxLen = max($termLen, strlen($word));

            // Only suggest if edit distance is reasonable (1-3 edits) and less than half the word length
            if ($distance > 0 && $distance <= 3 && $distance < $maxLen / 2) {
                $confidence = round(1 - ($distance / $maxLen), 2);
                $alternatives[] = [
                    'term' => $word,
                    'distance' => $distance,
                    'confidence' => $confidence,
                ];
            }
        }

        // Sort by distance (ascending), then by confidence (descending)
        usort($alternatives, function ($a, $b) {
            if ($a['distance'] !== $b['distance']) {
                return $a['distance'] - $b['distance'];
            }
            return $b['confidence'] <=> $a['confidence'];
        });

        return array_slice($alternatives, 0, $limit);
    }

    /**
     * Calculate recency boost score
     */
    protected function calculateRecencyBoost($item): float
    {
        if (!$this->recencyBoostEnabled) {
            return 1.0;
        }

        $dateValue = data_get($item, $this->recencyColumn);

        if (empty($dateValue)) {
            return 1.0;
        }

        try {
            $date = $dateValue instanceof \DateTimeInterface
                ? $dateValue
                : new \DateTime($dateValue);

            $now = new \DateTime();
            $daysDiff = $now->diff($date)->days;

            if ($daysDiff <= $this->recencyDays) {
                // Linear decay: full boost at day 0, no boost at recencyDays
                $decayFactor = 1 - ($daysDiff / $this->recencyDays);
                return 1.0 + (($this->recencyBoostMultiplier - 1.0) * $decayFactor);
            }
        } catch (\Exception $e) {
            // If date parsing fails, no boost
        }

        return 1.0;
    }

    /**
     * Get search analytics data
     *
     * Returns statistics about the search query.
     *
     * @return array Analytics data
     */
    public function getAnalytics(): array
    {
        $this->buildQuery();

        return [
            'search_term' => $this->searchTerm,
            'algorithm' => $this->algorithm ?? 'fuzzy',
            'columns_searched' => $this->searchableColumns,
            'column_weights' => $this->columnWeights,
            'typo_tolerance' => $this->typoTolerance,
            'tokenized' => $this->tokenizeSearch,
            'token_mode' => $this->tokenMatchMode,
            'stop_words_active' => !empty($this->stopWords),
            'synonyms_active' => !empty($this->synonyms) || !empty($this->synonymGroups),
            'accent_insensitive' => $this->accentInsensitiveEnabled,
            'cached' => $this->cacheMinutes !== null,
            'recency_boost' => $this->recencyBoostEnabled,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
    }
}

