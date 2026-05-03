<?php

namespace Ashiqfardus\LaravelFuzzySearch;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Closure;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\EmptySearchTermException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidAlgorithmException;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\SearchableColumnsNotFoundException;

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
    protected ?string $invertedIndexModelClass = null;
    protected ?string $extendedQuery = null;
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
        $this->accentInsensitiveEnabled = (bool) config('fuzzy-search.unicode.accent_insensitive', false);
    }

    /**
     * Set the search term
     *
     * @param string $term
     * @return self
     * @throws EmptySearchTermException if term is empty and config doesn't allow it
     */
    public function search(string $term): self
    {
        $this->searchTerm = trim($term);

        // Check if empty search is allowed in config
        $allowEmpty = config('fuzzy-search.allow_empty_search', false);

        if (empty($this->searchTerm) && !$allowEmpty) {
            throw new EmptySearchTermException();
        }

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
     * Use Fuse-style extended search syntax.
     *
     *   ' word     — substring include
     *   = word     — exact equality
     *   ^ word     — prefix
     *   word $     — suffix
     *   ! word     — exclude (NOT)
     *   |          — OR (default is AND on whitespace)
     *   ( ... )    — grouping
     *   "phrase"   — quoted phrase as one token
     *
     * Pass the query string here, OR set it via search() and call extended() with no args.
     */
    public function extended(?string $query = null): self
    {
        if ($query !== null) {
            $this->searchTerm = $query;
        }
        $this->extendedQuery = $this->searchTerm;
        return $this;
    }

    /**
     * Alias for extended() — same parser handles boolean syntax.
     *
     * Example: searchBoolean('term1 (term2 | term3) !term4')
     */
    public function searchBoolean(string $query): self
    {
        return $this->extended($query);
    }

    /**
     * Set search algorithm
     *
     * @param string $algorithm
     * @return self
     * @throws InvalidAlgorithmException if algorithm is not supported
     */
    public function using(string $algorithm): self
    {
        $supportedAlgorithms = ['fuzzy', 'levenshtein', 'soundex', 'trigram', 'simple', 'like', 'similar_text', 'metaphone'];

        if (!in_array($algorithm, $supportedAlgorithms)) {
            throw new InvalidAlgorithmException($algorithm);
        }

        // Normalize 'like' to 'simple' for consistency
        $this->algorithm = ($algorithm === 'like') ? 'simple' : $algorithm;
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
     *
     * @param bool $verbose Show detailed breakdown
     * @param string|null $logChannel Laravel log channel to use (null = no logging)
     * @return self
     */
    public function debugScore(bool $verbose = true, ?string $logChannel = null): self
    {
        $this->debugMode = true;
        $this->withRelevance = true;
        $this->options['debug_verbose'] = $verbose;
        $this->options['debug_log_channel'] = $logChannel;
        return $this;
    }

    /**
     * Get debug information for the current search configuration
     *
     * @return array
     */
    public function getDebugInfo(): array
    {
        return [
            'search_term' => $this->searchTerm,
            'algorithm' => $this->algorithm ?? config('fuzzy-search.default_algorithm', 'fuzzy'),
            'searchable_columns' => $this->searchableColumns,
            'column_weights' => $this->columnWeights,
            'typo_tolerance' => $this->typoTolerance,
            'tokenize' => $this->tokenizeSearch,
            'token_match_mode' => $this->tokenMatchMode,
            'stop_words' => $this->stopWords,
            'synonyms' => $this->synonyms,
            'accent_insensitive' => $this->accentInsensitiveEnabled,
            'unicode_normalize' => $this->unicodeNormalizeEnabled,
            'prefix_boost' => $this->prefixBoostMultiplier,
            'partial_match' => $this->partialMatchEnabled,
            'use_cache' => $this->cacheMinutes !== null,
            'cache_ttl' => $this->cacheMinutes,
            'use_index' => $this->useSearchIndex,
            'stable_ranking' => $this->stableRankingEnabled,
            'fallback_algorithms' => $this->fallbackAlgorithms,
            'options' => $this->options,
        ];
    }

    /**
     * Use the BM25 inverted index instead of LIKE-pattern search.
     *
     * @param  string|bool|null $modelClass
     *   - true/null  auto-detect from Eloquent builder
     *   - string     explicit model class (enables BM25 on DB::table() too)
     *   - false      disable (reset to LIKE path)
     */
    public function useInvertedIndex(string|bool|null $modelClass = true): self
    {
        if ($modelClass === false) {
            $this->useSearchIndex          = false;
            $this->invertedIndexModelClass = null;
            return $this;
        }

        $this->useSearchIndex = true;

        if (is_string($modelClass)) {
            $this->invertedIndexModelClass = $modelClass;
        }

        return $this;
    }

    /** Alias for useInvertedIndex() */
    public function useIndex(string|bool|null $modelClass = true): self
    {
        return $this->useInvertedIndex($modelClass);
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
     * Apply a predefined configuration preset
     *
     * @param string $presetName Name of preset from config (blog, ecommerce, users, phonetic, exact)
     * @return self
     * @throws InvalidConfigException if preset not found
     */
    public function preset(string $presetName): self
    {
        $presets = config('fuzzy-search.presets', []);

        if (!isset($presets[$presetName])) {
            throw new InvalidConfigException("Preset '{$presetName}' not found. Available presets: " . implode(', ', array_keys($presets)));
        }

        $preset = $presets[$presetName];

        // Apply columns if specified
        if (isset($preset['columns']) && is_array($preset['columns'])) {
            $this->searchIn($preset['columns']);
        }

        // Apply algorithm
        if (isset($preset['algorithm'])) {
            $this->using($preset['algorithm']);
        }

        // Apply typo tolerance
        if (isset($preset['typo_tolerance'])) {
            $this->typoTolerance($preset['typo_tolerance']);
        }

        // Apply accent insensitive
        if (isset($preset['accent_insensitive']) && $preset['accent_insensitive']) {
            $this->accentInsensitive();
        }

        // Apply partial match
        if (isset($preset['partial_match']) && $preset['partial_match']) {
            $this->partialMatch();
        }

        // Apply stop words
        if (isset($preset['stop_words_enabled']) && $preset['stop_words_enabled']) {
            $locale = $preset['locale'] ?? config('fuzzy-search.locale', 'en');
            $this->ignoreStopWords(config("fuzzy-search.stop_words.{$locale}", []));
        }

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
        // Extended-search path (Fuse-style operators)
        if ($this->extendedQuery !== null) {
            return $this->executeExtendedSearch();
        }

        // BM25 fast path via inverted index
        if ($this->useSearchIndex && !empty($this->searchTerm)) {
            return $this->executeIndexedSearch();
        }

        $this->buildQuery();
        $startTime = microtime(true);

        $maxCandidates = config('fuzzy-search.max_candidates', 1000);

        // Fetch all candidates up to the ceiling — do NOT apply limit/offset yet
        $candidates = $this->query->limit($maxCandidates)->get();

        // Rescore ALL candidates before slicing
        if ($this->withRelevance && !empty($this->searchTerm)) {
            $candidates = $this->calculateRelevanceScores($candidates);
            // calculateRelevanceScores already sorts by _score DESC and calls values()
        }

        // Apply pagination on the fully-ranked collection
        $results = $candidates->slice($this->offset, $this->limit)->values();

        if ($this->highlightTagOpen) {
            $results = $this->applyHighlighting($results);
        }

        if ($this->debugMode) {
            $results = $this->addDebugInfo($results);
        }

        event(new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
            searchTerm:     $this->searchTerm,
            columns:        $this->searchableColumns,
            algorithm:      $this->algorithm ?? config('fuzzy-search.default_algorithm', 'fuzzy'),
            candidateCount: $candidates->count(),
            latencyMs:      round((microtime(true) - $startTime) * 1000, 2),
        ));

        return $results;
    }

    /**
     * Execute search via BM25 inverted index
     */
    protected function executeIndexedSearch(): Collection
    {
        $startedAt  = microtime(true);
        $modelClass = $this->resolveIndexModelClass();

        if ($modelClass === null) {
            if (config('app.debug', false)) {
                \Illuminate\Support\Facades\Log::notice(
                    'fuzzy-search: useInvertedIndex() skipped — cannot resolve model class. ' .
                    'Pass the class explicitly: ->useInvertedIndex(App\Models\User::class)'
                );
            }
            $this->useSearchIndex = false;
            return $this->executeSearch();
        }

        $indexManager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
        $scorer       = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class);

        $terms   = $indexManager->processTerms($this->searchTerm);
        $results = $scorer->search($terms, $modelClass, ($this->limit + $this->offset) * 2);

        if ($results->isEmpty()) {
            return collect();
        }

        $ids      = $results->pluck('model_id')->toArray();
        $scoreMap = $results->pluck('score', 'model_id');

        if ($this->query instanceof \Illuminate\Database\Eloquent\Builder) {
            $keyName = $this->query->getModel()->getKeyName();
            $models  = $this->query->whereIn($keyName, $ids)->get();
        } else {
            $models = $modelClass::whereIn((new $modelClass)->getKeyName(), $ids)->get();
        }

        $sorted = $models
            ->sortByDesc(fn($m) => $scoreMap[$m->getKey()] ?? 0)
            ->values()
            ->slice($this->offset, $this->limit)
            ->values()
            ->map(function ($item) use ($scoreMap) {
                $item->_score = round((float) ($scoreMap[$item->getKey()] ?? 0), 6);
                return $item;
            });

        if ($this->highlightTagOpen) {
            $sorted = $this->applyHighlighting($sorted);
        }

        if ($this->debugMode) {
            $sorted = $this->addDebugInfo($sorted);
        }

        event(new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
            searchTerm:     $this->searchTerm,
            columns:        $this->searchableColumns,
            algorithm:      'bm25',
            candidateCount: count($ids),
            latencyMs:      round((microtime(true) - $startedAt) * 1000, 2),
        ));

        return $sorted;
    }

    /**
     * Execute search using Fuse-style extended/boolean syntax.
     * Routes through Lexer → ExtendedQueryParser → AstCompiler.
     */
    protected function executeExtendedSearch(): Collection
    {
        $startedAt = microtime(true);

        $columns = !empty($this->searchableColumns)
            ? $this->searchableColumns
            : $this->autoDetectColumnsForExtended();

        if (empty($columns)) {
            throw new \Ashiqfardus\LaravelFuzzySearch\Exceptions\SearchableColumnsNotFoundException();
        }

        $tokens = (new \Ashiqfardus\LaravelFuzzySearch\Query\Lexer())->tokenize($this->extendedQuery);
        $ast    = (new \Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser())->parse($tokens);

        $compiler  = new \Ashiqfardus\LaravelFuzzySearch\Query\AstCompiler();
        $rawQuery  = $this->query instanceof \Illuminate\Database\Eloquent\Builder
            ? $this->query->getQuery()
            : $this->query;

        $compiler->compile($ast, $rawQuery, $columns);

        // Apply remaining filters (mirroring buildQuery behavior)
        foreach ($this->filters as $filter) {
            if ($filter['operator'] === 'IN') {
                $this->query->whereIn($filter['column'], $filter['value']);
            } else {
                $this->query->where($filter['column'], $filter['operator'], $filter['value']);
            }
        }

        $maxCandidates = config('fuzzy-search.max_candidates', 1000);
        $candidates = $this->query->limit($maxCandidates)->get();

        if ($this->withRelevance) {
            $candidates = $this->calculateRelevanceScores($candidates);
        }

        $results = $candidates->slice($this->offset, $this->limit)->values();

        if ($this->highlightTagOpen) {
            $results = $this->applyHighlighting($results);
        }

        if ($this->debugMode) {
            $results = $this->addDebugInfo($results);
        }

        event(new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
            searchTerm:     $this->extendedQuery,
            columns:        $columns,
            algorithm:      'extended',
            candidateCount: $candidates->count(),
            latencyMs:      round((microtime(true) - $startedAt) * 1000, 2),
        ));

        return $results;
    }

    /**
     * Auto-detect searchable columns for extended search from the model.
     */
    private function autoDetectColumnsForExtended(): array
    {
        if ($this->query instanceof \Illuminate\Database\Eloquent\Builder) {
            $model = $this->query->getModel();
            if (method_exists($model, 'getSearchableColumns')) {
                return $model->getSearchableColumns();
            }
        }
        return [];
    }

    /**
     * Resolve the model class for the inverted index lookup.
     */
    protected function resolveIndexModelClass(): ?string
    {
        if ($this->invertedIndexModelClass !== null) {
            return $this->invertedIndexModelClass;
        }

        if ($this->query instanceof \Illuminate\Database\Eloquent\Builder) {
            return $this->query->getModel()::class;
        }

        return null;
    }

    /**
     * Get paginated results
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // BM25 fast path via inverted index
        if ($this->useSearchIndex && !empty($this->searchTerm)) {
            return $this->paginateIndexed($perPage, $pageName, $page);
        }

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
     * Paginate using BM25 inverted index
     */
    protected function paginateIndexed(int $perPage, string $pageName, ?int $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $startedAt  = microtime(true);
        $modelClass = $this->resolveIndexModelClass();

        if ($modelClass === null) {
            if (config('app.debug', false)) {
                \Illuminate\Support\Facades\Log::notice(
                    'fuzzy-search: paginate() with useInvertedIndex() skipped — could not resolve model class. Falling back to LIKE.'
                );
            }
            $this->useSearchIndex = false;
            return $this->paginate($perPage, $pageName, $page);
        }

        $page   = $page ?: request()->input($pageName, 1);
        $offset = ($page - 1) * $perPage;

        $indexManager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
        $scorer       = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class);

        $terms = $indexManager->processTerms($this->searchTerm);

        // Fetch enough results to cover the page (cap at a reasonable max)
        $maxResults = min($offset + $perPage * 5, 10000);
        $allResults = $scorer->search($terms, $modelClass, $maxResults);
        $total      = $allResults->count();

        $pageResults = $allResults->slice($offset, $perPage)->values();

        if ($pageResults->isEmpty()) {
            event(new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
                searchTerm:     $this->searchTerm,
                columns:        $this->searchableColumns,
                algorithm:      'bm25',
                candidateCount: $total,
                latencyMs:      round((microtime(true) - $startedAt) * 1000, 2),
            ));

            return new \Illuminate\Pagination\LengthAwarePaginator(
                [], $total, $perPage, $page,
                ['path' => request()->url(), 'pageName' => $pageName]
            );
        }

        $ids      = $pageResults->pluck('model_id')->toArray();
        $scoreMap = $pageResults->pluck('score', 'model_id');

        if ($this->query instanceof \Illuminate\Database\Eloquent\Builder) {
            $keyName = $this->query->getModel()->getKeyName();
            $models  = $this->query->whereIn($keyName, $ids)->get();
        } else {
            $keyName = (new $modelClass)->getKeyName();
            $models  = $modelClass::whereIn($keyName, $ids)->get();
        }

        $sorted = $models->sortByDesc(fn($m) => $scoreMap[$m->getKey()] ?? 0)
            ->values()
            ->map(function ($item) use ($scoreMap) {
                $item->_score = round((float) ($scoreMap[$item->getKey()] ?? 0), 6);
                return $item;
            });

        if ($this->highlightTagOpen) {
            $sorted = $this->applyHighlighting($sorted);
        }

        event(new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
            searchTerm:     $this->searchTerm,
            columns:        $this->searchableColumns,
            algorithm:      'bm25',
            candidateCount: $total,
            latencyMs:      round((microtime(true) - $startedAt) * 1000, 2),
        ));

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $sorted, $total, $perPage, $page,
            ['path' => request()->url(), 'pageName' => $pageName]
        );
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
                                array_merge($this->options, ['accent_insensitive' => $this->accentInsensitiveEnabled]),
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
                            array_merge($this->options, ['accent_insensitive' => $this->accentInsensitiveEnabled]),
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

                // Exact match - highest score
                if ($value === $term) {
                    $colScore = 100 * $weight;
                }
                // Prefix match - very high score
                elseif (str_starts_with($value, $term)) {
                    $colScore = 80 * $weight * $this->prefixBoostMultiplier;
                }
                // Contains - high score
                elseif (str_contains($value, $term)) {
                    $colScore = 60 * $weight;
                }
                // Similarity-based scoring for fuzzy matches
                else {
                    // Use similar_text for percentage-based similarity
                    $similarity = 0;
                    similar_text($term, $value, $similarity);

                    // Also check Levenshtein distance
                    $distance = FuzzySearch::levenshteinDistance($value, $term);

                    // Use the better of the two scores
                    $similarityScore = ($similarity / 100) * 50 * $weight;
                    $levenshteinScore = ($distance <= $this->typoTolerance)
                        ? max(0, (20 - $distance * 4)) * $weight
                        : 0;

                    $colScore = max($similarityScore, $levenshteinScore);
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
                $item->_score = round($score, 2);
                if ($this->debugMode) {
                    $item->_column_scores = $columnScores;
                }
            } elseif (is_array($item)) {
                $item['_score'] = round($score, 2);
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

        $term    = strtolower(trim($this->searchTerm));
        $termLen = strlen($term);

        try {
            // Query the term dictionary — fast and accurate at any dataset size
            $candidates = \Illuminate\Support\Facades\DB::table('fuzzy_index_terms')
                ->select('term', 'doc_count')
                ->where('term', '!=', $term)
                ->whereRaw('LENGTH(term) BETWEEN ? AND ?', [
                    max(1, $termLen - 3),
                    $termLen + 3,
                ])
                ->orderByDesc('doc_count')
                ->limit(300)
                ->get();
        } catch (\Illuminate\Database\QueryException $e) {
            // Index tables don't exist — gracefully return empty
            if (config('app.debug', false)) {
                \Illuminate\Support\Facades\Log::notice(
                    'fuzzy-search: didYouMean() returning empty — fuzzy_index_terms table missing. Run migrations.'
                );
            }
            return [];
        }

        if ($candidates->isEmpty()) {
            return [];
        }

        $alternatives = [];
        foreach ($candidates as $candidate) {
            $distance = levenshtein($term, $candidate->term);
            $maxLen   = max($termLen, strlen($candidate->term));

            if ($distance > 0 && $distance <= 3) {
                $alternatives[] = [
                    'term'        => $candidate->term,
                    'distance'    => $distance,
                    'confidence'  => round(1 - ($distance / $maxLen), 2),
                    '_doc_count'  => $candidate->doc_count,
                ];
            }
        }

        usort($alternatives, function ($a, $b) {
            // Sort by doc_count first (descending) — most common suggestions first
            if ($a['_doc_count'] !== $b['_doc_count']) {
                return $b['_doc_count'] - $a['_doc_count'];
            }
            // Then by distance (ascending) as tiebreaker
            if ($a['distance'] !== $b['distance']) {
                return $a['distance'] - $b['distance'];
            }
            // Finally by confidence (descending)
            return $b['confidence'] <=> $a['confidence'];
        });

        return array_slice(
            array_map(
                fn($a) => ['term' => $a['term'], 'distance' => $a['distance'], 'confidence' => $a['confidence']],
                $alternatives
            ),
            0,
            $limit
        );
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

