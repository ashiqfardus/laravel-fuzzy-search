# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- GitHub Actions CI/CD workflow for automated testing across PHP 8.0-8.4 and Laravel 9-12
- Code style checks workflow
- Comprehensive issue templates (bug report, feature request, question)
- Custom exception classes for better error handling:
  - `EmptySearchTermException` - thrown when search term is empty
  - `InvalidAlgorithmException` - thrown when invalid algorithm specified
  - `InvalidConfigException` - thrown for configuration errors
  - `SearchableColumnsNotFoundException` - thrown when no searchable columns found
- Enhanced `LaravelFuzzySearchException` base class with debugging features:
  - `getContext()` - retrieve debugging context
  - `withContext()` - add context fluently
  - `toArray()` - get formatted error report for logging
- Config presets for common use cases: `blog`, `ecommerce`, `users`, `phonetic`, `exact`
- `preset()` method to apply predefined configurations easily
- Enhanced debug mode with `debugScore($verbose, $logChannel)` for detailed scoring breakdown
- `getDebugInfo()` method to inspect search configuration
- `allow_empty_search` config option for graceful empty search handling
- Pull request template for consistent PR submissions
- Code of Conduct (Contributor Covenant 2.0)
- `.gitattributes` for cleaner release archives
- Added `like`, `similar_text`, `metaphone` as supported algorithms
- Comparison with competing packages in documentation (soliyer, castellanos)

### Changed
- Enhanced `search()` method to validate empty search terms
- Enhanced `using()` method to validate algorithm names (now supports 8 algorithms)
- `'like'` algorithm normalizes to `'simple'` for consistency
- Improved CONTRIBUTING.md with architecture overview and detailed guidelines
- Updated README.md with additional badges (Tests, PHP Version, Laravel Version)
- Added documentation links to README

### Fixed
- Fixed malformed `LaravelFuzzySearchException` class structure
- Fixed PHPUnit configuration to remove duplicate test suite warnings
- Fixed `FederatedSearchTest` to properly expect exception for empty search

### Documentation
- New `docs/GETTING_STARTED.md` - comprehensive getting started guide with examples
- New `docs/PERFORMANCE.md` - performance optimization guide with benchmarks
- New `docs/COMPARISON.md` - detailed comparison with Laravel Scout, TNTSearch, Meilisearch, Algolia, Elasticsearch, and competing Laravel fuzzy search packages
- Enhanced README with exception handling documentation
- Enhanced README with config presets documentation
- Updated algorithms table to include all 8 supported algorithms

## [1.0.0] - 2026-01-12

### Added

#### Core Features
- Zero-config search with auto-detection of searchable columns
- Fluent API for building search queries
- Full Eloquent and Query Builder support
- Multiple search algorithms: `fuzzy`, `levenshtein`, `soundex`, `metaphone`, `trigram`, `similar_text`, `simple`, `like`

#### Scoring & Weighting
- Field weighting with customizable priorities
- Relevance scoring with `_score` attribute
- Prefix boosting for results starting with search term
- Recency boost for newer records
- Custom scoring hooks via callbacks
- Partial match support

#### Text Processing
- Multi-word token search with `matchAll()` and `matchAny()`
- Stop-word filtering (multi-language: en, de, fr, es)
- Synonym support with groups
- Unicode normalization
- Accent-insensitive search
- Locale awareness

#### Smart Search
- Autocomplete suggestions via `suggest()`
- "Did you mean" spell correction via `didYouMean()`
- Multi-model federated search via `FederatedSearch`
- Search analytics via `getAnalytics()`

#### Performance
- Search index table support
- Async indexing with queue support
- Redis/cache integration
- Query pattern limiting
- Debounce support for real-time search

#### Pagination
- Stable ranking across pages
- Offset, simple, and cursor pagination
- Manual pagination with `take()` and `skip()`

#### Results
- Highlighted results with customizable tags
- Debug/explain-score mode
- Faceted search support

#### Reliability
- Fallback search strategy
- SQL injection protection
- Database-agnostic (MySQL, PostgreSQL, SQLite, SQL Server, MariaDB)

#### Developer Tools
- CLI commands: `fuzzy-search:index`, `fuzzy-search:clear`, `fuzzy-search:benchmark`, `fuzzy-search:explain`
- Comprehensive test suite (342 tests, 460 assertions)
- Per-model customization via `$searchable` property

#### Configuration
- Publishable config file
- Configurable algorithms, scoring, stop words, synonyms
- Cache and indexing settings

### Requirements
- PHP 8.0, 8.1, 8.2, 8.3, 8.4
- Laravel 9.x, 10.x, 11.x, 12.x

