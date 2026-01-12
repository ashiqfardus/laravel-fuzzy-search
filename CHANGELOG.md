# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-01-12

### 🎉 Initial Release

A powerful, zero-config fuzzy search package for Laravel with fluent API. Works with all major databases without external services. Scales to **10 million records** with proper optimization.

### ✨ Features

#### Core Search
- **Zero-config search** - Auto-detects searchable columns from `$fillable` or `$searchable`
- **Fluent API** - Chain methods naturally: `->search()->using()->typoTolerance()->get()`
- **Full Eloquent & Query Builder support** - Works with both seamlessly
- **8 search algorithms**:
  - `fuzzy` - General purpose with typo tolerance (recommended)
  - `levenshtein` - Edit distance based, configurable tolerance
  - `soundex` - Phonetic matching for similar sounding words
  - `metaphone` - More accurate phonetic matching
  - `trigram` - N-gram similarity (best with PostgreSQL pg_trgm)
  - `similar_text` - Percentage-based similarity
  - `simple` / `like` - Basic LIKE matching (fastest, no typo tolerance)

#### Scoring & Relevance
- **Field weighting** - Prioritize columns: `['title' => 10, 'body' => 5]`
- **Relevance scoring** - Results include `_score` attribute
- **Prefix boosting** - Boost results starting with search term
- **Recency boost** - Boost newer records with `boostRecent()`
- **Custom scoring hooks** - Add your own scoring logic via callbacks
- **Partial match support** - Match substrings within words
- **Improved scoring algorithm** - Uses `similar_text()` for percentage-based similarity scoring
- **Better Levenshtein distance scoring** - Proper weight calculation for fuzzy matches
- **Consistent score rounding** - Scores rounded to 2 decimal places

#### Text Processing
- **Multi-word token search** - `tokenize()` with `matchAll()` or `matchAny()`
- **Stop-word filtering** - Multi-language support (en, de, fr, es)
- **Synonym support** - Define synonyms and synonym groups
- **Unicode normalization** - Proper handling of Unicode characters
- **Accent-insensitive search** - `café` matches `cafe`
- **Locale awareness** - Language-specific processing

#### Smart Search Features
- **Autocomplete** - `suggest()` method for search suggestions
- **Spell correction** - `didYouMean()` for typo suggestions
- **Multi-model search** - `FederatedSearch` to search across multiple models
- **Search analytics** - `getAnalytics()` for search insights

#### Performance & Scaling
- **Search index table** - Optional pre-computed index for large datasets
- **Async indexing** - Queue support for background indexing
- **Redis/cache integration** - Cache search results with `cache(60)`
- **Query pattern limiting** - Prevent regex explosion attacks
- **Debounce support** - Rate limit real-time search requests
- **Scales to 10M records** - With partitioning, materialized views, and caching

#### Pagination
- **Stable ranking** - Consistent ordering across pages
- **Multiple pagination types** - Offset, simple, and cursor pagination
- **Manual control** - `take()` and `skip()` for custom pagination

#### Results & Output
- **Highlighted results** - `highlight('mark')` wraps matches in tags
- **Debug mode** - `debugScore()` explains scoring breakdown
- **Faceted search** - Group results by field values

#### Reliability & Security
- **Fallback strategy** - Graceful degradation when primary algorithm fails
- **SQL injection protection** - All queries use parameterized bindings
- **Database-agnostic** - MySQL, PostgreSQL, SQLite, SQL Server, MariaDB

#### Configuration
- **Config presets** - `blog`, `ecommerce`, `users`, `phonetic`, `exact`
- **Publishable config file** - Full customization via `config/fuzzy-search.php`
- **Per-model customization** - Override settings via `$searchable` property

#### Exception Handling
- `LaravelFuzzySearchException` - Base exception with context support
- `EmptySearchTermException` - When search term is empty
- `InvalidAlgorithmException` - When invalid algorithm specified
- `InvalidConfigException` - When configuration is invalid
- `SearchableColumnsNotFoundException` - When no searchable columns found

#### Developer Tools
- **CLI commands**:
  - `fuzzy-search:index` - Build search index
  - `fuzzy-search:clear` - Clear search index
  - `fuzzy-search:benchmark` - Performance benchmarking
  - `fuzzy-search:explain` - Explain search scoring
- **Comprehensive test suite** - 171+ tests with 230+ assertions

### 📚 Documentation
- `docs/GETTING_STARTED.md` - Quick start guide with examples
- `docs/PERFORMANCE.md` - Optimization guide for scaling to millions of records
- `docs/COMPARISON.md` - Comparison with Laravel Scout, Meilisearch, Algolia, Elasticsearch

### 📋 Requirements
- PHP 8.0, 8.1, 8.2, 8.3, or 8.4
- Laravel 9.x, 10.x, 11.x, or 12.x
- MySQL, PostgreSQL, SQLite, SQL Server, or MariaDB

---

[Full Documentation](https://github.com/ashiqfardus/laravel-fuzzy-search)
