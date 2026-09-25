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
use Ashiqfardus\LaravelFuzzySearch\Support\Accents;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Support\Utf8;
use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{AstNode, AndNode, OrNode, NotNode, FieldTerm};

/**
 * SearchBuilder - Fluent API for building fuzzy search queries
 */
class SearchBuilder
{
    protected Builder|EloquentBuilder $query;
    /** The caller's untouched query while a terminal call runs — see onQueryClone(). */
    protected Builder|EloquentBuilder|null $pristineQuery = null;
    protected FuzzySearch $fuzzySearch;
    protected string $searchTerm = '';
    /** The term was only invalid UTF-8 (`?q=%FF`): it cleaned to '' but was not empty, so it matches nothing. */
    protected bool $invalidBytesOnly = false;
    protected array $searchableColumns = [];
    protected array $columnWeights = [];

    /**
     * Cache of resolveColumnTargets(); emptied whenever searchIn() changes the column list.
     *
     * @var array<string, array{relation: ?string, column: string}>
     */
    protected array $columnTargets = [];
    protected ?string $algorithm = null;
    /** The event built by the most recent get()/paginate() on this builder; see lastExecution(). */
    protected ?\Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted $lastExecution = null;
    protected array $options = [];
    protected bool $withRelevance = true;
    protected int $limit = 15;
    protected int $offset = 0;
    /**
     * Ceiling for FuzzySearchExecuted::resultCount only — never for the query or the returned
     * rows. simplePaginate() fetches perPage + 1 rows so the paginator can see whether a next
     * page exists; that look-ahead row is not a result the caller receives, so it must not be
     * reported as one. null = report whatever was returned.
     */
    protected ?int $reportedResultCap = null;
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
    /** Explicitly asked for: accentInsensitive(), the model's $searchable or a preset — see foldsAccents(). */
    protected bool $accentInsensitiveEnabled = false;
    /** The global unicode.accent_insensitive default: it folds the term into a variant, never more. */
    protected bool $accentFoldingDefault = false;
    protected bool $unicodeNormalizeEnabled = false;
    protected bool $debugMode = false;
    protected bool $useSearchIndex = false;
    protected bool $asYouType = false;
    protected ?string $invertedIndexModelClass = null;
    /** suggest() source: 'auto' (dictionary when the model is indexed), 'index' or 'table'. */
    protected string $suggestSource = 'auto';
    /** @var array<string, float> Weighted terms of the last inverted-index query — see indexedQueryTerms(). */
    protected array $indexedTermWeights = [];
    protected ?string $extendedQuery = null;
    /** @var string[] Positive leaf terms of the compiled extended query — see positiveLeafTerms(). */
    protected array $extendedLeafTerms = [];
    protected ?int $cacheMinutes = null;
    /** cache() was called: it, not cache.enabled, decides whether get() caches (see cacheSeconds()). */
    protected bool $cacheCalled = false;
    protected ?string $cacheKey = null;
    /**
     * While get() fills its cache: what the search asked its rows to be decorated with (highlight
     * terms, debug algorithm), for get() to apply to fresh and cached rows alike — see decorate().
     * null otherwise, and applyHighlighting()/addDebugInfo() then decorate as they are called.
     */
    private ?array $decoration = null;
    /** Whether get()'s last search attempt matched a row before its page cut: what fallback() decides on. */
    private bool $matched = false;
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
        $this->accentFoldingDefault     = (bool) config('fuzzy-search.unicode.accent_insensitive', false);
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
     * The empty-search guard is deferred to the terminal call (guardEmptyTerm()) so that callers
     * using the extended()/searchBoolean() pattern can override the term
     * before execution:
     *
     *   User::search('')->extended('=John ^Doe')->get();  // safe
     *   User::search('=John ^Doe')->extended()->get();    // also safe (preferred)
     *
     * @param string $term
     * @return self
     * @throws EmptySearchTermException (deferred to get(), first(), paginate(), simplePaginate(), count() and getFacets()) if term is empty and config doesn't allow it
     */
    public function search(string $term): self
    {
        $this->searchTerm       = trim(Utf8::clean($term));
        $this->invalidBytesOnly = $this->searchTerm === '' && trim($term) !== '';

        return $this;
    }

    /**
     * Set searchable columns with optional weights
     */
    public function searchIn(array $columns): self
    {
        foreach (SearchableColumns::weights($columns) as $col => $weight) {
            SearchableColumns::validate([$col], SearchableColumns::INVALID_NAME_BRACKETED);
            if (!in_array($col, $this->searchableColumns, true)) {
                $this->searchableColumns[] = $col;
            }
            $this->columnWeights[$col] = $weight;
        }
        $this->columnTargets = [];
        return $this;
    }

    /**
     * Resolve every searchIn() column into either a direct column or a relation path.
     *
     * Decision D2: `a.b[.c]` is a relation path only when `a` (and each further segment)
     * is a relation method on the model; otherwise a two-segment name is the v2.0
     * table-qualified column `table.column` and is passed through untouched. A head that names
     * a table of the query — the FROM table, a joined one, or either's alias — is always that
     * table's column (ruling ER-57), and no method is looked at.
     *
     * @return array<string, array{relation: ?string, column: string}> keyed by the searchIn() column
     */
    protected function resolveColumnTargets(): array
    {
        if (!empty($this->columnTargets)) {
            return $this->columnTargets;
        }

        $model   = $this->query instanceof EloquentBuilder ? $this->query->getModel() : null;
        $tables  = $this->queryTableNames();
        $targets = [];

        foreach ($this->searchableColumns as $column) {
            $targets[$column] = $this->resolveColumnTarget($column, $model, $tables);
        }

        return $this->columnTargets = $targets;
    }

