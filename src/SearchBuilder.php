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
use Ashiqfardus\LaravelFuzzySearch\Exceptions\InvalidConfigException;
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

    /**
     * Cache of resolveColumnTargets(); emptied whenever searchIn() changes the column list.
     *
     * @var array<string, array{relation: ?string, column: string}>
     */
    protected array $columnTargets = [];
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

    protected bool $stopWordsOverridden = false;
    protected ?string $stopWordLocale = null;
    protected array $synonyms = [];
    protected array $synonymGroups = [];
    protected ?string $locale = null;
    protected bool $accentInsensitiveEnabled = false;
    protected bool $unicodeNormalizeEnabled = false;
    protected bool $debugMode = false;
    protected bool $useSearchIndex = false;
    protected bool $asYouType = false;
    protected ?string $invertedIndexModelClass = null;
    /** @var array<string, float> Weighted terms of the last inverted-index query — see indexedQueryTerms(). */
    protected array $indexedTermWeights = [];
    protected ?string $extendedQuery = null;
    protected ?int $cacheMinutes = null;
    protected ?string $cacheKey = null;
    protected bool $stableRankingEnabled = false;
    protected array $fallbackAlgorithms = [];
    protected ?int $debounceMs = null;
    protected int $maxPatterns = 100;
    protected array $scoring = ['exact_match' => 100, 'prefix_match' => 80, 'contains' => 60, 'fuzzy_match' => 50];

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
        $this->unicodeNormalizeEnabled  = (bool) config('fuzzy-search.unicode.normalize', false);
        $this->scoring     = array_merge($this->scoring, array_filter(config('fuzzy-search.scoring', []), 'is_numeric'));
        $this->maxPatterns = (int) config('fuzzy-search.performance.max_patterns', 100);
        // Forwarded through $options (merged into every applyFuzzyWhere() call) rather than
        // left to BaseDriver::capPatterns()'s own config fallback: the FuzzySearch singleton
        // is resolved eagerly at service-provider boot, so its config snapshot predates any
        // config() override made later inside a test or request.
        $this->options['max_patterns'] = $this->maxPatterns;

        if (config('fuzzy-search.highlighting.enabled', false)) {
            $this->highlightTagOpen  = (string) config('fuzzy-search.highlighting.tag_open', '<em>');
            $this->highlightTagClose = (string) config('fuzzy-search.highlighting.tag_close', '</em>');
        }
    }

    /**
     * Set the search term
     *
     * The empty-search guard is deferred to executeSearch() so that callers
     * using the extended()/searchBoolean() pattern can override the term
     * before execution:
     *
     *   User::search('')->extended('=John ^Doe')->get();  // safe
     *   User::search('=John ^Doe')->extended()->get();    // also safe (preferred)
     *
     * @param string $term
     * @return self
     * @throws EmptySearchTermException (deferred to get()) if term is empty and config doesn't allow it
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
            $col = is_string($key) ? $key : $value;
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $col)) {
                throw new \InvalidArgumentException("Invalid column name [{$col}]: only letters, digits, underscores, and dots allowed.");
            }
            if (is_string($key)) {
                if (!in_array($key, $this->searchableColumns, true)) {
                    $this->searchableColumns[] = $key;
                }
                $this->columnWeights[$key] = (int) $value;
            } else {
                if (!in_array($value, $this->searchableColumns, true)) {
                    $this->searchableColumns[] = $value;
                }
                $this->columnWeights[$value] = 1;
            }
        }
        $this->columnTargets = [];
        return $this;
    }

    /**
     * Resolve every searchIn() column into either a direct column or a relation path.
     *
     * Decision D2: `a.b[.c]` is a relation path only when `a` (and each further segment)
     * is a relation method on the model; otherwise a two-segment name is the v2.0
     * table-qualified column `table.column` and is passed through untouched.
     *
     * @return array<string, array{relation: ?string, column: string}> keyed by the searchIn() column
     */
    protected function resolveColumnTargets(): array
    {
        if (!empty($this->columnTargets)) {
            return $this->columnTargets;
        }

        $model   = $this->query instanceof EloquentBuilder ? $this->query->getModel() : null;
        $targets = [];

        foreach ($this->searchableColumns as $column) {
            $targets[$column] = $this->resolveColumnTarget($column, $model);
        }

        return $this->columnTargets = $targets;
    }

    /** @return array{relation: ?string, column: string} */
    protected function resolveColumnTarget(string $column, ?Model $model): array
    {
        if (!str_contains($column, '.')) {
            return ['relation' => null, 'column' => $column];
        }

        $segments = explode('.', $column);
        $leaf     = array_pop($segments);

        foreach ([...$segments, $leaf] as $segment) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $segment)) {
                throw new \InvalidArgumentException("Invalid column name [{$column}]: each dotted segment must be an identifier.");
            }
        }

        if ($model !== null && $this->isRelationPath($model, $segments)) {
            return ['relation' => implode('.', $segments), 'column' => $leaf];
        }

        if (count($segments) === 1) {
            return ['relation' => null, 'column' => $column]; // table.column (v2.0 behaviour)
        }

        throw new \InvalidArgumentException(
            $model === null
                ? "Relation search [{$column}] needs an Eloquent model: use Model::search() instead of a Query Builder."
                : "[{$column}]: [{$segments[0]}] is not a relation on " . get_class($model) . '.'
        );
    }

    /** True when every segment is a relation method, following the chain model by model. */
    protected function isRelationPath(Model $model, array $segments): bool
    {
        $current = $model;

        foreach ($segments as $segment) {
            if (!method_exists($current, $segment)) {
                return false;
            }

            try {
                $relation = $current->{$segment}();
            } catch (\Throwable) {
                return false;
            }

            if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                return false;
            }

            $current = $relation->getRelated();
        }

        return true;
    }

    /** @return array<string, string> searchIn() column => column for direct (non-relation) targets */
    protected function directTargets(): array
    {
        $direct = [];
        foreach ($this->resolveColumnTargets() as $column => $target) {
            if ($target['relation'] === null) {
                $direct[$column] = $target['column'];
            }
        }
        return $direct;
    }

    /** @return array<string, array{relation: string, column: string}> searchIn() column => target for relation targets */
    protected function relationTargets(): array
    {
        return array_filter($this->resolveColumnTargets(), fn ($t) => $t['relation'] !== null);
    }

    /** @return string[] unique relation paths, for eager loading */
    protected function relationPaths(): array
    {
        return array_values(array_unique(array_column($this->relationTargets(), 'relation')));
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
     * Pass the query string here, OR set it via search() and call searchBoolean() with no args.
     *
     * Example: searchBoolean('term1 (term2 | term3) !term4')
     */
    public function searchBoolean(?string $query = null): self
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
        $this->stopWordsOverridden = true;
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
    public function highlight(?string $tagOrOpen = null, ?string $close = null): self
    {
        if ($tagOrOpen === null) {
            $this->highlightTagOpen  = (string) config('fuzzy-search.highlighting.tag_open', '<em>');
            $this->highlightTagClose = (string) config('fuzzy-search.highlighting.tag_close', '</em>');
            return $this;
        }

        if ($close === null) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $tagOrOpen)) {
                throw new \InvalidArgumentException("Invalid HTML tag name for highlight(): [{$tagOrOpen}]");
            }
            $this->highlightTagOpen  = "<{$tagOrOpen}>";
            $this->highlightTagClose = "</{$tagOrOpen}>";
        } else {
            $this->highlightTagOpen  = $tagOrOpen;
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
            'column_targets' => $this->resolveColumnTargets(),
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
            'index_terms' => $this->indexedTermWeights,
            'as_you_type' => $this->asYouType,
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

    /**
     * As-you-type mode for the inverted index: the last query token also matches every
     * dictionary term that starts with it (up to bm25.prefix.max_expansions, most common
     * first), so "joh" finds "john" and "johnny" while the user is still typing.
     */
    public function asYouType(bool $enabled = true): self
    {
        $this->asYouType = $enabled;
        return $this;
    }

    /**
     * @deprecated since v2.0.0 — use useInvertedIndex() instead.
     * @see useInvertedIndex()
     */
    public function useIndex(string|bool|null $modelClass = true): self
    {
        trigger_error('useIndex() is deprecated since v2.0.0; use useInvertedIndex() instead.', E_USER_DEPRECATED);
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
     * Add a fallback algorithm. When the primary search (LIKE-pattern or BM25) returns no
     * rows, the same search is re-run with each fallback in the order given, until one
     * returns results. Applies to get(), first(), paginate(), simplePaginate() and count().
     */
    public function fallback(string $algorithm): self
    {
        $this->fallbackAlgorithms[] = $algorithm;
        return $this;
    }

    /**
     * @deprecated since 2.1.0 — a server-side "debounce" cannot exist: by the time this
     *             builder runs, the request has already arrived. Debounce on the client
     *             (`wire:model.live.debounce.300ms`, a JS timer). This method is a no-op
     *             and will be removed in v3.0.0.
     */
    public function debounce(int $ms): self
    {
        trigger_error(
            'SearchBuilder::debounce() is deprecated since 2.1.0 and does nothing — debounce on the client instead. It will be removed in v3.0.0.',
            E_USER_DEPRECATED
        );

        $this->debounceMs = $ms;
        return $this;
    }

    /**
     * Limit maximum patterns generated
     */
    public function maxPatterns(int $max): self
    {
        $this->maxPatterns = max(1, $max);
        $this->options['max_patterns'] = $this->maxPatterns; // read by BaseDriver::capPatterns()
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
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $column)) {
            throw new \InvalidArgumentException(
                "Invalid column name: '{$column}'. Column names must match [a-zA-Z_][a-zA-Z0-9_.]* ."
            );
        }
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
     * Apply arbitrary constraints to the underlying Eloquent / Query builder.
     *
     *   User::search('john')->query(fn ($q) => $q->where('tenant_id', 1)->with('roles'))->get();
     */
    public function query(Closure $callback): self
    {
        $callback($this->query);
        return $this;
    }

    /** Methods that execute or mutate and must never bypass the search WHERE clauses. */
    private const TERMINAL_METHODS = [
        'get', 'first', 'firstOrFail', 'find', 'findOrFail', 'count', 'exists', 'doesntExist',
        'pluck', 'value', 'sum', 'avg', 'min', 'max', 'aggregate', 'paginate', 'simplePaginate',
        'cursorPaginate', 'cursor', 'lazy', 'chunk', 'chunkById', 'each', 'delete', 'forceDelete',
        'update', 'increment', 'decrement', 'insert', 'insertGetId', 'upsert', 'truncate', 'toBase',
        'toRawSql', 'dd', 'dump', 'sole',
    ];

    /** Fluent builder methods safe to forward (prefix match: "where" covers whereIn, whereHas, ...). */
    private const FORWARDABLE_PREFIXES = [
        'where', 'orWhere', 'with', 'without', 'join', 'leftJoin', 'rightJoin', 'crossJoin',
        'select', 'addSelect', 'distinct', 'groupBy', 'having', 'orHaving', 'latest', 'oldest',
        'inRandomOrder', 'reorder', 'when', 'unless', 'tap', 'from', 'lock', 'sharedLock',
        'lockForUpdate', 'useWritePdo', 'onlyTrashed', 'withTrashed', 'withoutTrashed',
        'withCount', 'withSum', 'withMin', 'withMax', 'withAvg', 'withExists', 'has', 'orHas',
        'doesntHave', 'orDoesntHave', 'whereBelongsTo', 'scopes',
    ];

    /**
     * Forward fluent Eloquent / Query Builder calls and local scopes to the underlying query,
     * so `User::search('x')->where(...)->with(...)->activeScope()->get()` reads like Eloquent.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (in_array($method, self::TERMINAL_METHODS, true)) {
            throw new \BadMethodCallException(
                "SearchBuilder::{$method}() is not forwarded because it would run without the search " .
                "conditions. Add constraints with where()/query(), then call get(), first(), count() or paginate()."
            );
        }

        if ($this->query instanceof EloquentBuilder && $this->query->hasNamedScope($method)) {
            // callNamedScope() is protected on this Laravel version; scopes() is the public
            // entry point and delegates to it, mutating the same underlying query builder.
            $this->query->scopes([$method => $parameters]);
            return $this;
        }

        foreach (self::FORWARDABLE_PREFIXES as $prefix) {
            if (str_starts_with($method, $prefix)) {
                $result = $this->query->{$method}(...$parameters);
                // Fluent calls return the builder; keep the SearchBuilder chain going.
                if ($result instanceof Builder || $result instanceof EloquentBuilder) {
                    return $this;
                }
                return $result;
            }
        }

        throw new \BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
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
                return $this->executeWithFallback();
            });
        }

        return $this->executeWithFallback();
    }

    /**
     * Run executeSearch(), retrying with each fallback() algorithm while the result is empty.
     */
    protected function executeWithFallback(): Collection
    {
        return $this->withFallback(
            fn () => $this->executeSearch(),
            fn (Collection $results) => $results->isEmpty()
        );
    }

    /**
     * Run $attempt once with the primary algorithm; while $isEmpty($result) is true and
     * fallback algorithms remain, restore the untouched base query and run again with the
     * next fallback. Fallbacks always use the LIKE-pattern path (useInvertedIndex() is
     * switched off for them), so a BM25 miss can fall back to a typo-tolerant driver.
     *
     * @template T
     * @param  Closure(): T        $attempt
     * @param  Closure(T): bool    $isEmpty
     * @return T
     */
    protected function withFallback(Closure $attempt, Closure $isEmpty): mixed
    {
        if (empty($this->fallbackAlgorithms)) {
            return $attempt();
        }

        $baseQuery = clone $this->query;
        $algorithm = $this->algorithm;
        $useIndex  = $this->useSearchIndex;

        $result = $attempt();

        foreach ($this->fallbackAlgorithms as $fallback) {
            if (!$isEmpty($result)) {
                break;
            }

            $this->query          = clone $baseQuery;
            $this->algorithm      = $fallback;
            $this->useSearchIndex = false;

            $result = $attempt();
        }

        $this->algorithm      = $algorithm;
        $this->useSearchIndex = $useIndex;

        return $result;
    }

    /**
     * Execute the search
     */
    protected function executeSearch(): Collection
    {
        // Deferred empty-search guard (moved from search() so that extended()/searchBoolean()
        // can supply their own query after an empty string was passed to search('').
        if (empty($this->searchTerm) && $this->extendedQuery === null) {
            $allowEmpty = config('fuzzy-search.allow_empty_search', false);
            if (!$allowEmpty) {
                throw new EmptySearchTermException();
            }
        }

        // min_search_length / max_term_length guards — measured in characters, not bytes,
        // so multibyte terms are neither waved through nor cut mid-character.
        if ($this->extendedQuery === null && !empty($this->searchTerm)) {
            $minLength = (int) config('fuzzy-search.min_search_length', 1);
            if (mb_strlen($this->searchTerm, 'UTF-8') < $minLength) {
                return collect();
            }
            $maxLength = (int) config('fuzzy-search.query.max_term_length', 128);
            if (mb_strlen($this->searchTerm, 'UTF-8') > $maxLength) {
                $this->searchTerm = mb_substr($this->searchTerm, 0, $maxLength, 'UTF-8');
            }
        }

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

        $ranked = $scorer->rank($this->indexedQueryTerms($indexManager), $modelClass, $this->columnWeights); // model_id => score, best first

        if (empty($ranked)) {
            return collect();
        }

        // Walk the ranking against the constrained query until the requested window is
        // full. Constraints (filters, wheres, scopes) are applied before the cut, so a
        // selective filter fills its page from lower-ranked matches instead of coming
        // back short or empty.
        $models = \Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::models(
            $this->indexedBaseQuery($modelClass),
            array_keys($ranked),
            $this->offset + $this->limit
        );

        $sorted = $this->attachBm25Scores(
            $models->slice($this->offset, $this->limit)->values(),
            $ranked
        );

        if ($this->highlightTagOpen) {
            $sorted = $this->applyHighlighting($sorted, array_keys($this->indexedTermWeights));
        }

        if ($this->debugMode) {
            $sorted = $this->addDebugInfo($sorted);
        }

        event(new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
            searchTerm:     $this->searchTerm,
            columns:        $this->searchableColumns,
            algorithm:      'bm25',
            candidateCount: count($ranked),
            latencyMs:      round((microtime(true) - $startedAt) * 1000, 2),
        ));

        return $sorted;
    }

    /**
     * The Eloquent query BM25 candidates are checked against: the builder's own query
     * (including wheres the caller applied before wrapping it) or the model's default
     * query, plus any filter()/filterIn() constraints. Always a fresh clone.
     */
    protected function indexedBaseQuery(string $modelClass): EloquentBuilder
    {
        $base = $this->query instanceof EloquentBuilder
            ? clone $this->query
            : $modelClass::query();

        // Eager-load every relation a searchIn() column points at, so PHP rescoring and
        // highlighting on BM25 results read loaded relations instead of issuing one
        // query per row (mirrors buildQuery()'s LIKE/extended-path eager load).
        if ($this->query instanceof EloquentBuilder && !empty($this->relationPaths())) {
            $base->with($this->relationPaths());
        }

        foreach ($this->filters as $filter) {
            if ($filter['operator'] === 'IN') {
                $base->whereIn($filter['column'], $filter['value']);
            } else {
                $base->where($filter['column'], $filter['operator'], $filter['value']);
            }
        }

        return $base;
    }

    /**
     * The weighted terms the inverted index is queried with: the processed tokens at 1.0
     * plus, when typoTolerance() > 0 (and typo_tolerance.enabled), dictionary neighbours
     * within that many edits, damped so an expansion contributes less than the exact term
     * would (a rare expansion can still outscore a common exact term — BM25 weighs rarity).
     * Remembered so highlighting and getDebugInfo() can see what actually ran.
     *
     * @return array<string, float>
     */
    protected function indexedQueryTerms(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager $indexManager): array
    {
        $override = $this->stopWordsOverridden ? $this->stopWords : null;
        $terms    = $indexManager->processTerms($this->searchTerm, $override);
        $weights  = array_fill_keys($terms, 1.0);

        // Synonyms are alternatives the caller declared, not typos: full weight. They are
        // looked up on the raw lowercased words (before stemming) and then processed like
        // any other query text so the stemmer and stop words apply to them too. Both splits
        // are tried: the tokenizer's boundaries (so "laptop," finds the key "laptop") and
        // whitespace (so a key that contains punctuation, like "wi-fi", survives as one word).
        if ($this->synonyms !== [] || $this->synonymGroups !== []) {
            $lower = mb_strtolower(trim($this->searchTerm));
            $words = array_unique(array_merge(
                preg_split('/[^\p{L}\p{M}\p{N}]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY),
                preg_split('/\s+/u', $lower, -1, PREG_SPLIT_NO_EMPTY)
            ));

            foreach ($words as $word) {
                foreach ($this->expandWithSynonyms($word) as $synonym) {
                    if ($synonym === $word) {
                        continue;
                    }
                    foreach ($indexManager->processTerms($synonym, $override) as $term) {
                        $weights[$term] = 1.0;
                    }
                }
            }
            $terms = array_keys($weights);
        }

        $distance = config('fuzzy-search.typo_tolerance.enabled', true) ? $this->typoTolerance : 0;

        if ($distance > 0 && $terms !== []) {
            $fuzzy   = (array) config('fuzzy-search.bm25.fuzzy', []);
            $weights = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander::class)->expand(
                array_map('strval', $terms),
                $distance,
                (int) config('fuzzy-search.typo_tolerance.min_word_length', 4),
                (int) ($fuzzy['max_expansions'] ?? 5),
                (int) ($fuzzy['candidate_pool'] ?? 500),
                (bool) ($fuzzy['damping'] ?? true),
            );
        }

        if ($this->asYouType) {
            // The prefix source is the last RAW word the user typed, processed on its own:
            // $terms is de-duplicated (a repeated last word would vanish) and a trailing
            // stop word must not silently prefix-expand the word before it.
            $rawWords  = preg_split('/[^\p{L}\p{M}\p{N}]+/u', trim($this->searchTerm), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $lastTerms = $rawWords === [] ? [] : $indexManager->processTerms((string) end($rawWords), $override);

            if ($lastTerms !== []) {
                $prefixed = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander::class)->prefix(
                    (string) end($lastTerms),
                    (int) config('fuzzy-search.bm25.prefix.max_expansions', 10)
                );

                // A term reached twice keeps the higher weight (a prefix hit at 1.0 beats a
                // damped typo expansion of the same term; `+=` would have kept the lower one).
                foreach ($prefixed as $term => $weight) {
                    $weights[$term] = max($weights[$term] ?? 0.0, $weight);
                }
            }
        }

        return $this->indexedTermWeights = $weights;
    }

    /**
     * True when the base query carries any WHERE (filters, caller wheres, global scopes),
     * i.e. the BM25 ranking cannot be used as-is.
     */
    protected function hasIndexedConstraints(EloquentBuilder $base): bool
    {
        return !empty($base->toBase()->wheres);
    }

    /**
     * Set _raw_score / _score on a page of models. Normalised against the corpus-wide
     * maximum (the first entry of $ranked), so scores stay comparable across pages.
     *
     * @param array<int|string, float> $ranked model_id => score, best first
     */
    protected function attachBm25Scores(Collection $models, array $ranked): Collection
    {
        $bm25Max = (float) (reset($ranked) ?: 0);

        return $models->map(function ($item) use ($ranked, $bm25Max) {
            $raw = round((float) ($ranked[$item->getKey()] ?? 0), 6);
            $item->_raw_score = $raw;
            $item->_score     = $bm25Max > 0 ? round($raw / $bm25Max, 6) : $raw;
            return $item;
        });
    }

    /**
     * Compile the extended/boolean AST plus filter() constraints onto $this->query.
     * Shared by executeExtendedSearch() and paginateRanked(). Returns the columns used.
     */
    protected function compileExtendedQuery(): array
    {
        if (empty($this->searchableColumns)) {
            $this->searchIn($this->autoDetectColumnsForExtended());
        }
        $columns = $this->searchableColumns;

        if (empty($columns)) {
            throw new \Ashiqfardus\LaravelFuzzySearch\Exceptions\SearchableColumnsNotFoundException();
        }

        $tokens = (new \Ashiqfardus\LaravelFuzzySearch\Query\Lexer())->tokenize($this->extendedQuery);
        $ast    = (new \Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser())->parse($tokens);

        $dbDriver = $this->query->getConnection()->getDriverName();

        // Group the resolved targets: direct columns as before; relation columns by path.
        $direct    = array_values($this->directTargets());
        $relations = [];
        foreach ($this->relationTargets() as $target) {
            $relations[$target['relation']][] = $target['column'];
        }

        // whereHas() needs the Eloquent builder; Query Builder sources never have relations (Task 1).
        $compileTarget = $this->query instanceof EloquentBuilder && !empty($relations)
            ? $this->query
            : ($this->query instanceof EloquentBuilder ? $this->query->getQuery() : $this->query);

        (new \Ashiqfardus\LaravelFuzzySearch\Query\AstCompiler($dbDriver))->compile($ast, $compileTarget, $direct, $relations);

        if ($this->query instanceof EloquentBuilder && !empty($this->relationPaths())) {
            $this->query->with($this->relationPaths());
        }

        foreach ($this->filters as $filter) {
            if ($filter['operator'] === 'IN') {
                $this->query->whereIn($filter['column'], $filter['value']);
            } else {
                $this->query->where($filter['column'], $filter['operator'], $filter['value']);
            }
        }

        return $columns;
    }

    /**
     * Execute search using Fuse-style extended/boolean syntax.
     * Routes through Lexer → ExtendedQueryParser → AstCompiler.
     */
    protected function executeExtendedSearch(): Collection
    {
        $startedAt = microtime(true);

        $columns = $this->compileExtendedQuery();

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
     * Get paginated results.
     *
     * Ranks globally: fetches up to max_candidates rows, rescores in PHP, and slices the
     * page from that ranked set. Pages whose offset falls beyond max_candidates fall back
     * to database-level ordering for that page (see paginateRanked()). Works with
     * extended()/searchBoolean() as well as the plain LIKE-driver path.
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->withFallback(
            fn () => $this->paginateOnce($perPage, $pageName, $page),
            fn (\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator) => $paginator->total() === 0
        );
    }

    /**
     * One pagination attempt with the current algorithm (see withFallback()).
     */
    protected function paginateOnce(int $perPage, string $pageName, ?int $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        // BM25 fast path via inverted index
        if ($this->useSearchIndex && !empty($this->searchTerm)) {
            return $this->paginateIndexed($perPage, $pageName, $page);
        }

        return $this->paginateRanked($perPage, $pageName, $page);
    }

    /**
     * Length-aware pagination that ranks globally: fetch up to max_candidates rows, rescore in
     * PHP, slice the page. total() is the real DB count. For pages whose offset is beyond the
     * candidate ceiling, fall back to DB-level ordering for that page (documented limitation).
     */
    protected function paginateRanked(int $perPage, string $pageName, ?int $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $startedAt = microtime(true);
        $page      = (int) ($page ?: request()->input($pageName, 1));
        $page      = max(1, $page);
        $offset    = ($page - 1) * $perPage;

        if ($this->extendedQuery !== null) {
            $this->compileExtendedQuery();
            $algorithm = 'extended';
            $term      = $this->extendedQuery;
        } else {
            $this->buildQuery();
            $algorithm = $this->algorithm ?? config('fuzzy-search.default_algorithm', 'fuzzy');
            $term      = $this->searchTerm;
        }

        // toBase() applies global scopes (SoftDeletes, tenant scopes, ...); getQuery() does
        // not. The page items come from $this->query->clone()->get(), which does apply
        // scopes, so using getQuery() here overcounted total()/lastPage() for any scoped
        // model (mirrors the fix already applied in count()).
        $base  = $this->query instanceof EloquentBuilder ? $this->query->toBase() : $this->query;
        $total = $base->getCountForPagination();

        $maxCandidates = (int) config('fuzzy-search.max_candidates', 1000);

        if ($offset >= $maxCandidates) {
            // Deep page beyond the rescoring window: DB order for this page, score within it.
            $items = collect($this->query->clone()->offset($offset)->limit($perPage)->get());
        } else {
            $candidates = $this->query->clone()->limit($maxCandidates)->get();
            if ($this->withRelevance && $term !== '') {
                $candidates = $this->calculateRelevanceScores($candidates);
            }
            $items = $candidates->slice($offset, $perPage)->values();
        }

        if ($this->withRelevance && $offset >= $maxCandidates && $term !== '') {
            $items = $this->calculateRelevanceScores($items);
        }

        if ($this->highlightTagOpen) {
            $items = $this->applyHighlighting($items);
        }
        if ($this->debugMode) {
            $items = $this->addDebugInfo($items);
        }

        event(new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
            searchTerm:     $term,
            columns:        $this->searchableColumns,
            algorithm:      $algorithm,
            candidateCount: $total,
            latencyMs:      round((microtime(true) - $startedAt) * 1000, 2),
        ));

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $items, $total, $perPage, $page,
            ['path' => request()->url(), 'pageName' => $pageName]
        );
    }

    /**
     * Paginate using BM25 inverted index
     */
    protected function paginateIndexed(int $perPage, string $pageName, ?int $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $perPage    = min((int) $perPage, 100);
        $startedAt  = microtime(true);
        $modelClass = $this->resolveIndexModelClass();

        if ($modelClass === null) {
            if (config('app.debug', false)) {
                \Illuminate\Support\Facades\Log::notice(
                    'fuzzy-search: paginate() with useInvertedIndex() skipped — could not resolve model class. Falling back to LIKE.'
                );
            }
            $this->useSearchIndex = false;
            return $this->paginateOnce($perPage, $pageName, $page);
        }

        $page   = $page ?: request()->input($pageName, 1);
        $offset = ($page - 1) * $perPage;

        ['total' => $total, 'ranked' => $ranked, 'base' => $base] = $this->indexedRankingAndTotal($modelClass);

        if (empty($ranked)) {
            $sorted = collect();
        } else {
            $ids = array_keys($ranked);

            // Page: walk the ranking against the constrained query until offset + perPage
            // rows are collected, then slice.
            $models = \Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::models(
                $base, $ids, $offset + $perPage
            );

            $sorted = $this->attachBm25Scores($models->slice($offset, $perPage)->values(), $ranked);
        }

        if ($this->highlightTagOpen) {
            $sorted = $this->applyHighlighting($sorted, array_keys($this->indexedTermWeights));
        }

        if ($this->debugMode) {
            $sorted = $this->addDebugInfo($sorted);
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
     * Rank the current search term against $modelClass's BM25 index and total the matches
     * against the constrained base query. Shared by paginateIndexed() (which also builds the
     * page from the returned ranking/base) and count() (which only needs the total), so the
     * two can never disagree.
     *
     * @return array{total: int, ranked: array<int|string, float>, base: EloquentBuilder}
     */
    protected function indexedRankingAndTotal(string $modelClass): array
    {
        $indexManager = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class);
        $scorer       = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class);

        $terms  = $this->indexedQueryTerms($indexManager);
        $ranked = $scorer->rank($terms, $modelClass, $this->columnWeights); // model_id => score, best first
        $base   = $this->indexedBaseQuery($modelClass);

        if (empty($ranked)) {
            return ['total' => 0, 'ranked' => $ranked, 'base' => $base];
        }

        // Total: under constraints, count the ranked ids the constrained query accepts
        // (chunked); otherwise a single COUNT(DISTINCT) over the postings is exact.
        $total = $this->hasIndexedConstraints($base)
            ? \Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::count($base, array_keys($ranked))
            : $scorer->count($terms, $modelClass);

        return ['total' => $total, 'ranked' => $ranked, 'base' => $base];
    }

    /**
     * Simple pagination (offset-based, no total count).
     * Routes through the same search path as get() so extended-syntax and BM25 are honoured.
     */
    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \Illuminate\Contracts\Pagination\Paginator
    {
        $page   = $page ?: (int) request()->input($pageName, 1);
        $offset = ($page - 1) * $perPage;

        // Fetch one extra item so Paginator::setItems() can detect whether a next
        // page exists (it sets hasMore = count($items) > $perPage, then trims internally).
        // Save and restore so that a re-used SearchBuilder instance is not permanently
        // mutated by the pagination call.
        $savedLimit   = $this->limit;
        $savedOffset  = $this->offset;
        $this->limit  = $perPage + 1;
        $this->offset = $offset;
        $all          = $this->get();
        $this->limit  = $savedLimit;
        $this->offset = $savedOffset;

        return new \Illuminate\Pagination\Paginator(
            $all, $perPage, $page,
            ['path' => request()->url(), 'pageName' => $pageName]
        );
    }

    /**
     * Not supported — cursor pagination is incompatible with PHP-side relevance scoring.
     * Use paginate() or simplePaginate() instead.
     *
     * @throws \BadMethodCallException always
     */
    public function cursorPaginate(int $perPage = 15): never
    {
        throw new \BadMethodCallException(
            'cursorPaginate() is not supported by FuzzySearch — it bypasses PHP-side relevance scoring. ' .
            'Use paginate() for full pagination or simplePaginate() for forward-only pagination.'
        );
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
        return $this->withFallback(
            function (): int {
                // Mirror paginateOnce()/paginateRanked() so count() never disagrees with
                // paginate()->total() on the same builder.
                if ($this->useSearchIndex && !empty($this->searchTerm)) {
                    $modelClass = $this->resolveIndexModelClass();

                    if ($modelClass !== null) {
                        return $this->indexedRankingAndTotal($modelClass)['total'];
                    }

                    if (config('app.debug', false)) {
                        \Illuminate\Support\Facades\Log::notice(
                            'fuzzy-search: count() with useInvertedIndex() skipped — could not resolve model class. Falling back to LIKE.'
                        );
                    }
                    $this->useSearchIndex = false;
                }

                if ($this->extendedQuery !== null) {
                    $this->compileExtendedQuery();
                } else {
                    $this->buildQuery();
                }

                // Query\Builder::count() keeps columns/orders/limit/offset, and the relevance
                // ORDER BY buildQuery() adds makes PostgreSQL reject the aggregate ("column
                // must appear in the GROUP BY clause..."). getCountForPagination() drops them.
                $base = $this->query instanceof EloquentBuilder ? $this->query->toBase() : $this->query;
                return (int) $base->getCountForPagination();
            },
            fn (int $count) => $count === 0
        );
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

        // Eager-load every relation a searchIn() column points at, so PHP rescoring and
        // highlighting read loaded relations instead of issuing one query per row.
        if ($this->query instanceof EloquentBuilder && !empty($this->relationPaths())) {
            $this->query->with($this->relationPaths());
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
            $keyColumn = $this->query instanceof EloquentBuilder
                ? $this->query->getModel()->getQualifiedKeyName()
                : 'id';
            $this->query->orderBy($keyColumn, 'asc');
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

        $targets = $this->resolveColumnTargets();

        if ($this->tokenMatchMode === 'all' && $this->tokenizeSearch) {
            // Every token must match at least one column
            foreach ($tokens as $token) {
                $tokenTerms = $this->expandWithSynonyms($token);
                $this->query->where(function ($q) use ($tokenTerms, $targets) {
                    $first = true;
                    foreach ($tokenTerms as $term) {
                        foreach ($targets as $target) {
                            $this->applyColumnCondition($q, $target, $term, $first ? 'and' : 'or');
                            $first = false;
                        }
                    }
                });
            }
            return;
        }

        // Any token can match any column
        $this->query->where(function ($q) use ($allTerms, $targets) {
            $first = true;
            foreach ($allTerms as $term) {
                foreach ($targets as $target) {
                    $this->applyColumnCondition($q, $target, $term, $first ? 'and' : 'or');
                    $first = false;
                }
            }
        });
    }

    /**
     * Apply one searchIn() column's fuzzy condition to $query (an Eloquent or Query
     * builder inside a where-group). Direct columns go straight to the driver; relation
     * columns wrap the driver condition in whereHas()/orWhereHas() on the relation path,
     * which Eloquent compiles to a portable EXISTS subquery.
     *
     * @param array{relation: ?string, column: string} $target
     */
    protected function applyColumnCondition($query, array $target, string $term, string $boolean): void
    {
        $options = array_merge($this->options, ['accent_insensitive' => $this->accentInsensitiveEnabled]);

        if ($target['relation'] === null) {
            $subQuery = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
            $this->fuzzySearch->applyFuzzyWhere($subQuery, $target['column'], $term, $this->algorithm, $options, $boolean);
            return;
        }

        $method = $boolean === 'or' ? 'orWhereHas' : 'whereHas';
        $query->{$method}($target['relation'], function (EloquentBuilder $related) use ($target, $term, $options) {
            $this->fuzzySearch->applyFuzzyWhere($related->getQuery(), $target['column'], $term, $this->algorithm, $options, 'and');
        });
    }

    /**
     * Apply relevance ordering
     */
    protected function applyRelevanceOrdering(): void
    {
        $driver = $this->query->getConnection()->getDriverName();
        $term   = $this->searchTerm;
        // Escape LIKE metacharacters so user input cannot widen the match set (consistent
        // with all driver LIKE paths). The exact-match binding uses the raw term intentionally.
        $safeTerm = addcslashes($term, '%_');

        $scoreExpressions = [];
        $bindings = [];

        foreach ($this->directTargets() as $column => $directColumn) {
            $weight = $this->columnWeights[$column] ?? 1;
            $prefixBoost = $this->prefixBoostMultiplier;
            $col = $this->quoteColumn($directColumn, $driver);

            switch ($driver) {
                case 'mysql':
                case 'mariadb':
                    $scoreExpressions[] = "(CASE WHEN {$col} = ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $bindings = array_merge($bindings, [
                        $term, (int) round($weight * $this->scoring['exact_match']),
                        $safeTerm . '%', (int) round($weight * $this->scoring['prefix_match'] * $prefixBoost),
                        '%' . $safeTerm . '%', (int) round($weight * $this->scoring['contains']),
                    ]);
                    break;

                case 'pgsql':
                    $scoreExpressions[] = "(CASE WHEN {$col} = ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} ILIKE ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} ILIKE ? THEN ? ELSE 0 END)";
                    $bindings = array_merge($bindings, [
                        $term, (int) round($weight * $this->scoring['exact_match']),
                        $safeTerm . '%', (int) round($weight * $this->scoring['prefix_match'] * $prefixBoost),
                        '%' . $safeTerm . '%', (int) round($weight * $this->scoring['contains']),
                    ]);
                    break;

                default:
                    $scoreExpressions[] = "(CASE WHEN {$col} = ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $scoreExpressions[] = "(CASE WHEN {$col} LIKE ? THEN ? ELSE 0 END)";
                    $bindings = array_merge($bindings, [
                        $term, (int) round($weight * $this->scoring['exact_match']),
                        $safeTerm . '%', (int) round($weight * $this->scoring['prefix_match'] * $prefixBoost),
                        '%' . $safeTerm . '%', (int) round($weight * $this->scoring['contains']),
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
        return \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::quoteIdentifier($column, $driver);
    }

    /**
     * Calculate relevance scores for results
     */
    protected function calculateRelevanceScores(Collection $results): Collection
    {
        $term = strtolower($this->searchTerm);

        $results = $results->map(function ($item) use ($term) {
            $score = 0;
            $columnScores = [];

            foreach ($this->resolveColumnTargets() as $column => $target) {
                $weight   = $this->columnWeights[$column] ?? 1;
                $colScore = 0;

                $values = $this->columnValues($item, $column, $target);
                if ($values === [] && $target['relation'] === null) {
                    // v2.0 fed '' into scoreValue() for a missing/NULL direct column, so it
                    // could still earn a fuzzy-floor score under typoTolerance(). Keep that:
                    // an empty relation value stays absent, only direct columns get the ''.
                    $values = [''];
                }

                // A to-many relation contributes its best related row, never an average.
                foreach ($values as $value) {
                    $colScore = max($colScore, $this->scoreValue($value, $term, $weight));
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

        // Score normalization to [0,1] range
        // Preserve raw score for backwards compatibility under _raw_score
        $max = $results->max(function ($item) {
            return is_object($item) ? ($item->_score ?? 0) : ($item['_score'] ?? 0);
        });

        if ($max > 0) {
            $results = $results->map(function ($item) use ($max) {
                $raw = is_object($item) ? ($item->_score ?? 0) : ($item['_score'] ?? 0);
                $normalized = round($raw / $max, 6);
                if (is_object($item)) {
                    $item->_raw_score = $raw;
                    $item->_score     = $normalized;
                } elseif (is_array($item)) {
                    $item['_raw_score'] = $raw;
                    $item['_score']     = $normalized;
                }
                return $item;
            });
        }

        return $results;
    }

    /**
     * Every candidate string for a searchIn() column on one result: the column itself for a
     * direct column; for a relation path, the leaf column of every related row (to-many
     * relations and nested paths contribute one string per related row). Every query path
     * (LIKE in buildQuery(), extended in compileExtendedQuery(), BM25 in indexedBaseQuery(),
     * suggest() in suggestCandidateQuery()) eager-loads relationPaths(), so this reads
     * memory there. For an Eloquent Model, an unloaded relation segment — e.g. a model
     * handed to columnValues()/renderHighlighted() directly rather than through one of
     * those paths — contributes nothing rather than triggering a lazy-load query.
     *
     * @param  array{relation: ?string, column: string} $target
     * @return string[] non-empty strings only
     */
    protected function columnValues($item, string $column, array $target): array
    {
        if ($target['relation'] === null) {
            $value = (string) data_get($item, $target['column'], '');
            return $value === '' ? [] : [$value];
        }

        $rows = [$item];
        foreach (explode('.', $target['relation']) as $segment) {
            $next = [];
            foreach ($rows as $row) {
                if ($row instanceof Model) {
                    // Never trigger Eloquent's magic lazy load here: every search path
                    // eager-loads relationPaths() up front, so an unloaded relation means
                    // this model bypassed that (e.g. a caller-supplied row) — contribute
                    // nothing rather than issuing a query.
                    if (!$row->relationLoaded($segment)) {
                        continue;
                    }
                    $related = $row->{$segment};
                } else {
                    $related = is_object($row) ? ($row->{$segment} ?? null) : null;
                }
                if ($related instanceof \Illuminate\Support\Collection) {
                    foreach ($related as $r) {
                        $next[] = $r;
                    }
                } elseif ($related !== null) {
                    $next[] = $related;
                }
            }
            $rows = $next;
        }

        $values = [];
        foreach ($rows as $row) {
            $value = (string) data_get($row, $target['column'], '');
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * Score one string against the search term: the tier constants from scoring.* times the
     * column weight, or the similarity/Levenshtein floor for fuzzy matches.
     */
    protected function scoreValue(string $value, string $term, float|int $weight): float
    {
        $value = strtolower($value);

        if ($value === $term) {
            return $this->scoring['exact_match'] * $weight;
        }
        if (str_starts_with($value, $term)) {
            return $this->scoring['prefix_match'] * $weight * $this->prefixBoostMultiplier;
        }
        if (str_contains($value, $term)) {
            return $this->scoring['contains'] * $weight;
        }

        $similarity = 0;
        similar_text($term, $value, $similarity);
        $distance = FuzzySearch::levenshteinDistance($value, $term);

        $similarityScore  = ($similarity / 100) * $this->scoring['fuzzy_match'] * $weight;
        $levenshteinScore = ($distance <= $this->typoTolerance) ? max(0, (20 - $distance * 4)) * $weight : 0;

        return max($similarityScore, $levenshteinScore);
    }

    /**
     * Apply highlighting to results. $terms (index path) lists every weighted query term —
     * exact tokens, typo and prefix expansions — so a document that matched through "john"
     * for the query "jonh" still gets its match marked. null (LIKE/extended paths) keeps the
     * single-term behaviour: the whole search string is one needle.
     *
     * @param string[]|null $terms
     */
    protected function applyHighlighting(Collection $results, ?array $terms = null): Collection
    {
        $needles = $terms === null ? [$this->searchTerm] : array_values(array_filter(array_map('strval', $terms), fn ($t) => $t !== ''));
        if ($needles === [] || $needles === ['']) {
            return $results;
        }

        $open  = $this->highlightTagOpen ?? '<em>';
        $close = $this->highlightTagClose ?? '</em>';

        return $results->map(function ($item) use ($needles, $terms, $open, $close) {
            $matches     = [];
            $highlighted = [];

            foreach ($this->resolveColumnTargets() as $column => $target) {
                $values = $this->columnValues($item, $column, $target);
                if (empty($values)) {
                    continue;
                }

                // Highlight the first related value that matches; fall back to the first value.
                $chosen  = $values[0];
                $indices = [];
                foreach ($values as $value) {
                    $found = [];
                    foreach ($needles as $needle) {
                        $found = array_merge($found, $this->findMatchOffsets($value, $needle));
                    }
                    // Merge only on the index path (multiple needles from term expansion):
                    // the LIKE/extended path keeps v2.0's raw, unmerged offsets so adjacent
                    // matches of the same single needle stay separate tags (e.g. "an" in
                    // "banana" stays two <em> pairs instead of collapsing into one).
                    if ($terms !== null) {
                        $found = $this->mergeRanges($found);
                    }
                    if (!empty($found)) {
                        $chosen  = $value;
                        $indices = $found;
                        break;
                    }
                }

                if (!empty($indices)) {
                    $matches[] = ['column' => $column, 'value' => $chosen, 'indices' => $indices];
                    $highlighted[$column] = $this->wrapWithTags($chosen, $indices, $open, $close);
                } else {
                    $highlighted[$column] = $chosen;
                }
            }

            if (is_object($item)) {
                $item->_matches     = $matches;
                $item->_highlighted = $highlighted;
            } elseif (is_array($item)) {
                $item['_matches']     = $matches;
                $item['_highlighted'] = $highlighted;
            }

            return $item;
        });
    }

    /**
     * Find all case-insensitive occurrences of $term in $value.
     * Returns array of [startIdx, endIdx] inclusive ranges.
     */
    private function findMatchOffsets(string $value, string $term): array
    {
        if ($term === '') {
            return [];
        }
        $indices = [];
        $offset  = 0;
        $lower   = strtolower($value);
        $needle  = strtolower($term);

        while (($pos = strpos($lower, $needle, $offset)) !== false) {
            $indices[] = [$pos, $pos + strlen($term) - 1];
            $offset    = $pos + strlen($term);
        }
        return $indices;
    }

    /**
     * Sort [start, end] ranges and merge overlapping or touching ones, so two needles that
     * hit the same characters ("john" and "johnny") produce one tag pair. Only called on the
     * index path (multiple expanded needles); the LIKE/extended path keeps v2.0's raw,
     * unmerged offsets from a single needle, so back-to-back repeats of the same needle
     * (e.g. "an" in "banana") stay separate tags instead of collapsing into one.
     *
     * @param  array<int, array{0: int, 1: int}> $ranges
     * @return array<int, array{0: int, 1: int}>
     */
    private function mergeRanges(array $ranges): array
    {
        if (count($ranges) < 2) {
            return $ranges;
        }
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [array_shift($ranges)];
        foreach ($ranges as [$start, $end]) {
            $last = &$merged[count($merged) - 1];
            if ($start <= $last[1] + 1) {
                $last[1] = max($last[1], $end);
            } else {
                $merged[] = [$start, $end];
            }
            unset($last);
        }
        return $merged;
    }

    private function wrapWithTags(string $value, array $indices, string $open, string $close): string
    {
        if (empty($indices)) {
            return e($value);
        }

        usort($indices, fn($a, $b) => $a[0] <=> $b[0]);

        $out  = '';
        $last = 0;
        foreach ($indices as [$start, $end]) {
            $out .= e(substr($value, $last, $start - $last));
            $out .= $open . e(substr($value, $start, $end - $start + 1)) . $close;
            $last = $end + 1;
        }
        $out .= e(substr($value, $last));
        return $out;
    }

    /**
     * Render a column's value with HTML-escaped match offsets wrapped in <mark>.
     * Used by the @fuzzyHighlight Blade directive.
     *
     * @param mixed  $result A model/array/object with _matches populated
     * @param string $column Column name to render
     * @param string $tag    Wrapper tag (default 'mark')
     */
    public static function renderHighlighted($result, string $column, string $tag = 'mark'): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]*$/', $tag)) {
            throw new \InvalidArgumentException("Invalid HTML tag name for fuzzyHighlight: [{$tag}]");
        }

        $matches = is_object($result) ? ($result->_matches ?? []) : ($result['_matches'] ?? []);

        foreach ($matches as $match) {
            if (($match['column'] ?? null) === $column) {
                // The match carries the exact string that was scored (for a to-many
                // relation that is the related row that matched, which data_get cannot reach).
                return self::escapeAndWrap((string) ($match['value'] ?? ''), $match['indices'] ?? [], $tag);
            }
        }

        return e(self::displayValueFor($result, $column));
    }

    /**
     * Plain (unhighlighted) display value for a column: `_highlighted` when the search
     * produced one, otherwise data_get(). A Collection value is reduced to its first item;
     * anything that isn't a scalar at that point — including a model instance, or a
     * to-many relation collection data_get() couldn't resolve to one — renders as an
     * empty string rather than a warning.
     */
    protected static function displayValueFor($result, string $column): string
    {
        $highlighted = is_object($result) ? ($result->_highlighted ?? []) : ($result['_highlighted'] ?? []);
        if (array_key_exists($column, $highlighted)) {
            return (string) $highlighted[$column];
        }

        $value = data_get($result, $column);
        if ($value instanceof \Illuminate\Support\Collection) {
            $value = $value->first();
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private static function escapeAndWrap(string $value, array $indices, string $tag): string
    {
        if (empty($indices)) {
            return e($value);
        }

        usort($indices, fn($a, $b) => $a[0] <=> $b[0]);

        $out  = '';
        $last = 0;
        foreach ($indices as [$start, $end]) {
            $out .= e(substr($value, $last, $start - $last));
            $out .= '<' . $tag . '>' . e(substr($value, $start, $end - $start + 1)) . '</' . $tag . '>';
            $last = $end + 1;
        }
        $out .= e(substr($value, $last));
        return $out;
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
        // Closures cannot be serialized — skip caching when a custom score callback is set.
        if ($this->customScoreCallback !== null) {
            return 'fuzzy_search_nocache_' . uniqid();
        }

        $data = [
            'term'                   => $this->searchTerm,
            'columns'                => $this->searchableColumns,
            'algorithm'              => $this->algorithm,
            'options'                => $this->options,
            'filters'                => $this->filters,
            'limit'                  => $this->limit,
            'offset'                 => $this->offset,
            'use_search_index'       => $this->useSearchIndex,
            'extended_query'         => $this->extendedQuery,
            'column_weights'         => $this->columnWeights,
            'stop_words'             => $this->stopWords,
            'synonyms'               => $this->synonyms,
            'synonym_groups'         => $this->synonymGroups,
            'locale'                 => $this->locale,
            'accent_insensitive'     => $this->accentInsensitiveEnabled,
            'unicode_normalize'      => $this->unicodeNormalizeEnabled,
            'tokenize_search'        => $this->tokenizeSearch,
            'token_match_mode'       => $this->tokenMatchMode,
            'prefix_boost'           => $this->prefixBoostMultiplier,
            'partial_match'          => $this->partialMatchEnabled,
            'min_match_length'       => $this->minMatchLength,
            'recency_boost'          => $this->recencyBoostEnabled,
            'recency_multiplier'     => $this->recencyBoostMultiplier,
            'recency_column'         => $this->recencyColumn,
            'recency_days'           => $this->recencyDays,
            'sort_by'                => $this->sortBy,
            'stable_ranking'         => $this->stableRankingEnabled,
            'as_you_type'            => $this->asYouType,
            'stop_words_overridden'  => $this->stopWordsOverridden,
            'typo_tolerance'         => $this->typoTolerance,
            'fallback_algorithms'    => $this->fallbackAlgorithms,
            'base_sql'               => $this->query->toSql(),
            'base_bindings'          => $this->query->getBindings(),
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
        // $rawTerm  → PHP str_starts_with / strcmp comparisons (must be unescaped)
        // $safeTerm → LIKE bindings only (% and _ escaped so they match literally)
        // Never swap these: passing $safeTerm to str_starts_with would miss values
        // containing literal '%' or '_', and passing $rawTerm to LIKE would treat
        // those characters as wildcards.
        $rawTerm  = strtolower($this->searchTerm);
        $safeTerm = addcslashes($rawTerm, '%_');

        $results = $this->suggestCandidateQuery($safeTerm)->limit($limit * 3)->get();

        // Extract unique suggestions from results
        foreach ($results as $result) {
            foreach ($this->resolveColumnTargets() as $column => $target) {
                foreach ($this->columnValues($result, $column, $target) as $value) {
                    // Extract the matching word/phrase
                    $words = preg_split('/\s+/', $value);
                    foreach ($words as $word) {
                        $wordLower = strtolower($word);
                        if (str_starts_with($wordLower, $rawTerm) && strlen($word) > strlen($rawTerm)) {
                            $suggestions[$wordLower] = $word;
                        }
                    }

                    // Also add full column value if it starts with the raw search term
                    $valueLower = strtolower($value);
                    if (str_starts_with($valueLower, $rawTerm)) {
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
     * Build (but do not execute) the prefix-match query used by suggest(). Kept separate
     * so its SQL can be pinned per driver via toSql() without needing a live connection for
     * every driver — see tests/Unit/SearchBuilderTest.php.
     */
    protected function suggestCandidateQuery(string $safeTerm): Builder|EloquentBuilder
    {
        $driver = $this->query->getConnection()->getDriverName();

        // Clone query to avoid modifying the original
        $suggestQuery = clone $this->query;

        $targets = $this->resolveColumnTargets();

        $prefixWhere = function ($q, string $column, string $boolean) use ($safeTerm, $driver) {
            if ($driver === 'pgsql') {
                // PostgreSQL's LIKE is case-sensitive; ILIKE matches capitalised values too.
                $q->{$boolean === 'or' ? 'orWhereRaw' : 'whereRaw'}($this->quoteColumn($column, $driver) . ' ILIKE ?', [$safeTerm . '%']);
            } else {
                $q->{$boolean === 'or' ? 'orWhere' : 'where'}($column, 'LIKE', $safeTerm . '%');
            }
        };

        $suggestQuery->where(function ($q) use ($targets, $prefixWhere) {
            $first = true;
            foreach ($targets as $target) {
                if ($target['relation'] === null) {
                    $prefixWhere($q, $target['column'], $first ? 'and' : 'or');
                } else {
                    $q->{$first ? 'whereHas' : 'orWhereHas'}($target['relation'], function ($related) use ($target, $prefixWhere) {
                        $prefixWhere($related, $target['column'], 'and');
                    });
                }
                $first = false;
            }
        });

        if ($suggestQuery instanceof EloquentBuilder && !empty($this->relationPaths())) {
            $suggestQuery->with($this->relationPaths());
        }

        return $suggestQuery;
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
        if (empty($this->searchTerm) || mb_strlen($this->searchTerm) < 2) {
            return [];
        }

        $term    = mb_strtolower(trim($this->searchTerm));
        $termLen = mb_strlen($term);

        // The schema is only inspected when the dictionary query actually fails, so the happy
        // path costs one query instead of two. A missing table (migrations not run) means
        // "nothing to suggest"; anything else is a real SQL error and must surface.
        try {
            $candidates = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander::class)->candidates($term, 3, 300);
        } catch (\Illuminate\Database\QueryException $e) {
            if (\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('fuzzy_index_terms')) {
                throw $e; // a real database error — surface it
            }

            return []; // dictionary not migrated yet
        }

        $alternatives = [];
        foreach ($candidates as $candidate) {
            if ($candidate['distance'] === 0) {
                continue;
            }
            $maxLen = max($termLen, mb_strlen($candidate['term']));

            $alternatives[] = [
                'term'       => $candidate['term'],
                'distance'   => $candidate['distance'],
                'confidence' => round(1 - ($candidate['distance'] / $maxLen), 2),
                '_doc_count' => $candidate['doc_count'],
            ];
        }

        usort($alternatives, function ($a, $b) {
            if ($a['_doc_count'] !== $b['_doc_count']) {
                return $b['_doc_count'] - $a['_doc_count'];
            }
            if ($a['distance'] !== $b['distance']) {
                return $a['distance'] - $b['distance'];
            }
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

