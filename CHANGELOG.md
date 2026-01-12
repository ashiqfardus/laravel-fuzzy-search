# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-01-12

### Added

#### Core Features
- Zero-config search with auto-detection of searchable columns
- Fluent API for building search queries
- Full Eloquent and Query Builder support
- Multiple search algorithms: `fuzzy`, `levenshtein`, `soundex`, `trigram`, `simple`

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