    /**
     * @param  string[] $tables the names the query's tables go by (see queryTableNames())
     * @return array{relation: ?string, column: string}
     */
    protected function resolveColumnTarget(string $column, ?Model $model, array $tables = []): array
    {
        if (!str_contains($column, '.')) {
            return ['relation' => null, 'column' => $column];
        }

        $segments = explode('.', $column);
        $leaf     = array_pop($segments);

        foreach ([...$segments, $leaf] as $segment) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $segment)) {
                throw new \InvalidArgumentException("Invalid column name [{$column}]: each dotted segment must be an identifier.");
            }
        }

        if (in_array($segments[0], $tables, true)) {
            return ['relation' => null, 'column' => $column]; // a table of the query (ER-57)
        }

        if ($model !== null && $this->isRelationPath($model, $segments, $column)) {
            return ['relation' => implode('.', $segments), 'column' => $leaf];
        }

        if (count($segments) === 1) {
            return ['relation' => null, 'column' => $column]; // table.column (v2.0 behaviour)
        }

        throw $model === null
            ? new \InvalidArgumentException("Relation search [{$column}] needs an Eloquent model: use Model::search() instead of a Query Builder.")
            : self::notARelation($column, $model, $segments[0]);
    }

    /**
     * The names the query's FROM table and joined tables go by: each table's name (without a
     * schema) and its alias. toBase() so a global scope's join counts; a joinSub()/fromSub() has
     * no plain name and adds none.
     *
     * @return string[]
     */
    private function queryTableNames(): array
    {
        $base  = $this->query instanceof EloquentBuilder ? $this->query->toBase() : $this->query;
        $names = [];

        foreach ([$base->from, ...array_map(fn ($join) => $join->table, $base->joins ?? [])] as $from) {
            foreach (\Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::fromTable($from) ?? [] as $name) {
                if ($name !== null) {
                    $names[] = self::lastSegment($name);
                }
            }
        }

        return $names;
    }

    /** "users.name" → "name", "public.users" → "users", "name" → "name". */
    private static function lastSegment(string $name): string
    {
        return substr((string) strrchr('.' . $name, '.'), 1);
    }

    /**
     * True when every segment is a relation method, following the chain model by model. A first
     * segment that names no method is not a relation (a two-segment name is then table.column).
     * Any other segment is called only when isReachableRelation() allows it, and must return a
     * Relation; anything else throws, naming that segment, without calling it (ruling ER-50):
     * searchIn() can carry request input, and `unguard.body` or `save.body` would otherwise run
     * that method.
     */
    protected function isRelationPath(Model $model, array $segments, string $column): bool
    {
        $current = $model;
        // Declared config only: auto-detected columns never hold a dotted path, and detecting them
        // would run schema SQL before the rejection.
        $declared = fn (): bool => method_exists($model, 'hasDeclaredSearchableColumns')
            && $model->hasDeclaredSearchableColumns()
            && in_array($column, $model->getSearchableColumns(), true);

        foreach ($segments as $i => $segment) {
            if ($i === 0 && !method_exists($current, $segment)) {
                return false;
            }

            $relation = method_exists($current, $segment) && $this->isReachableRelation($current, $segment, $declared)
                ? $current->{$segment}()
                : null;

            if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                throw self::notARelation($column, $current, $segment);
            }

            $current = $relation->getRelated();
        }

        return true;
    }

    private static function notARelation(string $column, Model $model, string $segment): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            "Invalid column name [{$column}]: " . get_class($model) . "::{$segment} is not a relation: "
            . "declare a Relation return type or list the path in \$searchable['columns']."
        );
    }

    /**
     * Whether $segment may be called as a relation: a public, non-static method without required
     * parameters that neither the framework nor this package declares (an override of one counts
     * as theirs: `save`, `delete`), typed to return a Relation or on a path the model declares in
     * $searchable['columns'], which is the developer's own config.
     *
     * @param Closure(): bool $declared
     */
    private function isReachableRelation(Model $model, string $segment, Closure $declared): bool
    {
        $method = new \ReflectionMethod($model, $segment);

        if (!$method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        // A trait's method reports the class that uses it as its declaring class, so the owners
        // are every parent and every trait, each asked whether it has the method.
        foreach ([...class_parents($model), ...class_uses_recursive($model)] as $owner) {
            if (method_exists($owner, $segment)
                && (str_starts_with($owner, 'Illuminate\\')
                    || str_starts_with((string) (new \ReflectionClass($owner))->getFileName(), __DIR__ . DIRECTORY_SEPARATOR))) {
                return false;
            }
        }

        $type = $method->getReturnType();

        return ($type instanceof \ReflectionNamedType && !$type->isBuiltin()
                && is_a($type->getName(), \Illuminate\Database\Eloquent\Relations\Relation::class, true))
            || $declared();
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
     * Under a join, every column of the FROM table => the FROM table's name, or its alias, and a
     * dot: the prefix a searched column is written with in SQL, so a joined table with a column
     * of the same name cannot make it ambiguous. A column on both tables is therefore the FROM
     * table's (the model's own); a caller who means the joined one writes it dotted, and a dotted
     * column is never in this map. Any other column (a translations join's "title") stays bare:
     * it may live on the joined table. [] with no join — the SQL is then unchanged — or when FROM
     * is not a plain table (fromSub()). Only SQL is qualified: scoring, highlighting, _score,
     * facet keys and events keep the logical name. toBase() so a global scope's join counts.
     * A schema-qualified FROM (public.users) is qualified by its table alone: a 3-part column
     * would take the table prefix on the schema. A qualifier the column check would reject
     * (user-profiles, 2fa_users) leaves the columns bare, as before.
     *
     * @return array<string, string> column => "table." or "alias."
     */
    protected function qualifiedColumnMap(Builder|EloquentBuilder $query): array
    {
        $base = $query instanceof EloquentBuilder ? $query->toBase() : $query;
        $from = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::fromTable($base->from);

        if (empty($base->joins) || $from === null) {
            return [];
        }

        [$table, $alias] = $from;
        $qualifier       = $alias ?? self::lastSegment($table);

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $qualifier)) {
            return [];
        }

        return array_fill_keys(SearchableColumns::onTable($base->getConnection(), $table), $qualifier . '.');
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
            $this->searchTerm       = Utf8::clean($query);
            $this->invalidBytesOnly = trim($this->searchTerm) === '' && trim($query) !== '';
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
     * A documented no-op, kept for API compatibility (and used by the `ecommerce`/`exact`
     * presets).
     *
     * Every pattern-based algorithm already searches for substrings — the LIKE patterns the
     * drivers build are `%term%` — so `search('joh')` matches "john", "johnny" and "johanna"
     * with or without this call. There is nothing to switch on, which is also why this one is
     * not deprecated: no caller has to stop doing anything.
     */
    public function partialMatch(): self
    {
        $this->partialMatchEnabled = true;
        return $this;
    }

    /**
     * @deprecated since 2.1.0 — never implemented. The value was only ever read into the cache
     *             key, never into a pattern or a predicate; the real minimum-length controls are
     *             the `min_search_length` config key (whole term) and
     *             `typo_tolerance.min_word_length` (per word). This method is a no-op and will
     *             be removed in v3.0.0.
     */
    public function minMatchLength(int $length): self
    {
        trigger_error(
            'SearchBuilder::minMatchLength() is deprecated since 2.1.0 and does nothing — use the min_search_length config key. It will be removed in v3.0.0.',
            E_USER_DEPRECATED
        );

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
            $this->stopWords = config()->has("fuzzy-search.stop_words.{$stopWords}")
                ? \Ashiqfardus\LaravelFuzzySearch\Support\StopWords::forLocale($stopWords)
                : ($this->defaultStopWords[$stopWords] ?? []);
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
     * @deprecated since 2.1.0 — never implemented. The value was only ever read into the cache
     *             key: no stop-word list, stemmer or collation was ever selected from it. Pass
     *             the locale where it is actually read — `ignoreStopWords('de')` for a
     *             query-time stop-word list, `$searchable['locale']` for a model's index
     *             pipeline. This method is a no-op and will be removed in v3.0.0.
     */
    public function locale(string $locale): self
    {
        trigger_error(
            "SearchBuilder::locale() is deprecated since 2.1.0 and does nothing — use ignoreStopWords('de') for a query-time stop-word list or \$searchable['locale'] for a model's index pipeline. It will be removed in v3.0.0.",
            E_USER_DEPRECATED
        );

        $this->locale = $locale;
        return $this;
    }

    /**
     * Enable accent-insensitive search: the term's folded form is searched beside it (as the global
     * unicode.accent_insensitive default does), and on PostgreSQL with use_native_functions
     * unaccent(column) ILIKE unaccent(term) is OR'd beside the algorithm, which needs the unaccent
     * extension (CREATE EXTENSION unaccent). The model's $searchable['accent_insensitive'] and a
     * preset's opt in the same way; the global key alone never runs unaccent().
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
            'algorithm' => $this->extendedQuery !== null ? 'extended' : ($this->algorithm ?? config('fuzzy-search.default_algorithm', 'fuzzy')),
            'searchable_columns' => $this->searchableColumns,
            'column_weights' => $this->columnWeights,
            'column_targets' => $this->resolveColumnTargets(),
            'typo_tolerance' => $this->typoTolerance,
            'tokenize' => $this->tokenizeSearch,
            'token_match_mode' => $this->tokenMatchMode,
            'stop_words' => $this->stopWords,
            'synonyms' => $this->synonyms,
            'accent_insensitive' => $this->foldsAccents(),
            'unicode_normalize' => $this->unicodeNormalizeEnabled,
            'prefix_boost' => $this->prefixBoostMultiplier,
            'partial_match' => $this->partialMatchEnabled,
            'use_cache' => $this->cacheSeconds() > 0,
            'cache_ttl' => $this->cacheSeconds(), // seconds; 0 when get() does not cache
            'use_index' => $this->useSearchIndex,
            'index_ignored' => $this->extendedQuery !== null && $this->useSearchIndex,
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
     *   - string     explicit model class (enables BM25 on DB::table() too; that builder's
     *                wheres and joins still apply, so it must select from the model's table,
     *                unaliased, and a narrowed select() must include the primary key, which
     *                the ranked rows are matched back by)
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
     * Override suggest()'s source: 'auto' (default) uses the BM25 dictionary when the model
     * is indexed and falls back to the table scan otherwise; 'index' forces the dictionary
     * (empty array if it cannot serve the request); 'table' forces the LIKE table scan.
     */
    public function suggestFrom(string $source): self
    {
        if (!in_array($source, ['auto', 'index', 'table'], true)) {
            throw new \InvalidArgumentException("suggestFrom() expects 'auto', 'index' or 'table', got '{$source}'.");
        }
        $this->suggestSource = $source;
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
     * Cache get()'s results (first() and simplePaginate() go through it) for $minutes, or for the
     * cache.ttl config (seconds) when null. 0 turns caching off for this query, also when
     * cache.enabled caches every search. $key is used as given; a generated key starts with
     * cache.prefix. The store is cache.driver ('default' is the app's default store).
     */
    public function cache(?int $minutes = null, ?string $key = null): self
    {
        $this->cacheCalled  = true;
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
        SearchableColumns::validate([$column]);
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
            $this->ignoreStopWords(\Ashiqfardus\LaravelFuzzySearch\Support\StopWords::forLocale($locale));
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
        $this->offset = max(0, $offset); // a negative offset sliced from the end: the last rows
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
        $this->offset = (max(1, $page) - 1) * $perPage; // page 0 or below is page 1, as for ?page
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

    /**
     * ASCII whitespace, byte by byte — what `\s` means on Linux, on every platform (the Lexer's
     * rule too). A plain `\s` follows LC_CTYPE: under a UTF-8 locale on macOS/BSD byte 0xA0 is a
     * space, and it is inside à (C3 A0), ঠ (E0 A6 A0) and 丠 (E4 B8 A0). `\s` with /u is Unicode
     * whitespace and would change what Linux splits.
     */
    private const WHITESPACE = '/[ \t\n\r\x0B\f]+/';


    /** The alias the ordered index walk reads the key under — see orderedIndexedKeys(). */
    private const WALK_KEY = 'fuzzy_walk_key';

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
     * True when $term (already cleaned and trimmed) is non-empty but shorter than
     * min_search_length characters — never bytes, so a 2-character Bengali term is 2.
     * The one length rule behind every search API: this builder, FederatedSearch,
     * FuzzySearch::on() and the Scout engine.
     *
     * @internal
     */
    public static function belowMinSearchLength(string $term): bool
    {
        return $term !== '' && mb_strlen($term, 'UTF-8') < (int) config('fuzzy-search.min_search_length', 1);
    }

    /**
     * The search matches nothing, on every terminal and without dispatching an event: a plain
     * term below min_search_length, one made only of invalid UTF-8, or one made only of stop
     * words (the index path has no term left either), or a search with no column to run on (see
     * hasNoSearchableColumn()). Not '', which throws or, with allow_empty_search, lists every
     * row. An extended()/searchBoolean() query is a query, not a term, and is never measured; with
     * no column it throws SearchableColumnsNotFoundException.
     */
    protected function matchesNothing(): bool
    {
        return $this->invalidBytesOnly
            || ($this->extendedQuery === null && $this->searchTerm !== ''
                && (self::belowMinSearchLength($this->searchTerm) || $this->processSearchTerm($this->searchTerm) === ''
                    || $this->hasNoSearchableColumn()));
    }

    /**
     * The empty-term guard every terminal applies after matchesNothing() (ruling ER-46): '' (or
     * whitespace, which search() trims to '') throws EmptySearchTermException unless
     * allow_empty_search is on, and then every terminal lists every row. Deferred from search()
     * so extended()/searchBoolean() can still supply the query: `search('')->extended('=John')`.
     *
     * @throws EmptySearchTermException
     */
    protected function guardEmptyTerm(): void
    {
        if ($this->searchTerm === '' && $this->extendedQuery === null
            && !config('fuzzy-search.allow_empty_search', false)) {
            throw new EmptySearchTermException();
        }
    }

    /**
     * No column to search: searchIn() gave none — Model::search() passes the declared columns,
     * else the auto-detected ones — and, on the index path, the model has nothing to index
     * (IndexManager::indexesModel()). A model whose table cannot be listed (a $table typo, not
     * migrated, the connection down) is not such a model: detection could not look, so the
     * search runs and its error surfaces instead of an empty result.
     */
    protected function hasNoSearchableColumn(): bool
    {
        if ($this->searchableColumns !== []) {
            return false;
        }

        $indexClass = $this->useSearchIndex ? $this->resolveIndexModelClass() : null;
        $model      = $indexClass !== null
            ? new $indexClass()
            : ($this->query instanceof EloquentBuilder ? $this->query->getModel() : null);

        if ($model === null) {
            return true; // a plain query builder without searchIn()
        }

        if (SearchableColumns::onTable($model->getConnection(), $model->getTable()) === []) {
            return false;
        }

        return $indexClass === null || !\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::indexesModel($model);
    }

    /**
     * Execute search and get results
     */
    public function get(): Collection
    {
        // Before the cache: the key cannot tell an invalid-bytes term from ''.
        // Covers first() and simplePaginate(), which call get().
        if ($this->matchesNothing()) {
            return collect();
        }

        $this->guardEmptyTerm();

        // Check cache
        $seconds  = $this->cacheSeconds();
        $cacheKey = $seconds > 0 ? ($this->cacheKey ?? $this->generateCacheKey()) : null;

        if ($cacheKey === null) {
            return $this->executeWithFallback();
        }

        $store  = config('fuzzy-search.cache.driver', 'default');
        $store  = Cache::store(in_array($store, [null, '', 'default'], true) ? null : $store);
        $cached = $store->get($cacheKey);

        // Anything else under the key (an entry written before 2.1) is a miss.
        if (is_array($cached) && isset($cached['rows'])) {
            return $this->fromCachePayload($cached);
        }

        $this->decoration = [];

        try {
            $results    = $this->executeWithFallback();
            $decoration = $this->decoration;
        } finally {
            $this->decoration = null;
        }

        $store->put($cacheKey, $this->cachePayload($results, $decoration), $seconds);

        return $this->decorate($results, $decoration);
    }

    /**
     * What get() caches (ruling ER-83): scalars and arrays only, so it unserialises under Laravel
     * 13's cache.serializable_classes = false — each model's key and scores, or a plain query
     * builder's rows as arrays — plus the columns searched and how the rows are decorated. No row
     * visibility, highlighting or relation is stored: those belong to the request that reads it.
     */
    private function cachePayload(Collection $results, array $decoration): array
    {
        $scores = array_flip(['_score', '_column_scores', '_raw_score']);

        return [
            'models'     => $results->first() instanceof Model,
            'columns'    => $this->columnWeights,
            'decoration' => $decoration,
            'rows'       => $results->map(fn ($row) => $row instanceof Model
                ? ['key' => $row->getKey(), 'scores' => array_intersect_key($row->getAttributes(), $scores)]
                : (array) $row)->all(),
        ];
    }

    /**
     * A cached result for this request (rulings ER-58, ER-83): a plain query builder's rows as
     * stdClass again; models re-read by key, in the cached order, through the current query — its
     * global scopes, and its eager loads with their constraint closures plus the relation paths
     * searchIn() reads — so a row deleted since is dropped and retrieved listeners run for this
     * viewer. Then highlighted and debugged as a live get() would (decorate()). Costs the keyed
     * read and its eager loads on a hit.
     */
    private function fromCachePayload(array $payload): Collection
    {
        // The columns an extended search detected on the miss (compileExtendedQuery()).
        if ($this->searchableColumns === [] && $payload['columns'] !== []) {
            $this->searchIn($payload['columns']);
        }

        if (!$payload['models']) {
            $rows = array_map(fn (array $row) => (object) $row, $payload['rows']);

            return $this->decorate($this->query instanceof EloquentBuilder ? $this->query->getModel()->newCollection($rows) : collect($rows), $payload['decoration']);
        }

        // Models come from the builder's own model, or from useInvertedIndex(Model::class) on a plain query builder.
        $query = $this->modelBaseQuery((string) $this->resolveIndexModelClass());
        if ($this->query instanceof EloquentBuilder && $this->relationPaths() !== []) {
            $query->with($this->relationPaths());
        }

        $found = \Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::models($query, array_values(array_unique(array_column($payload['rows'], 'key'))))
            ->keyBy(fn (Model $model) => $model->getKey());
        $rows  = [];
        $seen  = [];

        foreach ($payload['rows'] as $row) {
            if (($model = $found[$row['key']] ?? null) === null) {
                continue;
            }
            // A join can repeat a model; each row keeps its own scores.
            $model = isset($seen[$row['key']]) ? clone $model : $model;
            $seen[$row['key']] = true;

            foreach ($row['scores'] as $name => $value) {
                $model->{$name} = $value;
            }
            $rows[] = $model;
        }

        return $this->decorate($query->getModel()->newCollection($rows), $payload['decoration']);
    }

    /**
     * Highlighting and debug output for get()'s cached and fresh rows alike, as the search asked for
     * them (see $decoration): computed for the current request, each row's own visibility included.
     */
    private function decorate(Collection $rows, array $decoration): Collection
    {
        if (array_key_exists('highlight', $decoration)) {
            $rows = $this->applyHighlighting($rows, $decoration['highlight']);
        }

        return array_key_exists('debug', $decoration) ? $this->addDebugInfo($rows, $decoration['debug']) : $rows;
    }

    /**
     * How long get() caches, in seconds (0 = it does not): cache() decides when called —
     * cache($minutes), or the cache.ttl config (seconds) for cache(null), and cache(0) is off —
     * otherwise cache.enabled caches every search for cache.ttl seconds.
     */
    protected function cacheSeconds(): int
    {
        if (!$this->cacheCalled && !config('fuzzy-search.cache.enabled', false)) {
            return 0;
        }

        return max(0, $this->cacheMinutes === null ? (int) config('fuzzy-search.cache.ttl', 3600) : $this->cacheMinutes * 60);
    }

    /**
     * Run executeSearch(), retrying with each fallback() algorithm while it matches nothing: the
     * matches before the page cut decide, as total() does for paginate(), so a simplePaginate() or
     * skip() page past the primary's matches is empty rather than another algorithm's rows.
     */
    protected function executeWithFallback(): Collection
    {
        return $this->withFallback(
            fn () => $this->executeSearch(),
            fn (Collection $results) => $results->isEmpty() && !$this->matched
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
            return $this->onQueryClone($attempt);
        }

        // Captured before the first attempt (which may switch them off itself) and restored in
        // finally, so a throwing retry cannot leave the builder on a fallback algorithm.
        $algorithm = $this->algorithm;
        $useIndex  = $this->useSearchIndex;

        try {
            $result = $this->onQueryClone($attempt);

            foreach ($this->fallbackAlgorithms as $fallback) {
                if (!$isEmpty($result)) {
                    break;
                }

                $this->algorithm      = $fallback;
                $this->useSearchIndex = false;

                $result = $this->onQueryClone($attempt);
            }

            return $result;
        } finally {
            $this->algorithm      = $algorithm;
            $this->useSearchIndex = $useIndex;
        }
    }

    /**
     * Run one terminal operation against a clone of the caller's query.
     *
     * buildQuery()/compileExtendedQuery() append the search's WHEREs and ORDER BYs to
     * $this->query. Without this, they landed on the very builder the caller keeps holding,
     * so `toSql()` then `get()` executed the search twice over, a second `get()` four times,
     * and `suggest()` — which clones the base query — ran inside the previous search.
     *
     * Everything a terminal call touches ($this->query, and the clones taken from it by
     * paginateRanked(), getFacets() and indexedBaseQuery()) therefore sees the prepared
     * query, while the caller's own builder is restored untouched — so constraints added
     * after a terminal call still take effect on the next one.
     *
     * It is re-entrant: the outermost wrap records the caller's pristine query, and a nested
     * wrap (a BM25 path falling back to LIKE, or a FuzzySearchExecuted listener calling
     * toSql() on the same builder) clones that pristine query rather than the prepared one it
     * is running inside. Both are restored in finally, so an inner search cannot leak into the
     * outer one and the outer one resumes on its own prepared query.
     *
     * @template T
     * @param  Closure(): T $work
     * @return T
     */
    protected function onQueryClone(Closure $work): mixed
    {
        $outer            = $this->query;
        $previousPristine = $this->pristineQuery;

        $this->pristineQuery ??= $outer;
        $this->query           = clone $this->pristineQuery;

        try {
            return $work();
        } finally {
            $this->query         = $outer;
            $this->pristineQuery = $previousPristine;
        }
    }

    /**
     * One place to build FuzzySearchExecuted so every path reports the same fields.
     * $candidateCount is what the DB matched before the page/limit cut; $resultCount is what
     * the caller receives.
     */
    protected function dispatchExecuted(string $algorithm, string $path, int $candidateCount, int $resultCount, float $startedAt, ?string $term = null): void
    {
        $event = new \Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted(
            searchTerm:     $term ?? $this->searchTerm,
            columns:        $this->searchableColumns,
            algorithm:      $algorithm,
            candidateCount: $candidateCount,
            latencyMs:      round((microtime(true) - $startedAt) * 1000, 2),
            resultCount:    min($resultCount, $this->reportedResultCap ?? PHP_INT_MAX),
            path:           $path,
            modelClass:     $this->query instanceof EloquentBuilder ? $this->query->getModel()::class : null,
        );

        $this->lastExecution = $event;

        event($event);
    }

    /**
     * The event built by the most recent get()/paginate() on this builder (null before any
     * run). withFallback() re-runs the same builder for each fallback algorithm; the last
     * attempt wins, which is the one whose results were actually returned. count() never
     * dispatches FuzzySearchExecuted, so it never touches this.
     *
     * It also stays null (or stale from an earlier run on the same instance) whenever a run
     * never reaches dispatchExecuted(): a term shorter than min_search_length or made only of
     * invalid UTF-8 returns from get()/paginate() before any query (see matchesNothing()), and
     * cache() serves get() from the cache without executing.
     * FuzzySearchCollection then reports meta.algorithm/meta.latency_ms as null.
     */
    public function lastExecution(): ?\Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted
    {
        return $this->lastExecution;
    }

    /**
     * Execute the search
     */
    protected function executeSearch(): Collection
    {
        // Only get() reaches this, after matchesNothing() has applied min_search_length. The
        // length cap is applied where the term becomes a query: buildQuery() and indexedQueryTerms().

        // Extended-search path (Fuse-style operators)
        if ($this->extendedQuery !== null) {
            return $this->executeExtendedSearch();
        }

        // BM25 fast path via inverted index
        if ($this->useSearchIndex && $this->searchTerm !== '') {
            return $this->executeIndexedSearch();
        }

        $this->buildQuery();
        $startTime = microtime(true);

        $maxCandidates = config('fuzzy-search.max_candidates', 1000);

        // Fetch all candidates up to the ceiling — do NOT apply limit/offset yet
        $candidates    = $this->query->limit($maxCandidates)->get();
        $this->matched = $candidates->isNotEmpty();

        // Rescore ALL candidates before slicing
        if ($this->withRelevance && $this->searchTerm !== '') {
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

        $this->dispatchExecuted($this->algorithm ?? config('fuzzy-search.default_algorithm', 'fuzzy'), 'like', $candidates->count(), $results->count(), $startTime);

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

        $base = $this->indexedBaseQuery($modelClass);

        if (empty($ranked) || $this->offset >= count($ranked)) {
            // Fall through instead of returning early (the shape paginateIndexed() already
            // uses): a search that matched nothing must still dispatch FuzzySearchExecuted,
            // or zero-result analytics never sees a miss on the index path. A page that starts
            // past every match is empty without a walk (ruling ER-82); only a fallback() asks
            // whether the query sees any match at all.
            $sorted        = collect();
            $this->matched = !empty($ranked) && ($this->fallbackAlgorithms === []
                || \Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::keys($base, array_keys($ranked), 1) !== []);
        } else {
            // Walk the ranking against the constrained query until the requested window is
            // full. Constraints (filters, wheres, scopes) are applied before the cut, so a
            // selective filter fills its page from lower-ranked matches instead of coming
            // back short or empty.
            $window        = $this->indexedWindow($modelClass, $base, $ranked, $this->offset + $this->limit);
            $this->matched = $window->isNotEmpty();
            $sorted        = $window->slice($this->offset, $this->limit)->values();
        }

        if ($this->highlightTagOpen) {
            $sorted = $this->applyHighlighting($sorted, array_keys($this->indexedTermWeights));
        }

        if ($this->debugMode) {
            $sorted = $this->addDebugInfo($sorted);
        }

        $this->dispatchExecuted('bm25', 'bm25', count($ranked), $sorted->count(), $startedAt);

        return $sorted;
    }

    /**
     * The Eloquent query BM25 candidates are checked against: modelBaseQuery() plus any
     * filter()/filterIn() constraints. Always a fresh clone.
     */
    protected function indexedBaseQuery(string $modelClass): EloquentBuilder
    {
        $base = $this->modelBaseQuery($modelClass);

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
        $this->capSearchTerm(); // see capSearchTerm(): the index path's one call

        $modelClass = $this->resolveIndexModelClass();
        $override   = $this->stopWordsOverridden ? $this->stopWords : null;
        $terms      = $indexManager->processTerms($this->searchTerm, $override, $modelClass);
        $weights    = array_fill_keys($terms, 1.0);

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
                    foreach ($indexManager->processTerms($synonym, $override, $modelClass) as $term) {
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
                $modelClass,
            );
        }

        if ($this->asYouType) {
            // The prefix source is the last RAW word the user typed, processed on its own:
            // $terms is de-duplicated (a repeated last word would vanish) and a trailing
            // stop word must not silently prefix-expand the word before it.
            $rawWords  = preg_split('/[^\p{L}\p{M}\p{N}]+/u', trim($this->searchTerm), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $lastTerms = $rawWords === [] ? [] : $indexManager->processTerms((string) end($rawWords), $override, $modelClass);

            if ($lastTerms !== []) {
                $prefixed = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander::class)->prefix(
                    (string) end($lastTerms),
                    (int) config('fuzzy-search.bm25.prefix.max_expansions', 10),
                    $modelClass,
                    visibleOnly: false, // matching keeps hidden columns (ER-66); suggest() leaves them out
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
     * The builder's own query (including wheres the caller applied before wrapping it) as an
     * Eloquent query. A plain query builder given useInvertedIndex(Model::class) runs inside the
     * model's query, so its wheres and joins apply alongside the model's global scopes instead
     * of being replaced by them. Always a fresh clone.
     */
    protected function modelBaseQuery(string $modelClass): EloquentBuilder
    {
        return $this->query instanceof EloquentBuilder
            ? clone $this->query
            : $modelClass::query()->setQuery(clone $this->query);
    }

    /**
     * True when the base query carries any WHERE or JOIN (filters, caller wheres and joins,
     * global scopes), i.e. the BM25 ranking cannot be used as-is. A join can hide rows as
     * surely as a where. having(), unions and a from-subquery are not detected.
     */
    protected function hasIndexedConstraints(Builder|EloquentBuilder $base): bool
    {
        $query = $base instanceof EloquentBuilder ? $base->toBase() : $base;

        return !empty($query->wheres) || !empty($query->joins);
    }

    /**
     * True when the base query narrows the model in a way the dictionary cannot see — the
     * caller's where()s and joins, forwarded scopes, global scopes — so suggest() and
     * didYouMean() must not offer model-wide terms. The SoftDeletes scope is not counted: the
     * index honours it (deleting a row removes its postings, and IndexModelJob loads through the
     * scope), though with indexing.async only once the queued job has run, and never for a
     * query-builder delete(), which fires no model events. onlyTrashed() adds a real where and
     * still counts; withTrashed() does not, and the dictionary then offers non-trashed terms
     * only, which is safe. The BM25 search path keeps hasIndexedConstraints(), which also covers
     * a trashed row the queue has not removed yet.
     */
    protected function hasSuggestionConstraints(Builder|EloquentBuilder $base): bool
    {
        return $this->hasIndexedConstraints($base instanceof EloquentBuilder
            ? (clone $base)->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            : $base);
    }

    /**
     * Set _raw_score / _score on the ranked window of models and return it best first. The raw
     * score is the BM25 score through the model's getSearchScore() override, if it has one (see
     * searchScore()), which can reorder the window. _score is normalised against the window's best
     * row: the ranking walk always starts at rank 1 of what the query can see, so it is the same on
     * every page and scores stay comparable across pages. Not the first entry of $ranked: a row
     * the query hides, such as another tenant's, would set the scale and reveal what it contains.
     *
     * $rank false (an explicit orderBy()) keeps the window's own order.
     *
     * @param array<int|string, float> $ranked model_id => score, best first
     */
    protected function attachBm25Scores(Collection $models, array $ranked, bool $rank = true): Collection
    {
        $scores = $models->mapWithKeys(fn ($item, $i) => [$i => $this->searchScore($item, (float) ($ranked[$item->getKey()] ?? 0))]);
        $top    = (float) ($scores->max() ?? 0);

        // arsort() is stable: rows the hook left tied keep their BM25 rank.
        return ($rank ? $scores->sortDesc() : $scores)->map(function (float $raw, $i) use ($models, $top) {
            $item             = $models[$i];
            $item->_raw_score = round($raw, 6);
            $item->_score     = $top > 0 ? round($item->_raw_score / $top, 6) : $item->_raw_score;
            return $item;
        })->values();
    }

    /**
     * The index path's first $end matches the constrained $base accepts, scored: in rank order,
     * or, with orderBy(), in that order (see orderedIndexedKeys()).
     *
     * @param array<int|string, float> $ranked model_id => score, best first
     */
    private function indexedWindow(string $modelClass, EloquentBuilder $base, array $ranked, int $end): Collection
    {
        if ($this->sortBy === []) {
            $models = \Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::models($base, array_keys($ranked), $this->bm25Window($modelClass, $end));

            return $this->attachBm25Scores($models, $ranked);
        }

        $models = \Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::models($base, $this->orderedIndexedKeys($modelClass, $base, $ranked, $end));

        return $this->attachBm25Scores($models, $ranked, false);
    }

    /**
     * With orderBy(), the keys of the first $needed matches in that order: $base's rows sorted by
     * applyExplicitOrder()'s columns and then the key, keeping those the ranking matched. The query
     * reads only ranked documents (ruling ER-82): up to one bm25.candidate_chunk of matches it is
     * restricted to their ids; past that, a subquery on the postings restricts it
     * (Bm25Scorer::whereRanked()), so no id list can pass SQL Server's 2,100-parameter limit. That
     * subquery needs the index on the model's connection; on another one the ordered window is the
     * top max_candidates ranked ids (at least one chunk), listed, and deeper matches are not served.
     * It reads a page of 1,000 rows at a time: lazy(), never one buffered result (pdo_mysql and
     * pdo_pgsql fetch a whole result set before its first row). The key ends the order, so it is
     * total and no row moves between pages. It stops once $needed matches are found: one ordered
     * query per 1,000 matches it passes, never a row that did not match.
     *
     * The caller's select list stays — an order may name its alias (withCount()'s posts_count, a
     * selectRaw() column) — and the key is read through an alias of its own.
     *
     * @param  array<int|string, float> $ranked model_id => score
     * @return array<int|string>
     */
    private function orderedIndexedKeys(string $modelClass, EloquentBuilder $base, array $ranked, int $needed): array
    {
        $model = $base->getModel();
        $ids   = array_keys($ranked);
        $query = clone $base;
        $chunk = max(1, (int) config('fuzzy-search.bm25.candidate_chunk', 200));

        // Global scopes, as RankedCandidates adds its ids, so a caller's where(A)->orWhere(B) is grouped first.
        if (count($ids) > $chunk && $base->getQuery()->getConnection() === DB::connection()) {
            $query->withGlobalScope(self::class, fn (EloquentBuilder $q) => app(\Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer::class)
                ->whereRanked($q->getQuery(), $model->getQualifiedKeyName(), $this->indexedTermWeights, $modelClass, $this->columnWeights));
        } else {
            $ids = array_slice($ids, 0, max($chunk, (int) config('fuzzy-search.max_candidates', 1000)));
            $query->withGlobalScope(self::class, fn (EloquentBuilder $q) => $q->whereKey($ids));
        }

        $this->applyExplicitOrder($query, true);

        $walk = $query->toBase();
        $walk->columns ??= ['*'];
        $walk->addSelect($model->getQualifiedKeyName() . ' as ' . self::WALK_KEY);
        $keys = [];

        foreach ($walk->lazy(1000) as $row) {
            $key = $row->{self::WALK_KEY};

            if (isset($ranked[$key])) {
                $keys[$key] = true; // a join may repeat a model

                if (count($keys) >= $needed) {
                    break;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * How many ranked rows the index path hydrates for a page that ends at $end: $end, or
     * max_candidates when the model overrides getSearchScore(), whose scores can reorder the
     * ranking, so the page is cut from the same window the LIKE path rescores.
     */
    private function bm25Window(string $modelClass, int $end): int
    {
        return self::hasSearchScoreHook($modelClass) ? max($end, (int) config('fuzzy-search.max_candidates', 1000)) : $end;
    }

    /**
     * $score through $item's getSearchScore() — Searchable's per-model hook, applied once per row
     * to the score PHP computed (the LIKE and extended rescoring's column sum, the BM25 raw score)
     * before normalisation, so the ranking follows it. The trait's own method returns the score
     * unchanged, so only a model that overrides it is called.
     */
    private function searchScore(mixed $item, float $score): float
    {
        return $item instanceof Model && self::hasSearchScoreHook($item::class) ? (float) $item->getSearchScore($score) : $score;
    }

    /** @var array<class-string, bool> model class => it uses Searchable and overrides getSearchScore() */
    private static array $searchScoreHooks = [];

    private static function hasSearchScoreHook(string $modelClass): bool
    {
        return self::$searchScoreHooks[$modelClass] ??= in_array(\Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::class, class_uses_recursive($modelClass), true)
            && (new \ReflectionMethod($modelClass, 'getSearchScore'))->getFileName()
                !== (new \ReflectionClass(\Ashiqfardus\LaravelFuzzySearch\Traits\Searchable::class))->getFileName();
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
        $ast    = $this->withTermVariants((new \Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser())->parse($tokens));

        $this->extendedLeafTerms = array_values(array_unique($this->positiveLeafTerms($ast)));

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

        $typoDistance = config('fuzzy-search.typo_tolerance.enabled', true) ? $this->typoTolerance : 0;

        (new \Ashiqfardus\LaravelFuzzySearch\Query\AstCompiler($dbDriver, $typoDistance, $this->options, $this->qualifiedColumnMap($this->query)))
            ->compile($ast, $compileTarget, $direct, $relations);

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

        $this->applyExplicitOrder($this->query);

        return $columns;
    }

    /**
     * What the extended path scores rows against: its leaf terms, each on its own (joined into one
     * string, a long OR query scored every row against all of it — see similarity()), or null on
     * the LIKE path (and for a query with no positive leaf, e.g. a pure NOT) so that
     * calculateRelevanceScores() falls back to the search term as before.
     *
     * @return string[]|null
     */
    private function extendedScoringTerms(): ?array
    {
        return $this->extendedLeafTerms === [] ? null : $this->extendedLeafTerms;
    }

    /**
     * The terms an extended query actually asks to match: every leaf term outside a NOT
     * subtree, with field scopes unwrapped (name:~jonh contributes "jonh"). Highlighting and
     * scoring use these — the raw query string is a query, not a needle.
     *
     * @return string[]
     */
    private function positiveLeafTerms(AstNode $node): array
    {
        if ($node instanceof NotNode) {
            return [];
        }

        if ($node instanceof FieldTerm) {
            return $this->positiveLeafTerms($node->term);
        }

        if ($node instanceof AndNode || $node instanceof OrNode) {
            return array_merge([], ...array_map(fn (AstNode $child) => $this->positiveLeafTerms($child), $node->children));
        }

        return property_exists($node, 'term') && $node->term !== '' ? [$node->term] : [];
    }

    /**
     * Execute search using Fuse-style extended/boolean syntax.
     * Routes through Lexer → ExtendedQueryParser → AstCompiler.
     */
    protected function executeExtendedSearch(): Collection
    {
        $startedAt = microtime(true);

        $this->compileExtendedQuery();

        $maxCandidates = config('fuzzy-search.max_candidates', 1000);
        $candidates = $this->query->limit($maxCandidates)->get();
        $this->matched = $candidates->isNotEmpty();

        if ($this->withRelevance) {
            $candidates = $this->calculateRelevanceScores($candidates, $this->extendedScoringTerms());
        }

        $results = $candidates->slice($this->offset, $this->limit)->values();

        if ($this->highlightTagOpen) {
            $results = $this->applyHighlighting($results, $this->extendedLeafTerms ?: null);
        }

        if ($this->debugMode) {
            $results = $this->addDebugInfo($results);
        }

        $this->dispatchExecuted('extended', 'extended', $candidates->count(), $results->count(), $startedAt, $this->extendedQuery);

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
     *
     * $perPage is clamped to max_candidates (default 1000) on every path — see paginateOnce().
     */
    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        if ($this->matchesNothing()) {
            $perPage = $this->clampPerPage($perPage);

            return new \Illuminate\Pagination\LengthAwarePaginator(
                [], 0, $perPage, $this->resolvePage($page, $pageName, $perPage),
                ['path' => request()->url(), 'pageName' => $pageName]
            );
        }

        $this->guardEmptyTerm();

        return $this->withFallback(
            fn () => $this->paginateOnce($perPage, $pageName, $page),
            fn (\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator) => $paginator->total() === 0
        );
    }

    /**
     * One pagination attempt with the current algorithm (see withFallback()).
     *
     * perPage is clamped to `max_candidates` on both paths. The BM25 path used to clamp at a
     * hard-coded 100 (a page-size DoS guard) and the LIKE path at nothing, so the same
     * paginate(200) call returned a different page size depending on useInvertedIndex(). One
     * rule now: a page can never exceed the candidate window the ranking is built from — which
     * is also the most rows either path can rank.
     */
    protected function paginateOnce(int $perPage, string $pageName, ?int $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $perPage = $this->clampPerPage($perPage);

        // BM25 fast path via inverted index — never for extended queries, which run on the
        // LIKE path (mirrors executeSearch(); see getDebugInfo()['index_ignored']).
        if ($this->extendedQuery === null && $this->useSearchIndex && $this->searchTerm !== '') {
            return $this->paginateIndexed($perPage, $pageName, $page);
        }

        return $this->paginateRanked($perPage, $pageName, $page);
    }

    /**
     * The page-size rule paginate() and simplePaginate() share: [1, max_candidates]. A page can
     * never exceed the candidate window the ranking is built from, and a caller-supplied
     * per_page cannot make the index path hydrate an unbounded number of models. take()/limit()
     * stay the caller's explicit limit and are not clamped.
     *
     * @internal FederatedSearch's paginators apply it too
     */
    public static function clampPerPage(int $perPage): int
    {
        return max(1, min($perPage, (int) config('fuzzy-search.max_candidates', 1000)));
    }

    /**
     * The page every paginator serves: $page, else the request's $pageName, and 1 for anything
     * that is not a whole number of at least 1 (?page=abc, ?page=0, ?page=-3, ?page[]=1). The
     * request value is user input: unsanitised, the index path threw a TypeError on it and
     * served the wrong rows for 0. Capped so that no offset it gives (simplePaginate() reads one
     * row past the page) overflows into a float: past that, every page is empty anyway.
     *
     * @internal FederatedSearch's paginators apply it too
     */
    public static function resolvePage(?int $page, string $pageName, int $perPage): int
    {
        return min(max(1, (int) ($page ?: request()->input($pageName, 1))), intdiv(PHP_INT_MAX, $perPage + 1));
    }

    /**
     * Length-aware pagination that ranks globally: fetch up to max_candidates rows, rescore in
     * PHP, slice the page. total() is the real DB count. For pages whose offset is beyond the
     * candidate ceiling, fall back to DB-level ordering for that page (documented limitation).
     */
    protected function paginateRanked(int $perPage, string $pageName, ?int $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $startedAt = microtime(true);
        $page      = $this->resolvePage($page, $pageName, $perPage);
        $offset    = ($page - 1) * $perPage;

        $this->prepareQuery();
        $algorithm = $this->extendedQuery !== null
            ? 'extended'
            : ($this->algorithm ?? config('fuzzy-search.default_algorithm', 'fuzzy'));
        $term      = $this->extendedQuery ?? $this->searchTerm;

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
                $candidates = $this->calculateRelevanceScores($candidates, $this->extendedScoringTerms());
            }
            $items = $candidates->slice($offset, $perPage)->values();
        }

        if ($this->withRelevance && $offset >= $maxCandidates && $term !== '') {
            $items = $this->calculateRelevanceScores($items, $this->extendedScoringTerms());
        }

        if ($this->highlightTagOpen) {
            $items = $this->applyHighlighting($items, $this->extendedLeafTerms ?: null);
        }
        if ($this->debugMode) {
            $items = $this->addDebugInfo($items);
        }

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $items, $total, $perPage, $page,
            ['path' => request()->url(), 'pageName' => $pageName]
        );

        $this->dispatchExecuted($algorithm, $this->extendedQuery !== null ? 'extended' : 'like', $total, count($paginator->items()), $startedAt, $term);

        return $paginator;
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
            return $this->paginateOnce($perPage, $pageName, $page);
        }

        $page   = $this->resolvePage($page, $pageName, $perPage);
        $offset = ($page - 1) * $perPage;

        ['total' => $total, 'ranked' => $ranked, 'base' => $base] = $this->indexedRankingAndTotal($modelClass);

        if (empty($ranked) || $offset >= count($ranked)) {
            $sorted = collect(); // past every match: no walk (ruling ER-82)
        } else {
            // Page: walk the matches against the constrained query until offset + perPage
            // rows are collected, then slice.
            $sorted = $this->indexedWindow($modelClass, $base, $ranked, $offset + $perPage)->slice($offset, $perPage)->values();
        }

        if ($this->highlightTagOpen) {
            $sorted = $this->applyHighlighting($sorted, array_keys($this->indexedTermWeights));
        }

        if ($this->debugMode) {
            $sorted = $this->addDebugInfo($sorted);
        }

        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $sorted, $total, $perPage, $page,
            ['path' => request()->url(), 'pageName' => $pageName]
        );

        $this->dispatchExecuted('bm25', 'bm25', $total, count($paginator->items()), $startedAt);

        return $paginator;
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
            : $scorer->count($terms, $modelClass, $this->columnWeights);

        return ['total' => $total, 'ranked' => $ranked, 'base' => $base];
    }

    /**
     * Simple pagination (offset-based, no total count).
     * Routes through the same search path as get() so extended-syntax and BM25 are honoured.
     * perPage is clamped like paginate()'s — see clampPerPage().
     */
    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): \Illuminate\Contracts\Pagination\Paginator
    {
        $perPage = $this->clampPerPage($perPage);
        $page    = $this->resolvePage($page, $pageName, $perPage);
        $offset  = ($page - 1) * $perPage;

        // Fetch one extra item so Paginator::setItems() can detect whether a next
        // page exists (it sets hasMore = count($items) > $perPage, then trims internally).
        // Save and restore so that a re-used SearchBuilder instance is not permanently
        // mutated by the pagination call.
        $savedLimit   = $this->limit;
        $savedOffset  = $this->offset;
        $this->limit  = $perPage + 1;
        $this->offset = $offset;
        // The extra row is a look-ahead, not a result: keep it out of the event's resultCount.
        $this->reportedResultCap = $perPage;

        try {
            $all = $this->get();
        } finally {
            $this->limit  = $savedLimit;
            $this->offset = $savedOffset;
            $this->reportedResultCap = null;
        }

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
     * Get first result.
     *
     * The limit it sets is restored afterwards (as simplePaginate() does with its look-ahead),
     * so first() leaves the builder exactly as it found it.
     */
    public function first(): ?Model
    {
        $limit       = $this->limit;
        $this->limit = 1;

        try {
            return $this->get()->first();
        } finally {
            $this->limit = $limit;
        }
    }

    /**
     * Get count
     */
    public function count(): int
    {
        if ($this->matchesNothing()) {
            return 0;
        }

        $this->guardEmptyTerm();

        return $this->withFallback(
            function (): int {
                // Mirror paginateOnce()/paginateRanked() so count() never disagrees with
                // paginate()->total() on the same builder. Never for extended queries, which
                // run on the LIKE path (mirrors executeSearch(); see getDebugInfo()['index_ignored']).
                if ($this->extendedQuery === null && $this->useSearchIndex && $this->searchTerm !== '') {
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

                $this->prepareQuery();

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
     * Get facet counts, highest count first and then by value.
     *
     * reorder() drops the search's relevance ORDER BY before the aggregate: it orders by a
     * CASE expression over ungrouped columns, which MySQL 8 rejects under only_full_group_by
     * (1055) and PostgreSQL under 42803 — every relevance-ordered search threw there. It is
     * also meaningless for a grouped count, so the counts get an order of their own instead
     * (the same fix count() applies by going through getCountForPagination()).
     */
    public function getFacets(): array
    {
        if (empty($this->facets)) {
            return [];
        }

        if ($this->matchesNothing()) {
            return array_fill_keys($this->facets, []);
        }

        $this->guardEmptyTerm();

        return $this->onQueryClone(function (): array {
            $this->prepareQuery();
            $facetResults = [];
            // On the base query, so global scopes are already applied: a scope's select() would
            // otherwise run at pluck() time and replace the facet's select(col, COUNT(*)).
            $base = $this->query instanceof EloquentBuilder ? $this->query->toBase() : $this->query;
            $own  = $this->qualifiedColumnMap($base);

            foreach ($this->facets as $facet) {
                $column = ($own[$facet] ?? '') . $facet; // the result stays keyed by $facet; pluck() strips the table

                $facetResults[$facet] = $base
                    ->clone()
                    ->reorder()
                    ->select($column, DB::raw('COUNT(*) as count'))
                    ->groupBy($column)
                    ->orderByDesc('count')
                    ->orderBy($column)
                    ->pluck('count', $column)
                    ->toArray();
            }

            return $facetResults;
        });
    }

    /**
     * Apply this builder's search to $this->query: the extended/boolean AST when extended()
     * or searchBoolean() was called, the LIKE conditions otherwise. Every entry point that
     * needs a prepared query but does not go through executeSearch() (which dispatches to
     * executeExtendedSearch() itself) calls this, so none of them can run the raw extended
     * string — "name:john" — through the LIKE path as if it were a plain term.
     */
    private function prepareQuery(): void
    {
        $this->extendedQuery !== null ? $this->compileExtendedQuery() : $this->buildQuery();
    }

    /**
     * Truncate the search term to query.max_term_length characters (never bytes), so no
     * driver ever generates O(n²) LIKE patterns from a multi-kilobyte term.
     *
     * Called where the term becomes a query, and nowhere else: buildQuery() (the LIKE path) and
     * indexedQueryTerms() (the index path). Every terminal reaches one of them — get(), first(),
     * paginate(), simplePaginate(), count(), getFacets(), toSql(), getBindings(), getAnalytics()
     * and fallback() retries — before it reads the term. An extended query is a query, not a
     * term — the Lexer caps each of its tokens.
     */
    protected function capSearchTerm(): void
    {
        if ($this->extendedQuery !== null || $this->searchTerm === '') {
            return;
        }

        $this->searchTerm = FuzzySearch::capTerm($this->searchTerm);
    }

    /**
     * Build the query
     */
    protected function buildQuery(): void
    {
        $this->capSearchTerm(); // see capSearchTerm(): the LIKE path's one call

        // Process search term
        $searchTerm = $this->processSearchTerm($this->searchTerm);

        if ($searchTerm !== '') {
            // No column: match nothing, never every row (a fallback() retry after the index path
            // reaches here without matchesNothing(); '0 = 1' is Laravel's own whereIn([]) form).
            $this->searchableColumns === [] ? $this->query->whereRaw('0 = 1') : $this->applySearchConditions($searchTerm);
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

        // Apply sorting: an explicit orderBy() replaces the relevance order
        if (empty($this->sortBy) && $this->withRelevance && $this->searchTerm !== '') {
            $this->applyRelevanceOrdering();
        }

        $this->applyExplicitOrder($this->query);
    }

    /**
     * orderBy()'s columns, in the order the calls were made, then stableRanking()'s key as the
     * tiebreak ($endOnKey: always — the index walk needs a total order). On every path (LIKE,
     * extended, index) an explicit order is the result order: PHP rescoring still sets _score but
     * does not re-sort by it (calculateRelevanceScores()), and the index path walks its matches in
     * this order (orderedIndexedKeys()).
     */
    private function applyExplicitOrder(Builder|EloquentBuilder $query, bool $endOnKey = false): void
    {
        foreach ($this->sortBy as $sort) {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        if (!$this->stableRankingEnabled && !$endOnKey) {
            return;
        }

        // Stable ranking. An aliased FROM has no "users"."id", only its alias's, and a fromSub() has
        // neither (2.0 ordered by the bare key). A plain builder's bare "id" is qualified under a
        // join like a searched column: a join selecting both tables' columns makes it ambiguous.
        if ($query instanceof EloquentBuilder) {
            $model     = $query->getModel();
            $from      = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::fromTable($query->toBase()->from);
            $keyColumn = match (true) {
                $from === null    => $model->getKeyName(),
                $from[1] !== null => $from[1] . '.' . $model->getKeyName(),
                default           => $model->getQualifiedKeyName(),
            };
            $names = [$model->getKeyName(), $model->getQualifiedKeyName(), $keyColumn];
        } else {
            $keyColumn = ($this->qualifiedColumnMap($query)['id'] ?? '') . 'id';
            $names     = ['id', $keyColumn];
        }

        // Once (ruling ER-52): SQL Server rejects a column named twice in ORDER BY.
        $orders = ($query instanceof EloquentBuilder ? $query->getQuery() : $query)->orders ?? [];
        if (array_intersect(array_filter(array_column($orders, 'column'), 'is_string'), $names) === []) {
            $query->orderBy($keyColumn, 'asc');
        }
    }

    /**
     * Process search term (normalization, stop words). Accents are not folded here: the folded
     * form is searched beside the typed term (termVariants()), never instead of it.
     */
    protected function processSearchTerm(string $term): string
    {
        // Unicode normalization
        if ($this->unicodeNormalizeEnabled && function_exists('normalizer_normalize')) {
            $term = normalizer_normalize($term, \Normalizer::FORM_C);
        }

        // Remove stop words
        if (!empty($this->stopWords)) {
            $words = preg_split(self::WHITESPACE, $term);
            $words = array_filter($words, function ($word) {
                return !in_array(Utf8::lowerAscii($word), $this->stopWords);
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
        return Accents::fold($string);
    }

    /** Whether the term's accent-folded form is searched too: the global default, or an explicit opt-in. */
    protected function foldsAccents(): bool
    {
        return $this->accentInsensitiveEnabled || $this->accentFoldingDefault;
    }

    /**
     * The forms a term is searched in: the term, plus its accent-folded form when folding is on
     * and changes it. The folded form is an added variant, like a synonym — "Müller" finds
     * "Zoë Müller" and "Muller" — never a replacement. An ASCII term has one variant, so its SQL is
     * byte-identical to a search with folding off. The LIKE conditions, the relevance ORDER BY,
     * PHP scoring, highlighting and extended() leaves all read it.
     *
     * @return string[]
     */
    protected function termVariants(string $term): array
    {
        $folded = $this->foldsAccents() ? trim($this->removeAccents($term)) : $term;

        return $folded === $term || $folded === '' ? [$term] : [$term, $folded];
    }

    /**
     * A token's alternatives on the LIKE path: each of its variants and that variant's synonyms.
     *
     * @return string[]
     */
    protected function termAlternatives(string $token): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (string $variant) => $this->expandWithSynonyms($variant),
            $this->termVariants($token)
        ))));
    }

    /**
     * An extended query searches both forms too: a leaf whose term folding changes becomes
     * (leaf | folded leaf), field scope kept. A NOT keeps wrapping one leaf, the shape the parser
     * builds: !term becomes !term !folded. A query with nothing to fold comes back as it was.
     */
    private function withTermVariants(AstNode $node): AstNode
    {
        if ($node instanceof AndNode || $node instanceof OrNode) {
            return new ($node::class)(array_map(fn (AstNode $child) => $this->withTermVariants($child), $node->children));
        }

        if ($node instanceof NotNode) {
            $child = $this->withTermVariants($node->child);

            return $child instanceof OrNode
                ? new AndNode(array_map(fn (AstNode $variant) => new NotNode($variant), $child->children))
                : $node;
        }

        $leaf     = $node instanceof FieldTerm ? $node->term : $node;
        $variants = $this->termVariants($leaf->term);
        if (count($variants) === 1) {
            return $node;
        }

        return new OrNode(array_map(function (string $term) use ($node, $leaf): AstNode {
            $variant = new ($leaf::class)($term);

            return $node instanceof FieldTerm ? new FieldTerm($node->field, $variant) : $variant;
        }, $variants));
    }

    /**
     * Expand search terms with synonyms
     */
    protected function expandWithSynonyms(string $term): array
    {
        $terms = [$term];
        $lowerTerm = Utf8::lowerAscii($term);

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
            $tokens = preg_split(self::WHITESPACE, $searchTerm, -1, PREG_SPLIT_NO_EMPTY); // not array_filter(): it drops "0"
        } else {
            $tokens = [$searchTerm];
        }

        // Under tokenize(), similar_text bounds each token by the whole term's length (ER-59).
        $wholeTermLength = $this->tokenizeSearch ? mb_strlen($searchTerm, 'UTF-8') : null;

        // Expand with the folded variant and synonyms
        $allTerms = [];
        foreach ($tokens as $token) {
            $allTerms = array_merge($allTerms, $this->termAlternatives($token));
        }
        $allTerms = array_unique($allTerms);

        // The SQL names a direct column qualified under a join; the targets themselves (scoring,
        // highlighting) keep the logical name.
        $own     = $this->qualifiedColumnMap($this->query);
        $targets = array_map(
            fn (array $t) => $t['relation'] === null ? ['relation' => null, 'column' => ($own[$t['column']] ?? '') . $t['column']] : $t,
            $this->resolveColumnTargets()
        );

        // whereHas() needs an Eloquent group, but an Eloquent where(Closure) group carries the
        // model's table, not the FROM and its alias, which a driver may read (metaphone's shadow
        // column check). Direct columns alone group on the base query, as compileExtendedQuery()
        // does; the SQL is the same.
        $group = $this->query instanceof EloquentBuilder && $this->relationTargets() === []
            ? $this->query->getQuery()
            : $this->query;

        if ($this->tokenMatchMode === 'all' && $this->tokenizeSearch) {
            // Every token must match at least one column
            foreach ($tokens as $token) {
                $tokenTerms = $this->termConditions($this->termAlternatives($token));
                $group->where(function ($q) use ($tokenTerms, $targets, $wholeTermLength) {
                    $first = true;
                    foreach ($tokenTerms as [$term, $termOptions]) {
                        foreach ($targets as $target) {
                            $this->applyColumnCondition($q, $target, $term, $first ? 'and' : 'or', $termOptions, $wholeTermLength);
                            $first = false;
                        }
                    }
                });
            }
            return;
        }

        // Any token can match any column
        $allTerms = $this->termConditions($allTerms);
        $group->where(function ($q) use ($allTerms, $targets, $wholeTermLength) {
            $first = true;
            foreach ($allTerms as [$term, $termOptions]) {
                foreach ($targets as $target) {
                    $this->applyColumnCondition($q, $target, $term, $first ? 'and' : 'or', $termOptions, $wholeTermLength);
                    $first = false;
                }
            }
        });
    }

    /**
     * Each term with the driver options it is applied with. An explicit accent opt-in ORs
     * unaccent() beside the algorithm on PostgreSQL (FuzzySearch::applyFuzzyWhere()): only the
     * first term of each accent-free form carries it, since a folded variant unaccents to its
     * own term's form and would repeat the same alternative.
     *
     * @param  string[] $terms
     * @return array<int, array{0: string, 1: array<string, mixed>}> [term, options], in order
     */
    private function termConditions(array $terms): array
    {
        $unaccented = [];
        $conditions = [];

        foreach ($terms as $term) {
            $form         = mb_strtolower($this->removeAccents($term), 'UTF-8');
            $conditions[] = [$term, ['accent_insensitive' => $this->accentInsensitiveEnabled && !isset($unaccented[$form])]];
            $unaccented[$form] = true;
        }

        return $conditions;
    }

    /**
     * Apply one searchIn() column's fuzzy condition to $query (an Eloquent or Query
     * builder inside a where-group). Direct columns go straight to the driver; relation
     * columns wrap the driver condition in whereHas()/orWhereHas() on the relation path,
     * which Eloquent compiles to a portable EXISTS subquery.
     *
     * @param array{relation: ?string, column: string} $target
     * @param array<string, mixed> $termOptions this term's own driver options (termConditions())
     * @param int|null $wholeTermLength under tokenize(), the whole search term's length, which
     *                 similar_text's min_percentage bound measures (ruling ER-59)
     */
    protected function applyColumnCondition($query, array $target, string $term, string $boolean, array $termOptions = [], ?int $wholeTermLength = null): void
    {
        $options = array_merge($this->options, ['accent_insensitive' => $this->accentInsensitiveEnabled], $termOptions);

        if ($target['relation'] === null) {
            $subQuery = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
            $this->fuzzySearch->applyTermWhere($subQuery, $target['column'], $term, $this->algorithm, $options, $boolean, $wholeTermLength);
            return;
        }

        $method = $boolean === 'or' ? 'orWhereHas' : 'whereHas';
        $query->{$method}($target['relation'], function (EloquentBuilder $related) use ($target, $term, $options, $wholeTermLength) {
            $this->fuzzySearch->applyTermWhere($related->getQuery(), $target['column'], $term, $this->algorithm, $options, 'and', $wholeTermLength);
        });
    }

    /**
     * Apply relevance ordering
     */
    protected function applyRelevanceOrdering(): void
    {
        $driver = $this->query->getConnection()->getDriverName();
        // Each tier matches any form the term is searched in (termVariants()), so a row found through
        // the folded form ranks as a match of it; one form (an ASCII term) compiles as before.
        $terms  = $this->termVariants($this->searchTerm);
        // Escape LIKE metacharacters so user input cannot widen the match set (consistent
        // with all driver LIKE paths). The exact-match binding uses the raw term intentionally.
        $safeTerms = array_map(fn (string $term) => \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::escapeLike($term, $driver), $terms);

        $scoreExpressions = [];
        $bindings = [];
        $own      = $this->qualifiedColumnMap($this->query);

        foreach ($this->directTargets() as $column => $directColumn) {
            $weight = $this->columnWeights[$column] ?? 1;
            $prefixBoost = $this->prefixBoostMultiplier;
            $col = $this->quoteColumn(($own[$directColumn] ?? '') . $directColumn, $driver);
            // ILIKE on PostgreSQL, whose LIKE is case-sensitive; ESCAPE '!' everywhere else.
            $like = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::like($col, $driver, \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::likeOperator($driver));

            $equals = implode(' OR ', array_fill(0, count($terms), "{$col} = ?"));
            $likes  = implode(' OR ', array_fill(0, count($terms), $like));

            $scoreExpressions[] = "(CASE WHEN {$equals} THEN ? ELSE 0 END)";
            $scoreExpressions[] = "(CASE WHEN {$likes} THEN ? ELSE 0 END)";
            $scoreExpressions[] = "(CASE WHEN {$likes} THEN ? ELSE 0 END)";
            $bindings = array_merge(
                $bindings,
                $terms, [(int) round($weight * $this->scoring['exact_match'])],
                array_map(fn (string $safe) => $safe . '%', $safeTerms), [(int) round($weight * $this->scoring['prefix_match'] * $prefixBoost)],
                array_map(fn (string $safe) => '%' . $safe . '%', $safeTerms), [(int) round($weight * $this->scoring['contains'])],
            );
        }

        if (!empty($scoreExpressions)) {
            $this->query->orderByRaw('(' . implode(' + ', $scoreExpressions) . ') DESC', $bindings);
        }
    }

    /**
     * Quote column based on database driver; a qualified column's table gets the connection's
     * table prefix, as the grammar writes the FROM table
     */
    protected function quoteColumn(string $column, string $driver): string
    {
        return \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::quoteIdentifier($column, $driver, $this->query->getGrammar()->getTablePrefix());
    }

    /**
     * Calculate relevance scores for results. A column adds up the best score of each group of
     * terms, so a row that matches more of an extended query ranks higher; a single term scores
     * exactly as the search term does. $terms are an extended query's leaf terms; null scores the
     * search term in every form it was searched in (termVariants()).
     *
     * Ruling ER-65: while accent folding is on for the query (foldsAccents()), a group is the terms
     * with one accent-folded form — the search term and its folded form, or an extended leaf and
     * the folded twin withTermVariants() adds — so a twin never counts twice. With folding off,
     * each distinct term is its own group: a typed "müller" and "muller" add up.
     *
     * @param string[]|null $terms
     */
    protected function calculateRelevanceScores(Collection $results, ?array $terms = null): Collection
    {
        $groups = [];
        $folds  = $this->foldsAccents();
        foreach ($terms ?? $this->termVariants($this->searchTerm) as $term) {
            $term = mb_strtolower($term, 'UTF-8');
            $key  = $folds ? trim($this->removeAccents($term)) : $term;
            $groups[$key === '' ? $term : $key][] = $term;
        }
        $groups = array_values($groups);

        // Ruling ER-55, the per-row similarity budget: a group gets the similarity/Levenshtein floor
        // only while the groups' total length, each counted by its longest member, stays within
        // Utf8::SCORING_MAX_CHARS (the first always does, so one term scores as it always has); the
        // rest score by tier alone. It bounds a many-leaf extended query to about one capped
        // comparison per value and form.
        $fuzzy = [];
        $spent = 0;
        foreach ($groups as $i => $group) {
            $spent    += max(array_map(fn (string $term) => mb_strlen($term, 'UTF-8'), $group));
            $fuzzy[$i] = $i === 0 || $spent <= Utf8::SCORING_MAX_CHARS;
        }

        $results = $results->map(function ($item) use ($groups, $fuzzy) {
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

                // Each value lowered and cut once, not once per term.
                $values = array_map(fn (string $value) => [$lower = mb_strtolower($value, 'UTF-8'), Utf8::scoringInput($lower)], $values);

                // A to-many relation contributes its best related row, never an average.
                foreach ($groups as $i => $group) {
                    $groupScore = 0;
                    foreach ($values as [$lower, $cut]) {
                        foreach ($group as $term) {
                            $groupScore = max($groupScore, $this->scoreLowered($lower, $cut, $term, $weight, $fuzzy[$i]));
                        }
                    }
                    $colScore += $groupScore;
                }

                $columnScores[$column] = $colScore;
                $score += $colScore;
            }

            // The model's own getSearchScore() adjusts the base score first
            $score = $this->searchScore($item, $score);

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
        });

        // An explicit orderBy() is the order (see applyExplicitOrder()); relevance ranks only without one.
        if ($this->sortBy === []) {
            $results = $results->sortByDesc(function ($item) {
                return is_object($item) ? ($item->_score ?? 0) : ($item['_score'] ?? 0);
            })->values();
        }

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
     * $shownOnly (highlighting) keeps only what toArray() would show (see shows()): a column the
     * row hides, a relation it hides, and a leaf the related row hides contribute nothing.
     *
     * @param  array{relation: ?string, column: string} $target
     * @return string[] non-empty strings only
     */
    protected function columnValues($item, string $column, array $target, bool $shownOnly = false): array
    {
        if ($target['relation'] === null) {
            if ($shownOnly && !self::shows($item, self::lastSegment($target['column']))) {
                return []; // a qualified column (teams.name) is the row's attribute "name"
            }
            // A table.column name is read off the attributes, never through getAttribute(): that
            // calls a model method named like the table (ER-57: items.name must not run items()).
            $source = $item instanceof Model && str_contains($target['column'], '.') ? $item->getAttributes() : $item;
            $value  = (string) data_get($source, $target['column'], '');
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
                    if (!$row->relationLoaded($segment) || ($shownOnly && !self::shows($row, $segment))) {
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
            $value = $shownOnly && !self::shows($row, $target['column']) ? '' : (string) data_get($row, $target['column'], '');
            if ($value !== '') {
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * Whether $row->toArray() shows $key, by Eloquent's own rule (getArrayableItems()): not in
     * getHidden(), and in getVisible() when that is set. Read off the row itself, so a runtime
     * makeHidden()/makeVisible() counts. Anything but a model has no such rule and shows it all.
     */
    private static function shows(mixed $row, string $key): bool
    {
        if (!$row instanceof Model) {
            return true;
        }

        $visible = $row->getVisible();

        return !in_array($key, $row->getHidden(), true) && ($visible === [] || in_array($key, $visible, true));
    }

    /**
     * shows() for a searchIn() column, judged on $row alone: a dotted name by its relation when
     * the row has that relation loaded ("author.name"), else by its last part ("teams.name" is
     * the attribute "name"). The related rows' own rule applies where their values are read.
     *
     * @internal FuzzySearchResource re-applies it to a row as it renders
     */
    public static function showsColumn(mixed $row, string $column): bool
    {
        $head = strstr($column, '.', true);

        return self::shows($row, $head !== false && $row instanceof Model && $row->relationLoaded($head) ? $head : self::lastSegment($column));
    }

    /**
     * Whether toArray() shows a searchIn() column on $item (the #5 rule): the attribute for a
     * direct column; for a relation path, the relation on the row and each segment, then the leaf,
     * on every related row loaded along the path.
     *
     * @param array{relation: ?string, column: string} $target
     */
    protected function columnShown($item, array $target): bool
    {
        if ($target['relation'] === null) {
            return self::shows($item, self::lastSegment($target['column']));
        }

        $rows = [$item];
        foreach ([...explode('.', $target['relation']), $target['column']] as $key) {
            $next = [];
            foreach ($rows as $row) {
                if (!self::shows($row, $key)) {
                    return false;
                }
                if ($row instanceof Model && $row->relationLoaded($key)) {
                    $related = $row->getRelation($key);
                    foreach ($related instanceof \Illuminate\Support\Collection ? $related : [$related] as $one) {
                        if ($one !== null) {
                            $next[] = $one;
                        }
                    }
                }
            }
            $rows = $next;
        }

        return true;
    }

    /**
     * Score one string against the search term: the tier constants from scoring.* times the
     * column weight, or the similarity/Levenshtein floor for fuzzy matches. $term arrives
     * mb_strtolower()ed by calculateRelevanceScores(); the value is lowered the same way, so
     * the exact/prefix/contains tiers are case-insensitive for every script, not only ASCII.
     */
    protected function scoreValue(string $value, string $term, float|int $weight): float
    {
        $value = mb_strtolower($value, 'UTF-8');

        return $this->scoreLowered($value, Utf8::scoringInput($value), $term, $weight);
    }

    /**
     * scoreValue() for a value already lowered, with its Utf8::scoringInput() cut made once by
     * the caller. $fuzzy false (past the similarity budget) scores the tiers only.
     */
    private function scoreLowered(string $value, string $cut, string $term, float|int $weight, bool $fuzzy = true): float
    {
        if ($value === $term) {
            return $this->scoring['exact_match'] * $weight;
        }
        if (str_starts_with($value, $term)) {
            return $this->scoring['prefix_match'] * $weight * $this->prefixBoostMultiplier;
        }
        if (str_contains($value, $term)) {
            return $this->scoring['contains'] * $weight;
        }
        if (!$fuzzy) {
            return 0.0;
        }

        [$similarity, $distance] = $this->similarity($cut, $term);

        $similarityScore  = ($similarity / 100) * $this->scoring['fuzzy_match'] * $weight;
        $levenshteinScore = ($distance <= $this->typoTolerance) ? max(0, (20 - $distance * 4)) * $weight : 0;

        return max($similarityScore, $levenshteinScore);
    }

    /**
     * similar_text()'s percentage and the Levenshtein distance between $value and $term, each
     * cut by Utf8::scoringInput() to its first 255 characters: both are O(n·m) (similar_text()
     * worse), and on whole values a 127-character term against 300 rows of 20KB took seconds, a
     * CPU DoS. A value or term within the cap is compared as it is, so it scores exactly as before.
     * Every call PHP rescoring makes to either function goes through here.
     *
     * @return array{0: float, 1: int}
     */
    protected function similarity(string $value, string $term): array
    {
        $value = Utf8::scoringInput($value);
        $term  = Utf8::scoringInput($term);
        similar_text($term, $value, $percent);

        return [$percent, FuzzySearch::levenshteinDistance($value, $term)];
    }

    /**
     * Apply highlighting to results. $terms (index path) lists every weighted query term —
     * exact tokens, typo and prefix expansions — so a document that matched through "john"
     * for the query "jonh" still gets its match marked. null (LIKE path) keeps the
     * single-term behaviour: the whole search string is one needle, plus its folded form when
     * that was searched too (termVariants()).
     *
     * @param string[]|null $terms
     */
    protected function applyHighlighting(Collection $results, ?array $terms = null): Collection
    {
        if ($this->decoration !== null) {
            $this->decoration['highlight'] = $terms; // get() highlights its cached and fresh rows alike
            return $results;
        }

        $needles =$terms === null ? $this->termVariants($this->searchTerm) : array_values(array_filter(array_map('strval', $terms), fn ($t) => $t !== ''));
        if ($needles === [] || $needles === ['']) {
            return $results;
        }

        $open  = $this->highlightTagOpen ?? '<em>';
        $close = $this->highlightTagClose ?? '</em>';

        return $results->map(function ($item) use ($needles, $terms, $open, $close) {
            $matches     = [];
            $highlighted = [];

            // Only what toArray() shows: _highlighted and _matches must not reveal a hidden column.
            foreach ($this->resolveColumnTargets() as $column => $target) {
                $values = $this->columnValues($item, $column, $target, true);
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
                    // Merge only where there are several needles (the index and extended paths,
                    // or a LIKE term with a folded variant): a single LIKE needle keeps v2.0's raw,
                    // unmerged offsets so adjacent matches of it stay separate tags (e.g. "an" in
                    // "banana" stays two <em> pairs instead of collapsing into one).
                    if ($terms !== null || count($needles) > 1) {
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
                    // Ruling P8-R10: escaped like the matched branch, so _highlighted is
                    // uniformly safe HTML — not just the columns wrapWithTags() touched.
                    $highlighted[$column] = e($chosen);
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
     * Find all case-insensitive, non-overlapping occurrences of $term in $value.
     * Returns [start, end] inclusive BYTE offsets into $value — the unit wrapWithTags(),
     * `_matches` and renderHighlighted() all slice with substr().
     *
     * /iu compares per character with Unicode case folding ("привет" finds "ПРИВЕТ", "über"
     * finds "ÜBER"), reports byte offsets, and each range spans the matched text's own bytes —
     * the two cases of a letter need not be the same length (k vs the Kelvin sign). A value or
     * term that is not valid UTF-8 keeps the byte search (ASCII case folding only).
     */
    private function findMatchOffsets(string $value, string $term): array
    {
        if ($term === '') {
            return [];
        }

        // An invalid term cannot even compile under /u (E_WARNING, not false), so check it first;
        // an invalid value fails at match time and returns false.
        if (mb_check_encoding($term, 'UTF-8')
            && preg_match_all('/' . preg_quote($term, '/') . '/iu', $value, $found, PREG_OFFSET_CAPTURE) !== false) {
            return array_map(fn (array $m) => [$m[1], $m[1] + strlen($m[0]) - 1], $found[0]);
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
     * hit the same characters ("john" and "johnny") produce one tag pair. Only called with
     * multiple needles (the index and extended paths, a LIKE term with a folded variant); a single
     * LIKE needle keeps v2.0's raw, unmerged offsets, so back-to-back repeats of the same needle
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

        // displayValueFor() already returns safe HTML (the _highlighted branch is
        // pre-escaped per P8-R10; the data_get() fallback escapes itself) — wrapping it in
        // e() again here would double-escape entities like "&lt;" into "&amp;lt;".
        return self::displayValueFor($result, $column);
    }

    /**
     * Plain (unhighlighted) display value for a column: `_highlighted` when the search
     * produced one, otherwise data_get(). Always HTML-safe: the `_highlighted` branch is
     * pre-escaped by applyHighlighting() (matched values via wrapWithTags(), non-matching
     * ones via e() — P8-R10), and the data_get() fallback escapes here. A Collection value
     * is reduced to its first item; anything that isn't a scalar at that point — including a
     * model instance, or a to-many relation collection data_get() couldn't resolve to one —
     * renders as an empty string rather than a warning.
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
        return is_scalar($value) ? e((string) $value) : '';
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
     * Add debug information. $algorithm is the one the rows were found with (get() passes the
     * one a fallback() ran, recorded when the search asked for this); null reads the current one.
     */
    protected function addDebugInfo(Collection $results, ?string $algorithm = null): Collection
    {
        $algorithm ??= $this->extendedQuery !== null ? 'extended' : ($this->algorithm ?? 'fuzzy');

        if ($this->decoration !== null) {
            $this->decoration['debug'] = $algorithm; // get() debugs its cached and fresh rows alike
            return $results;
        }

        return $results->map(function ($item) use ($algorithm) {
            // The #5 rule: a column toArray() would not show is left out, as from _highlighted.
            $shown = array_flip(array_keys(array_filter($this->resolveColumnTargets(), fn (array $target) => $this->columnShown($item, $target))));

            $debug = [
                'term' => $this->searchTerm,
                'algorithm' => $algorithm,
                'typo_tolerance' => $this->typoTolerance,
                'prefix_boost' => $this->prefixBoostMultiplier,
                'columns' => array_values(array_filter($this->searchableColumns, fn (string $column) => isset($shown[$column]))),
                'weights' => array_intersect_key($this->columnWeights, $shown),
                'column_scores' => array_intersect_key(is_object($item) ? ($item->_column_scores ?? []) : ($item['_column_scores'] ?? []), $shown),
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
     * The cache key: cache.prefix plus a hash of everything that changes get()'s rows or their
     * shape — the builder's settings, the caller's query (its SQL, bindings, model class and
     * eager-load names; their constraints are applied on each read, see fromCachePayload()) and
     * where it runs (connection, driver, host, port, database, table prefix and, on PostgreSQL,
     * search_path: one extra query when a search is cached), so two tenants or two highlight
     * styles never share an entry. null when a customScore() closure is set: a closure
     * cannot be part of a key, so that search is not cached (it was stored under a key no later
     * call could read).
     */
    protected function generateCacheKey(): ?string
    {
        if ($this->customScoreCallback !== null) {
            return null;
        }

        $connection = $this->query->getConnection();

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
            'accent_folding_default' => $this->accentFoldingDefault,
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
            'highlight'              => [$this->highlightTagOpen, $this->highlightTagClose],
            'with_relevance'         => $this->withRelevance,
            'debug'                  => $this->debugMode,
            'index_model'            => $this->invertedIndexModelClass,
            'scoring'                => $this->scoring,
            'model'                  => $this->query instanceof EloquentBuilder ? $this->query->getModel()::class : null,
            'eager_loads'            => $this->query instanceof EloquentBuilder ? array_keys($this->query->getEagerLoads()) : [],
            'connection'             => [
                $connection->getName(), $connection->getDriverName(), $connection->getConfig('host'), $connection->getConfig('port'),
                $connection->getDatabaseName(), $connection->getTablePrefix(),
                // Schema-per-tenant on PostgreSQL switches search_path on one connection and database.
                $connection->getDriverName() === 'pgsql'
                    ? $connection->selectOne("select current_setting('search_path') as search_path")->search_path
                    : null,
            ],
            'base_sql'               => $this->query->toSql(),
            'base_bindings'          => $this->query->getBindings(),
            // Ruling ER-70: every query-time setting (min_percentage, max_candidates, driver
            // options, ...) at once, keys sorted so that an equal config gives the same key.
            'config'                 => self::sortedKeys(config('fuzzy-search', [])),
        ];

        return config('fuzzy-search.cache.prefix', 'fuzzy_search_') . md5(serialize($data));
    }

    /**
     * $value with every array in it sorted by key, at every depth. SORT_STRING is a total order
     * even for mixed int and string keys; keys are kept, so a list's order still tells lists apart.
     */
    private static function sortedKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        ksort($value, SORT_STRING);

        return array_map(self::sortedKeys(...), $value);
    }

    /**
     * Get raw SQL for debugging
     */
    public function toSql(): string
    {
        return $this->onQueryClone(function (): string {
            $this->prepareQuery();
            return $this->query->toSql();
        });
    }

    /**
     * Get bindings for debugging
     */
    public function getBindings(): array
    {
        return $this->onQueryClone(function (): array {
            $this->prepareQuery();
            return $this->query->getBindings();
        });
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
        if ($this->searchTerm === '' || mb_strlen($this->searchTerm, 'UTF-8') < 2) {
            return [];
        }

        if (empty($this->searchableColumns)) {
            return [];
        }

        // NF-1: with every searchable column hidden there is nothing to suggest (ER-51) — no query.
        $targets = $this->suggestTargets();
        if ($targets === []) {
            return [];
        }

        // 'auto' leaves a constrained query (a where(), a join, a tenant scope) to the table scan:
        // the dictionary is scoped to the model, not to the query. suggestFrom('index') opts back
        // in. The check reads the query the table scan runs on, so it only switches when that helps.
        $constrained = $this->suggestSource === 'auto' && $this->hasSuggestionConstraints($this->query);

        if ($this->suggestSource !== 'table' && !$constrained) {
            $fromIndex = $this->suggestFromIndex($limit);
            if ($fromIndex !== null || $this->suggestSource === 'index') {
                return $fromIndex ?? [];
            }
        }

        $suggestions = [];
        // $rawTerm  → PHP str_starts_with / strcmp comparisons (must be unescaped)
        // $safeTerm → LIKE bindings only (the escape character, % and _ — and [ on SQL Server —
        // escaped so they match literally). Never swap these: passing $safeTerm to str_starts_with
        // would miss values containing those characters, and passing $rawTerm to LIKE would treat
        // them as wildcards.
        $rawTerm  = Utf8::lowerAscii($this->searchTerm);
        $safeTerm = \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::escapeLike($rawTerm, $this->query->getConnection()->getDriverName());

        $results = $this->suggestCandidateQuery($safeTerm)->limit($limit * 3)->get();

        // Extract unique suggestions from results
        foreach ($results as $result) {
            foreach ($targets as $column => $target) {
                // Ruling ER-51: never a word from a column the row hides (the #5 rule) — this also
                // catches a runtime makeHidden(), which suggestTargets() cannot see.
                foreach ($this->columnValues($result, $column, $target, true) as $value) {
                    // Extract the matching word/phrase
                    $words = preg_split(self::WHITESPACE, $value);
                    foreach ($words as $word) {
                        $wordLower = Utf8::lowerAscii($word);
                        if (str_starts_with($wordLower, $rawTerm) && strlen($word) > strlen($rawTerm)) {
                            $suggestions[$wordLower] = $word;
                        }
                    }

                    // Also add full column value if it starts with the raw search term
                    $valueLower = Utf8::lowerAscii($value);
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
     * Complete the last whitespace token from the BM25 dictionary, scoped to the model's
     * postings, and prefix the earlier tokens back ("Bob jo" → "Bob johnson"). Returns null
     * when the dictionary cannot serve this builder (no Eloquent model, no fuzzy_index_meta
     * row for it, or the index tables are missing — any other database error surfaces) so
     * suggest() can fall back to the table scan. The meta row — not the postings table — is the "is this model indexed" signal:
     * IndexManager keeps it even after every document is individually removed (total_docs
     * decrements to 0; only flush() deletes the row), so a model that was indexed and is now
     * empty still returns [] from the dictionary (P6-R6's documented auto semantics) instead
     * of silently reverting to the table scan.
     */
    protected function suggestFromIndex(int $limit): ?array
    {
        $modelClass = $this->resolveIndexModelClass();
        if ($modelClass === null) {
            return null;
        }

        $tokens = preg_split('/\s+/u', trim($this->searchTerm)) ?: [];
        $last   = mb_strtolower((string) array_pop($tokens), 'UTF-8');
        $head   = $tokens === [] ? '' : implode(' ', $tokens) . ' ';
        if ($last === '') {
            return [];
        }

        if (app(\Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager::class)->pipelineFor($modelClass)->foldsAccents()) {
            $last = \Ashiqfardus\LaravelFuzzySearch\Support\Accents::fold($last);
        }

        try {
            $indexed = \Illuminate\Support\Facades\DB::table('fuzzy_index_meta')->where('model_type', $modelClass)->exists();
            if (!$indexed) {
                return null;
            }
            $rows = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander::class)->prefix($last, $limit, $modelClass);
        } catch (\Illuminate\Database\QueryException $e) {
            // Same rule as didYouMean(): a missing dictionary means "not migrated" and hands the
            // query back to the table scan; anything else is a real database error and must
            // surface instead of being hidden behind a silently different result set.
            if (\Illuminate\Support\Facades\DB::getSchemaBuilder()->hasTable('fuzzy_index_terms')) {
                throw $e;
            }

            return null; // dictionary not migrated yet
        }

        return array_map(fn (string $term) => $head . $term, array_keys($rows)); // prefix() returns term => weight
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

        $targets = $this->suggestTargets();
        $paths   = array_values(array_unique(array_column(array_filter($targets, fn (array $t) => $t['relation'] !== null), 'relation')));

        // ILIKE on PostgreSQL (its LIKE is case-sensitive), ESCAPE '!' everywhere else;
        // a qualified column's table carries the connection's table prefix, as the FROM does.
        $prefixWhere = function ($q, string $column, string $boolean) use ($safeTerm, $driver) {
            \Ashiqfardus\LaravelFuzzySearch\Support\DbDialect::whereLike($q, $column, $safeTerm . '%', $driver, $boolean);
        };

        // Under a join (which sends 'auto' suggestions here), qualified as the search path is.
        $own = $this->qualifiedColumnMap($suggestQuery);

        $suggestQuery->where(function ($q) use ($targets, $prefixWhere, $own) {
            $first = true;
            foreach ($targets as $target) {
                if ($target['relation'] === null) {
                    $prefixWhere($q, ($own[$target['column']] ?? '') . $target['column'], $first ? 'and' : 'or');
                } else {
                    $q->{$first ? 'whereHas' : 'orWhereHas'}($target['relation'], function ($related) use ($target, $prefixWhere) {
                        $prefixWhere($related, $target['column'], 'and');
                    });
                }
                $first = false;
            }
        });

        if ($suggestQuery instanceof EloquentBuilder && $paths !== []) {
            $suggestQuery->with($paths);
        }

        return $suggestQuery;
    }

    /**
     * The searchIn() targets suggest()'s table scan matches and reads: those the model does not
     * hide by its class-level $hidden/$visible (for a relation column, each segment on the model
     * that holds it, then the leaf on the related model). A row that matches only through a
     * hidden column can yield no suggestion (ER-51), so matching it would only use up the rows
     * the scan fetches (NF-1). Only the WHERE narrows (ruling ER-67): the SELECT stays as it is,
     * so an accessor that reads a hidden attribute still has it (Q13).
     *
     * @return array<string, array{relation: ?string, column: string}>
     */
    private function suggestTargets(): array
    {
        $targets = $this->resolveColumnTargets();

        if (!$this->query instanceof EloquentBuilder) {
            return $targets;
        }

        $model = $this->query->getModel();

        return array_filter($targets, function (array $target) use ($model): bool {
            $current = $model;
            foreach ($target['relation'] === null ? [] : explode('.', $target['relation']) as $segment) {
                if (!self::shows($current, $segment)) {
                    return false;
                }
                $current = $current->{$segment}()->getRelated(); // a relation resolveColumnTargets() allowed
            }

            return self::shows($current, $target['relation'] === null ? self::lastSegment($target['column']) : $target['column']);
        });
    }

    /**
     * Get "Did you mean" spell corrections
     *
     * Returns alternative spellings when the current search yields few or no results, drawn
     * from the searched model's own terms in the BM25 dictionary.
     *
     * @param int $limit Maximum number of alternatives
     * @return array Array of alternative search terms with distance and confidence
     */
    public function didYouMean(int $limit = 3): array
    {
        if ($this->searchTerm === '' || mb_strlen($this->searchTerm) < 2) {
            return [];
        }

        // The dictionary is shared by every indexed model: only this model's terms may be
        // offered. Without a model there is nothing to scope to, and an unscoped read would
        // hand out every other model's terms.
        $modelClass = $this->resolveIndexModelClass();
        if ($modelClass === null) {
            return [];
        }

        $term    = mb_strtolower(trim($this->searchTerm));
        $termLen = mb_strlen($term);

        // The reach grows with the term: 1 edit for 2–3 characters, 2 for 4–5, 3 from 6. Three
        // edits from a 4-letter term reaches almost any short word.
        $maxDistance = min(3, max(1, intdiv($termLen, 2)));

        // The schema is only inspected when the dictionary query actually fails, so the happy
        // path costs one query instead of two. A missing table (migrations not run) means
        // "nothing to suggest"; anything else is a real SQL error and must surface.
        try {
            $candidates = app(\Ashiqfardus\LaravelFuzzySearch\Indexing\TermExpander::class)->candidates($term, $maxDistance, 300, $modelClass);
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

        // Closest first, then the most common, then the most confident, then the term itself so
        // the order is deterministic (strcmp: `<=>` would compare '19' and '100' as numbers).
        usort($alternatives, fn ($a, $b) => ([$a['distance'], $b['_doc_count'], $b['confidence']]
            <=> [$b['distance'], $a['_doc_count'], $a['confidence']]) ?: strcmp($a['term'], $b['term']));

        if ($alternatives !== [] && $this->hasSuggestionConstraints($base = $this->modelBaseQuery($modelClass))) {
            $alternatives = $this->visibleAlternatives($alternatives, $base, $modelClass, $limit);
        }

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
     * The ranked alternatives the constrained base query can see: a candidate stays when at
     * least one row it is posted for passes the query. Each check reads up to max_candidates of
     * the candidate's distinct model_ids (which ones is up to the database's plan) from the
     * postings and runs them through RankedCandidates::keys() — the chunked primary-key
     * whereIn the BM25 constrained path uses — so no LIKE scan runs
     * (the JSON resource calls this on every empty page, which a caller can produce at will).
     * The ids travel as bound values, never as a subquery against the model's table: model_id
     * is a string column, and the dictionary may live on another connection than the model. A candidate whose visible rows
     * all fall outside the capped id list is dropped — the safe direction.
     *
     * @param  list<array<string, mixed>> $alternatives best first
     * @return list<array<string, mixed>>
     */
    protected function visibleAlternatives(array $alternatives, EloquentBuilder $base, string $modelClass, int $limit): array
    {
        $maxIds  = max(1, (int) config('fuzzy-search.max_candidates', 1000));
        $visible = [];

        // ponytail: checks at most max($limit * 3, 10) candidates, so a query that sees few of
        // the model's rows can get fewer than $limit alternatives; raise the cap if that matters.
        foreach (array_slice($alternatives, 0, max($limit * 3, 10)) as $alternative) {
            $ids = DB::table('fuzzy_index_postings as p')
                ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
                ->where('t.term', $alternative['term'])
                ->where('p.model_type', $modelClass)
                ->distinct() // one posting per column: a row holding the term twice took two slots
                ->limit($maxIds)
                ->pluck('p.model_id')
                ->all();

            if (\Ashiqfardus\LaravelFuzzySearch\Indexing\RankedCandidates::keys($base, $ids, 1) !== []) {
                $visible[] = $alternative;

                if (count($visible) >= $limit) {
                    break;
                }
            }
        }

        return $visible;
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
        // prepareQuery() is what resolves an extended query's columns; the query it builds is
        // thrown away with the clone.
        $this->onQueryClone(fn () => $this->prepareQuery());

        return [
            'search_term' => $this->searchTerm,
            'algorithm' => $this->extendedQuery !== null ? 'extended' : ($this->algorithm ?? 'fuzzy'),
            'columns_searched' => $this->searchableColumns,
            'column_weights' => $this->columnWeights,
            'typo_tolerance' => $this->typoTolerance,
            'tokenized' => $this->tokenizeSearch,
            'token_mode' => $this->tokenMatchMode,
            'stop_words_active' => !empty($this->stopWords),
            'synonyms_active' => !empty($this->synonyms) || !empty($this->synonymGroups),
            'accent_insensitive' => $this->foldsAccents(),
            'cached' => $this->cacheSeconds() > 0,
            'recency_boost' => $this->recencyBoostEnabled,
            'limit' => $this->limit,
            'offset' => $this->offset,
        ];
    }
}

