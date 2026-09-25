# Contributing to Laravel Fuzzy Search

Thank you for considering contributing to Laravel Fuzzy Search! This guide will help you get started.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
- [Development Setup](#development-setup)
- [Architecture Overview](#architecture-overview)
- [Coding Standards](#coding-standards)
- [Testing Guidelines](#testing-guidelines)
- [Pull Request Process](#pull-request-process)
- [Releasing (maintainers)](#releasing-maintainers)
## Code of Conduct

- Be respectful and inclusive
- Provide constructive feedback
- Focus on what is best for the community
- Show empathy towards other community members

## How Can I Contribute?

### Reporting Bugs

If you discover a bug, please [create an issue](https://github.com/ashiqfardus/laravel-fuzzy-search/issues/new?template=bug_report.yml) with:

1. A clear, descriptive title
2. Steps to reproduce the issue
3. Expected vs actual behavior
4. Your environment (PHP version, Laravel version, database)
5. Code sample if applicable

### Suggesting Features

Feature requests are welcome! Please [create a feature request](https://github.com/ashiqfardus/laravel-fuzzy-search/issues/new?template=feature_request.yml) with:

1. A clear description of the feature
2. The problem it solves
3. Example usage code
4. Any alternatives you've considered

### Improving Documentation

Documentation improvements are always appreciated:

- Fix typos or unclear explanations
- Add missing examples
- Improve getting started guides
- Add use case tutorials

### Writing Code

See the [Pull Request Process](#pull-request-process) below.

## Development Setup

### 1. Fork & Clone

```bash
git clone https://github.com/YOUR-USERNAME/laravel-fuzzy-search.git
cd laravel-fuzzy-search
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Run Tests

```bash
composer test
```

### 4. Create a Branch

```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/bug-description
```

## Architecture Overview

### Directory Structure

```
src/
├── Analytics/            # Persisted search log (analytics.enabled)
│   ├── RecordSearchLog.php
│   └── SearchAnalytics.php
├── Console/              # Artisan commands
│   ├── Concerns/ValidatesInput.php
│   ├── AddShadowColumnCommand.php
│   ├── AnalyticsCommand.php
│   ├── AnalyticsPruneCommand.php
│   ├── BenchmarkCommand.php
│   ├── ClearCommand.php
│   ├── ExplainCommand.php
│   ├── FlushCommand.php
│   ├── IndexCommand.php
│   ├── RebuildCommand.php
│   ├── StatusCommand.php
│   └── UpgradeV1Command.php
├── Drivers/              # Search algorithm drivers (7 algorithm drivers + BaseDriver)
│   ├── BaseDriver.php
│   ├── FuzzyDriver.php
│   ├── LevenshteinDriver.php
│   ├── MetaphoneDriver.php
│   ├── SimilarTextDriver.php
│   ├── SimpleDriver.php
│   ├── SoundexDriver.php
│   └── TrigramDriver.php
├── Events/               # Fired after each search execution
│   └── FuzzySearchExecuted.php
├── Exceptions/           # Custom exceptions
│   ├── EmptySearchTermException.php
│   ├── InvalidAlgorithmException.php
│   ├── InvalidConfigException.php
│   ├── LaravelFuzzySearchException.php
│   ├── QuerySyntaxException.php
│   └── SearchableColumnsNotFoundException.php
├── Facades/              # Laravel facades
│   ├── FuzzySearch.php
│   └── SearchAnalytics.php
├── Http/Resources/       # JSON API resources
│   ├── FuzzySearchCollection.php
│   └── FuzzySearchResource.php
├── Indexing/             # BM25 inverted-index engine
│   ├── Bm25Scorer.php
│   ├── IndexManager.php
│   ├── NgramTokenizer.php
│   ├── NullStemmer.php
│   ├── Pipeline.php
│   ├── PorterStemmer.php
│   ├── RankedCandidates.php
│   ├── ScriptAwareTokenizer.php
│   ├── StemmerInterface.php
│   ├── TermExpander.php
│   ├── TokenizerInterface.php
│   └── WhitespaceTokenizer.php
├── Integrations/Filament/
│   └── HasFuzzyGlobalSearch.php
├── Jobs/                 # Queue jobs
│   ├── Concerns/ConfiguresRetryLimits.php
│   ├── IndexModelJob.php
│   ├── RebuildIndexJob.php
│   ├── RecordSearchLogJob.php
│   └── ReindexModelJob.php
├── Observers/            # Eloquent model observers for auto-indexing
│   ├── SearchableIndexingObserver.php
│   └── SearchableObserver.php
├── Query/                # Extended query parser (Fuse.js-style operators)
│   ├── AstCompiler.php
│   ├── AstNodes/
│   ├── ExtendedQueryParser.php
│   ├── Lexer.php
│   └── Token.php
├── Scout/                # Laravel Scout engine adapter
│   └── FuzzySearchEngine.php
├── Support/              # Shared helpers (dialects, accents, UTF-8, stop words, columns)
│   ├── Accents.php
│   ├── DbDialect.php
│   ├── IndexQuery.php
│   ├── SearchableColumns.php
│   ├── StopWords.php
│   └── Utf8.php
├── Traits/               # Model traits
│   ├── Fuzzy.php
│   └── Searchable.php
├── FederatedSearch.php   # Multi-model search
├── FuzzySearch.php       # Core driver dispatcher + in-memory facade
├── FuzzySearchServiceProvider.php
├── InMemorySearch.php    # PHP-side search over static arrays/collections
└── SearchBuilder.php     # Fluent query builder (all chainable methods)
```

### Key Components

#### SearchBuilder
The fluent API that users interact with (`Model::search('term')->...`). Handles query configuration, column weighting, algorithm selection, text processing (stop words, synonyms), caching, BM25 index routing, and result formatting.

#### Drivers (7 algorithm drivers + BaseDriver)
Each search algorithm is implemented as a driver extending `BaseDriver`:
- `FuzzyDriver`: General-purpose LIKE-pattern fuzzy matching
- `LevenshteinDriver`: LIKE patterns for up to `levenshtein.max_distance` edits (a native function with `use_native_functions`)
- `SoundexDriver`: Phonetic matching via SOUNDEX()
- `MetaphoneDriver`: PHP `metaphone()` codes matched against a `{column}_metaphone` shadow column
- `TrigramDriver`: N-gram similarity (PostgreSQL pg_trgm with `use_native_functions`, otherwise trigram LIKE patterns)
- `SimilarTextDriver`: a contains-LIKE plus a SQL length bound that enforces `similar_text.min_percentage`
- `SimpleDriver`: Basic LIKE `%term%` query
- `like`: Alias for SimpleDriver

#### Indexing (BM25)
`IndexManager` tokenizes, stems, and persists an inverted index to four tables (`fuzzy_index_terms`, `fuzzy_index_documents`, `fuzzy_index_postings`, `fuzzy_index_meta`). `Bm25Scorer` builds the scoring SQL from those tables.

#### Query (Extended Syntax)
`Lexer` tokenizes the query string, `ExtendedQueryParser` builds an AST, and `AstCompiler` converts it to SQL WHERE clauses. Supports operators: `=`, `^`, `$`, `!`, `'`, `|`, `( )`, `"..."`.

#### Scout Adapter
`FuzzySearchEngine` integrates with Laravel Scout, delegating to `Bm25Scorer` for BM25 ranking.

### Adding a New Search Algorithm

The package has no runtime driver registration: an algorithm is added in the source, in three
places. A name must be lower-case letters and underscores.

1. Create a new driver in `src/Drivers/`. `apply()` adds the algorithm's predicate to the query.
   Build a LIKE with `escapeLike()` and `DbDialect::whereLike()`, as the bundled drivers do, so it
   carries the `ESCAPE` clause each database needs. Relevance ordering and PHP rescoring are the
   `SearchBuilder`'s job, so a driver needs nothing else (`getRelevanceExpression()` and
   `getRelevanceBindings()` are deprecated and never called).

```php
<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Illuminate\Database\Query\Builder;

class MyAlgorithmDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        // Example: values that start with the term. $this->config is the merged
        // fuzzy-search config; your own options live under $this->config['myalgorithm'].
        DbDialect::whereLike($query, $column, $this->escapeLike($value) . '%', $this->driver, $boolean);

        return $query;
    }
}
```

2. Register it in `FuzzySearch::$registry` (`src/FuzzySearch.php`):

```php
protected array $registry = [
    // ... existing entries ...
    'myalgorithm' => Drivers\MyAlgorithmDriver::class,
];
```

   That is enough for the paths that hand a name straight to the registry: the `whereFuzzy`-style
   macros (`whereFuzzy('name', $term, 'myalgorithm')`) and `fallback('myalgorithm')`.

3. Add the name to the list `SearchBuilder::using()` checks (`$supportedAlgorithms` in
   `src/SearchBuilder.php`) and to the message in `src/Exceptions/InvalidAlgorithmException.php`.
   `using()`, a model's `$searchable['algorithm']` and a preset's `algorithm` go through that list
   first, and throw `InvalidAlgorithmException` for a name that is not on it.

4. Add configuration defaults under `'myalgorithm' => [...]` in `config/fuzzy-search.php` if the
   driver reads any. A caller's `options()` are merged into that section.

5. Write tests in `tests/Unit/` and `tests/Feature/`, including one through `using()` and one
   through a macro.

6. Update the algorithm tables in the README (Available Algorithms and Algorithm × Database
   Compatibility).

### Database Compatibility

When adding features, ensure compatibility with the databases CI runs (see `.github/workflows/ci.yml`
and the README's Requirements):
- MySQL 8.0
- MariaDB 11.4 (10.6+ expected)
- PostgreSQL 14
- SQLite 3
- SQL Server 2022

Inside a driver, branch on `$this->driver` and use `$this->isMySqlFamily()` for MySQL and MariaDB together (on Laravel 11+ a MariaDB connection reports `mariadb`); elsewhere use `DbDialect::isMySqlFamily($query->getConnection()->getDriverName())`.

```php
if ($this->isMySqlFamily()) {
    // MySQL and MariaDB
} elseif ($this->driver === 'pgsql') {
    // PostgreSQL
}
```

## Coding Standards

### PSR-12

Follow [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standards:

- Use 4 spaces for indentation
- Opening braces on same line for methods
- One statement per line
- Proper spacing around operators

### PHPDoc

Add PHPDoc blocks for all public methods:

```php
/**
 * Search for records matching the given term
 * 
 * @param string $term The search term
 * @return self
 * @throws EmptySearchTermException if term is empty
 */
public function search(string $term): self
{
    // ...
}
```

### Naming Conventions

- Classes: `PascalCase`
- Methods: `camelCase`
- Variables: `camelCase`
- Constants: `UPPER_SNAKE_CASE`
- Config keys: `snake_case`

### Type Hints

Always use type hints:

```php
// ✅ Good
public function search(string $term): self

// ❌ Bad
public function search($term)
```

### Commit Messages

Write clear, descriptive commit messages:

```
feat: add support for custom scoring callbacks
fix: resolve issue with empty search terms
docs: improve getting started guide
test: add coverage for federated search
refactor: simplify algorithm driver selection
perf: optimize query generation for large datasets
```

Use conventional commit prefixes:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `test`: Adding or updating tests
- `refactor`: Code refactoring
- `perf`: Performance improvement
- `chore`: Maintenance tasks

## Testing Guidelines

### Writing Tests

All new features must include tests. We use PHPUnit with Orchestra Testbench.

The suite runs on `config/fuzzy-search.php` exactly as it ships: `tests/TestCase.php` loads the file whole, and `tests/Unit/ShippedConfigTest.php` fails if the test config drifts from it. A test that needs a different value sets it itself, with `config([...])` in the test or in its class's `defineEnvironment()`, and a one-line comment saying why. Don't add an override to `TestCase`. If the test environment really can't run a shipped value, list it in `ShippedConfigTest::OVERRIDES` with its reason.

Name test methods `test_*` (PHPUnit 12 and 13 no longer read the `@test` annotation).

#### Unit Tests

Test individual components in isolation:

```php
// tests/Unit/MyFeatureTest.php
namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class MyFeatureTest extends TestCase
{
    public function test_it_does_something_correctly(): void
    {
        $result = // ... test code
        
        $this->assertEquals($expected, $result);
    }
}
```

#### Feature Tests

Test complete workflows:

```php
// tests/Feature/SearchTest.php
namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;

require_once __DIR__ . '/../TestModels.php'; // the shared test models (User, Product, ...)

class SearchTest extends TestCase
{
    public function test_it_searches_with_typo_tolerance(): void
    {
        // TestCase seeds a few users; add the row this test is about.
        User::create(['name' => 'Zelda Quill', 'email' => 'zelda@example.com']);

        $results = User::search('zedla')->get();   // a transposition of "zelda"

        $this->assertCount(1, $results);
        $this->assertEquals('Zelda Quill', $results->first()->name);
    }
}
```

### Testing Different Databases

The whole suite runs on in-memory SQLite by default. Set `DB_TEST_DRIVER` plus the
connection vars to run the *same* suite against a real server. Every test class inherits
this through `tests/Concerns/ConfiguresDatabaseConnection.php`, so BM25 indexing, flush,
Scout and driver tests all execute on the selected database:

```bash
# MySQL / MariaDB
DB_TEST_DRIVER=mysql DB_TEST_HOST=127.0.0.1 DB_TEST_PORT=3306 \
DB_TEST_DATABASE=fuzzy_test DB_TEST_USERNAME=root DB_TEST_PASSWORD=secret \
vendor/bin/phpunit

# PostgreSQL
DB_TEST_DRIVER=pgsql DB_TEST_HOST=127.0.0.1 DB_TEST_PORT=5432 \
DB_TEST_DATABASE=fuzzy_test DB_TEST_USERNAME=postgres DB_TEST_PASSWORD=secret \
vendor/bin/phpunit

# SQL Server
DB_TEST_DRIVER=sqlsrv DB_TEST_HOST=127.0.0.1 DB_TEST_PORT=1433 \
DB_TEST_DATABASE=fuzzy_test DB_TEST_USERNAME=sa DB_TEST_PASSWORD='Secret!123' \
vendor/bin/phpunit
```

Supported values: `sqlite` (default), `mysql`, `mariadb`, `pgsql`, `sqlsrv`. The database
must already exist; tables are created and dropped per test.
`tests/Integration/DatabaseDriverSanityTest.php` fails loudly if the requested driver was
not actually used.

Write assertions that hold on every driver:

- Column quoting differs: `` `name` `` on MySQL, `"name"` on PostgreSQL, `[name]` on
  SQL Server, bare on SQLite. Match SQL with a quote-agnostic regex.
- `LIKE` is case-sensitive on PostgreSQL; the package emits `ILIKE` there.
- MySQL/MariaDB use native `SOUNDEX()`; the other drivers fall back to `LIKE` patterns.
- Dropping a parent table (`fuzzy_index_terms`) fails under foreign keys on real servers.
  Run the migration's `down()` in child-first order instead.

Read `$this->dbDriver` inside a test when the expectation legitimately differs per
database. CI runs the suite on every supported database (see `.github/workflows/ci.yml`).

### Running Tests

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Run specific test file
vendor/bin/phpunit tests/Unit/SearchBuilderTest.php

# Run specific test method
vendor/bin/phpunit --filter test_it_searches_correctly

# Run the Performance suite (timing and memory bounds; CI does not run it)
composer benchmark
```

A run fails on any warning PHPUnit records and on any deprecation raised in `src/` (`failOnWarning`, `failOnDeprecation`); a risky test does not fail it. A test of a deprecated method must catch its notice and assert it: `$this->assertSame([$message], $this->deprecationsFrom(fn () => …))`, with `deprecationsFrom()` from `Tests\Concerns\ReportsSourceDeprecations`. In a test that boots the application (`Tests\TestCase`, `Tests\Integration\DatabaseTestCase`), a deprecation raised by vendor code is logged by Laravel and does not fail the run; in a test that extends `PHPUnit\Framework\TestCase` directly, any deprecation does.

### Test Coverage

Aim for:
- 80%+ overall coverage
- 100% coverage for critical paths (search logic, scoring)
- All public methods tested

## Pull Request Process

### Before Submitting

1. **Run tests**: `composer test`
2. **Check code style**: Ensure PSR-12 compliance
3. **Update docs**: If adding features
4. **Add tests**: For new functionality
5. **Update CHANGELOG**: Add entry under "Unreleased"

### Submitting

1. Push your branch to your fork
2. Open a Pull Request against `main`
3. Fill out the PR template completely
4. Link any related issues

### PR Template

Your PR should include:

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tests pass locally
- [ ] Added new tests for this change
- [ ] Updated existing tests

## Checklist
- [ ] Code follows PSR-12
- [ ] PHPDoc blocks added/updated
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
```

### Review Process

1. Maintainers will review your PR
2. Address any feedback or requested changes
3. Once approved, a maintainer will merge

### After Merge

Your contribution will be included in the next release. Thank you! 🎉

## Releasing (maintainers)

The package carries no version string — Packagist versions come from git tags, so a release
is a tag plus release notes, not a file edit.

1. **CI green on the release commit.** Every row of `test-sqlite`, `test-mysql`, `test-pgsql`,
   `test-mariadb`, `test-sqlsrv`, `test-scout10` and `test-lowest`, and all three `test-filament` legs.
2. **CHANGELOG.** Give `[x.y.z]` its date (`## [2.1.0] — 2026-09-18`, em-dash), keep the
   subsection order Added / Changed / Deprecated / Removed / Fixed / Security, and add the
   compare link at the bottom: `[x.y.z]: https://github.com/ashiqfardus/laravel-fuzzy-search/compare/v<prev>...v<new>`.
3. **Commit** `chore: release x.y.z`.
4. **Annotated tag** (v2.0.0's precedent, not v2.0.1's lightweight tag):
   `git tag -a vX.Y.Z -m "vX.Y.Z — <one-line summary>"`.
5. **Push** the branch and the tag: `git push origin <branch> && git push origin vX.Y.Z`.
6. **GitHub release** — `gh` is not installed here; use the REST API with the token from the
   credential helper and the CHANGELOG section as the body:
   `POST /repos/ashiqfardus/laravel-fuzzy-search/releases` with `tag_name`, `name`, `body`.
7. **Packagist** — confirm the new version appears in
   `https://repo.packagist.org/p2/ashiqfardus/laravel-fuzzy-search.json`. If auto-update is not
   wired to the GitHub webhook, trigger it from the Packagist package page.
8. **Merge into `main`** (the default branch) once the tag is published.
9. **Demo repo** — bump the `ashiqfardus/laravel-fuzzy-search` constraint and the version
   strings in its README, then push. Refresh the demo lock first:
   `composer update ashiqfardus/laravel-fuzzy-search --no-install`.

## Questions?

- 📖 Check the [documentation](docs/)
- 💬 Join [discussions](https://github.com/ashiqfardus/laravel-fuzzy-search/discussions)
- ❓ [Ask a question](https://github.com/ashiqfardus/laravel-fuzzy-search/issues/new?template=question.yml)

## Recognition

All contributors are listed in the [CHANGELOG](CHANGELOG.md) and repository contributors page.

Thank you for contributing! 🙏

