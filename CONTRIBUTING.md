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
├── Console/              # Artisan commands
│   ├── AddShadowColumnCommand.php
│   ├── BenchmarkCommand.php
│   ├── ClearCommand.php
│   ├── ExplainCommand.php
│   ├── FlushCommand.php
│   ├── IndexCommand.php
│   ├── RebuildCommand.php
│   └── StatusCommand.php
├── Drivers/              # Search algorithm drivers (8 total)
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
│   └── SearchableColumnsNotFoundException.php
├── Facades/              # Laravel facades
│   └── FuzzySearch.php
├── Indexing/             # BM25 inverted-index engine
│   ├── Bm25Scorer.php
│   ├── IndexManager.php
│   ├── NullStemmer.php
│   ├── PorterStemmer.php
│   ├── StemmerInterface.php
│   ├── TokenizerInterface.php
│   └── WhitespaceTokenizer.php
├── Jobs/                 # Queue jobs
│   ├── IndexModelJob.php
│   └── RebuildIndexJob.php
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

#### Drivers (8 algorithms)
Each search algorithm is implemented as a driver extending `BaseDriver`:
- `FuzzyDriver`: General-purpose LIKE-pattern fuzzy matching
- `LevenshteinDriver`: PHP-side edit-distance filtering
- `SoundexDriver`: Phonetic matching via SOUNDEX()
- `MetaphoneDriver`: Double-metaphone phonetic matching (requires shadow column)
- `TrigramDriver`: N-gram similarity (PostgreSQL pg_trgm or PHP fallback)
- `SimilarTextDriver`: PHP `similar_text()` percentage threshold
- `SimpleDriver`: Basic LIKE `%term%` query
- `like`: Alias for SimpleDriver

#### Indexing (BM25)
`IndexManager` tokenizes, stems, and persists an inverted index to four tables (`fuzzy_index_terms`, `fuzzy_index_documents`, `fuzzy_index_postings`, `fuzzy_index_meta`). `Bm25Scorer` builds the scoring SQL from those tables.

#### Query (Extended Syntax)
`Lexer` tokenizes the query string, `ExtendedQueryParser` builds an AST, and `AstCompiler` converts it to SQL WHERE clauses. Supports operators: `=`, `^`, `$`, `!`, `'`, `|`, `( )`, `"..."`.

#### Scout Adapter
`FuzzySearchEngine` integrates with Laravel Scout, delegating to `Bm25Scorer` for BM25 ranking.

### Adding a New Search Algorithm

1. Create a new driver in `src/Drivers/`:

```php
<?php

namespace Ashiqfardus\LaravelFuzzySearch\Drivers;

use Illuminate\Database\Query\Builder;

class MyAlgorithmDriver extends BaseDriver
{
    public function apply(Builder $query, string $column, string $value, string $boolean = 'and'): Builder
    {
        // Implement your search logic
        return $query;
    }

    public function getRelevanceExpression(string $column, string $value): string
    {
        return "CASE WHEN {$column} = ? THEN 100 ELSE 0 END";
    }

    public function getRelevanceBindings(string $value): array
    {
        return [$value];
    }
}
```

2. Register in `FuzzySearch.php` in the `$registry` array:

```php
protected array $registry = [
    // ... existing entries ...
    'myalgorithm' => Drivers\MyAlgorithmDriver::class,
];
```

3. Add configuration defaults in `config/fuzzy-search.php` if needed

4. Write tests in `tests/Unit/` and `tests/Feature/`

5. Update documentation

### Database Compatibility

When adding features, ensure compatibility with:
- MySQL 5.7+
- PostgreSQL 9.6+
- SQLite 3.x
- SQL Server 2016+

Use the `getDriver()` method to handle database-specific logic:

```php
$driver = $query->getConnection()->getDriverName();

switch ($driver) {
    case 'mysql':
        // MySQL-specific code
        break;
    case 'pgsql':
        // PostgreSQL-specific code
        break;
    // ...
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

#### Unit Tests

Test individual components in isolation:

```php
// tests/Unit/MyFeatureTest.php
namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

class MyFeatureTest extends TestCase
{
    /** @test */
    public function it_does_something_correctly()
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
use Ashiqfardus\LaravelFuzzySearch\Tests\TestModels\User;

class SearchTest extends TestCase
{
    /** @test */
    public function it_searches_with_typo_tolerance()
    {
        User::create(['name' => 'John Doe']);
        
        $results = User::search('jonh')->get();
        
        $this->assertCount(1, $results);
        $this->assertEquals('John Doe', $results->first()->name);
    }
}
```

### Testing Different Databases

Test against multiple databases when adding database-specific features:

```php
public function test_works_on_mysql()
{
    $this->app['config']->set('database.default', 'mysql');
    // Test code
}

public function test_works_on_postgresql()
{
    $this->app['config']->set('database.default', 'pgsql');
    // Test code
}
```

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

# Run benchmarks
composer benchmark
```

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

## Questions?

- 📖 Check the [documentation](docs/)
- 💬 Join [discussions](https://github.com/ashiqfardus/laravel-fuzzy-search/discussions)
- ❓ [Ask a question](https://github.com/ashiqfardus/laravel-fuzzy-search/issues/new?template=question.yml)

## Recognition

All contributors are listed in the [CHANGELOG](CHANGELOG.md) and repository contributors page.

Thank you for contributing! 🙏

