# Comparison with Similar Packages

How Laravel Fuzzy Search compares to other search solutions.

## Table of Contents

- [Quick Comparison](#quick-comparison)
- [Other Laravel Fuzzy Search Packages](#vs-other-laravel-fuzzy-search-packages)
- [Laravel Scout](#vs-laravel-scout)
- [TNTSearch](#vs-tntsearch)
- [Meilisearch](#vs-meilisearch)
- [Algolia](#vs-algolia)
- [Elasticsearch](#vs-elasticsearch)
- [Which Should You Choose?](#which-should-you-choose)

## Quick Comparison

| Feature | Laravel Fuzzy Search | Laravel Scout | TNTSearch | Meilisearch | Algolia | Elasticsearch |
|---------|---------------------|---------------|-----------|-------------|---------|---------------|
| **Setup Time** | ⚡ 2 min | ⚡ 5 min | ⚡ 10 min | ⏱️ 30 min | ⏱️ 20 min | ⏱️ 60 min |
| **External Dependencies** | ✅ None | ❌ Driver-specific | ✅ None | ❌ Requires service | ❌ Requires service | ❌ Requires server |
| **Cost** | ✅ Free | 💰 Varies | ✅ Free | 💰 Paid plans | 💰 Paid | 💰 Infrastructure |
| **Database Integration** | ✅ Native | ⚠️ Via drivers | ⚠️ Separate index | ❌ Separate | ❌ Separate | ❌ Separate |
| **Typo Tolerance** | ✅ Built-in | ⚠️ Driver-specific | ⚠️ Limited | ✅ Excellent | ✅ Excellent | ✅ Good |
| **Real-time Updates** | ✅ Instant | ⚠️ Queue delay | ⚠️ Requires index | ✅ Fast | ✅ Fast | ⚠️ Near real-time |
| **Best For** | Small-Large apps | Any size | Medium apps | Medium-Large | Large apps | Enterprise |
| **Scalability** | ✅ Up to 10M rows (optimized) | ✅ Millions | ⚠️ Up to 1M | ✅ Millions | ✅ Millions | ✅ Billions |
| **Privacy** | ✅ Data stays local | ⚠️ Depends on driver | ✅ Local | ❌ External service | ❌ External service | ⚠️ Self-hosted |
| **Learning Curve** | ⚡ Easy | ⚡ Easy | ⏱️ Medium | ⏱️ Medium | ⏱️ Medium | ⏱️ Steep |

## Vs. Other Laravel Fuzzy Search Packages

There are a few other "fuzzy search" packages for Laravel. Here's how we compare:

### Comparison Table

| Feature | **ashiqfardus/laravel-fuzzy-search** | soliyer/laravel-fuzzy-search | castellanos/laravel-fuzzy-search |
|---------|:-----------------------------------:|:----------------------------:|:--------------------------------:|
| **Multiple Algorithms** | ✅ 8 algorithms (fuzzy, levenshtein, soundex, trigram, simple, like, similar_text, metaphone) | ⚠️ Limited | ⚠️ Limited |
| **Zero-Config Setup** | ✅ Auto-detects columns | ❌ Manual config | ❌ Manual config |
| **Fluent API** | ✅ Full fluent chain | ⚠️ Basic | ⚠️ Basic |
| **Field Weighting** | ✅ Customizable weights | ❌ No | ❌ No |
| **Typo Tolerance** | ✅ Configurable (0-5) | ⚠️ Fixed | ⚠️ Fixed |
| **Relevance Scoring** | ✅ With `_score` attribute | ❌ No | ❌ No |
| **Highlighting** | ✅ Custom tags | ❌ No | ❌ No |
| **Stop Words** | ✅ Multi-language (en, de, fr, es) | ❌ No | ❌ No |
| **Synonyms** | ✅ Groups & mappings | ❌ No | ❌ No |
| **Accent Insensitive** | ✅ Unicode support | ❌ No | ❌ No |
| **Cache Support** | ✅ Redis/cache | ❌ No | ❌ No |
| **Search Index** | ✅ Optional indexing | ❌ No | ❌ No |
| **Queue Support** | ✅ Async indexing | ❌ No | ❌ No |
| **Multi-Model Search** | ✅ FederatedSearch | ❌ No | ❌ No |
| **Autocomplete** | ✅ `suggest()` method | ❌ No | ❌ No |
| **Spell Correction** | ✅ `didYouMean()` | ❌ No | ❌ No |
| **Debug Mode** | ✅ Score explanation | ❌ No | ❌ No |
| **CLI Tools** | ✅ index, benchmark, explain | ❌ No | ❌ No |
| **Config Presets** | ✅ blog, ecommerce, users, etc. | ❌ No | ❌ No |
| **Pagination** | ✅ Offset, cursor, simple | ⚠️ Basic | ⚠️ Basic |
| **Fallback Strategy** | ✅ Multiple fallbacks | ❌ No | ❌ No |
| **Exception Handling** | ✅ Custom exceptions with context | ❌ Basic | ❌ Basic |
| **Test Coverage** | ✅ 171+ tests | ⚠️ Unknown | ⚠️ Unknown |
| **Documentation** | ✅ Comprehensive | ⚠️ Basic | ⚠️ Basic |
| **Active Development** | ✅ Yes (2026) | ⚠️ Unknown | ⚠️ Unknown |
| **Laravel 13 Support** | ✅ Yes | ⚠️ Unknown | ⚠️ Unknown |

### Why Choose This Package?

**1. Feature-Rich**: This is the most comprehensive fuzzy search package for Laravel, with features typically found only in external services.

**2. Zero-Config**: Just add the trait and search. No configuration required to get started.

**3. Production-Ready**: 171+ tests, proper exception handling, and extensive documentation.

**4. Modern PHP**: Built for PHP 8.1+ with type hints, enums, fibers-friendly, and modern patterns.

**5. Actively Maintained**: Regular updates, Laravel 13 support, and responsive issue handling.

### Example Comparison

**With this package:**
```php
// Zero config, full features
$results = User::search('jonh')  // typo
    ->searchIn(['name' => 10, 'email' => 5])
    ->typoTolerance(2)
    ->accentInsensitive()
    ->withSynonyms(['john' => ['jon', 'johnny']])
    ->highlight('mark')
    ->withRelevance()
    ->cache(60)
    ->paginate(15);
```

**With other packages:**
```php
// Typically just basic LIKE search
$results = User::whereFuzzy('name', 'john')->get();
```


## Vs. Laravel Scout

[Laravel Scout](https://laravel.com/docs/scout) is Laravel's official full-text search package.

### Similarities

- ✅ Fluent Laravel API
- ✅ Eloquent integration
- ✅ Trait-based setup
- ✅ Pagination support

### Laravel Fuzzy Search Advantages

| Feature | Laravel Fuzzy Search | Laravel Scout |
|---------|---------------------|---------------|
| **Zero Setup** | ✅ Works immediately | ❌ Requires driver installation |
| **Database-Native** | ✅ Uses your existing DB | ❌ Requires external service/index |
| **Real-time** | ✅ Instant updates | ⚠️ Queue delay |
| **Privacy** | ✅ Data in your database | ⚠️ Depends on driver (Algolia, Meilisearch) |
| **Cost** | ✅ Free | 💰 Most drivers are paid services |
| **Multiple Algorithms** | ✅ 8 algorithms built-in | ❌ Single algorithm per driver |
| **Query Builder Support** | ✅ Works with Query Builder | ❌ Eloquent only |

### Laravel Scout Advantages

| Feature | Scout | Laravel Fuzzy Search |
|---------|-------|---------------------|
| **Scalability** | ✅ Millions of records | ✅ Up to 10M (optimized) |
| **Advanced Features** | ✅ Faceting, filters, geo-search | ⚠️ Basic features |
| **Ecosystem** | ✅ Many drivers available | ⚠️ Database-only |

### Example Comparison

**Laravel Fuzzy Search:**
```php
// Immediate use after composer install
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

class Article extends Model
{
    use Searchable;
}

$results = Article::search('laravel')->get();
```

**Laravel Scout:**
```php
// Requires driver installation & configuration
// composer require laravel/scout
// composer require algolia/algoliasearch-client-php
// configure .env with Algolia credentials

use Laravel\Scout\Searchable;

class Article extends Model
{
    use Searchable;
}

// Requires indexing
php artisan scout:import "App\Models\Article"

$results = Article::search('laravel')->get();
```

### When to Use Each

**Use Laravel Fuzzy Search if:**
- ✅ You want zero external dependencies
- ✅ You have < 10M records (with optimization)
- ✅ You need instant, real-time updates
- ✅ Privacy is important (data stays in your DB)
- ✅ Budget is limited (no service costs)
- ✅ You want multiple search algorithms
- ✅ You need Query Builder support

**Use Laravel Scout if:**
- ✅ You have > 10M records
- ✅ You need advanced features (faceting, geo-search)
- ✅ You're willing to use external services
- ✅ You need extreme scalability
- ✅ You want best-in-class typo tolerance

## Vs. TNTSearch

[TNTSearch](https://github.com/teamtnt/tntsearch) is a PHP full-text search engine.

### Similarities

- ✅ No external services required
- ✅ Works with Laravel
- ✅ Good for small-medium datasets

### Laravel Fuzzy Search Advantages

| Feature | Laravel Fuzzy Search | TNTSearch |
|---------|---------------------|-----------|
| **Setup** | ⚡ Zero config | ⏱️ Requires configuration & indexing |
| **Real-time** | ✅ Instant updates | ❌ Requires manual reindexing |
| **Database Native** | ✅ Direct DB queries | ❌ Separate SQLite index |
| **Multiple Algorithms** | ✅ 8 algorithms | ⚠️ 1 algorithm |
| **Query Builder** | ✅ Supported | ❌ Eloquent only |

### TNTSearch Advantages

| Feature | TNTSearch | Laravel Fuzzy Search |
|---------|-----------|---------------------|
| **Speed** | ✅ Very fast with index | ⚠️ Slower on large datasets |
| **Stemming** | ✅ Built-in | ❌ Not available |
| **BM25 Ranking** | ✅ Available | ⚠️ Custom scoring only |

### When to Use Each

**Use Laravel Fuzzy Search if:**
- ✅ You want zero setup
- ✅ You need real-time updates
- ✅ You want to avoid maintaining a separate index
- ✅ You need multiple algorithms

**Use TNTSearch if:**
- ✅ Speed is critical and you can maintain an index
- ✅ You need advanced stemming
- ✅ You're okay with manual reindexing

## Vs. Meilisearch

[Meilisearch](https://www.meilisearch.com/) is a modern, fast search engine.

### Similarities

- ✅ Excellent typo tolerance
- ✅ Fast search results
- ✅ Laravel integration available

### Laravel Fuzzy Search Advantages

| Feature | Laravel Fuzzy Search | Meilisearch |
|---------|---------------------|-------------|
| **Setup** | ⚡ 2 minutes | ⏱️ 30+ minutes (server setup) |
| **Infrastructure** | ✅ None needed | ❌ Requires server/service |
| **Cost** | ✅ Free | 💰 Paid for hosting/cloud |
| **Privacy** | ✅ Data in your DB | ❌ External service |
| **Real-time** | ✅ Instant | ⚠️ Near real-time |
| **Maintenance** | ✅ None | ⚠️ Server maintenance |

### Meilisearch Advantages

| Feature | Meilisearch | Laravel Fuzzy Search |
|---------|-------------|---------------------|
| **Scalability** | ✅ Millions of records | ✅ Up to 10M (optimized) |
| **Speed** | ✅ Extremely fast | ✅ Good (with caching) |
| **Typo Tolerance** | ✅ Best-in-class | ✅ Good |
| **Faceting** | ✅ Advanced | ❌ Basic |
| **Relevance** | ✅ Excellent | ✅ Good |

### When to Use Each

**Use Laravel Fuzzy Search if:**
- ✅ You want no infrastructure overhead
- ✅ Dataset < 10M records
- ✅ Budget-conscious
- ✅ Privacy-sensitive data
- ✅ Quick setup is priority

**Use Meilisearch if:**
- ✅ You have > 10M records
- ✅ Speed is critical
- ✅ You need advanced faceting
- ✅ You can manage infrastructure
- ✅ Budget allows for hosting

## Vs. Algolia

[Algolia](https://www.algolia.com/) is a premium hosted search service.

### Similarities

- ✅ Excellent typo tolerance
- ✅ Fast results
- ✅ Laravel integration

### Laravel Fuzzy Search Advantages

| Feature | Laravel Fuzzy Search | Algolia |
|---------|---------------------|---------|
| **Cost** | ✅ Free | 💰 Expensive ($1/month minimum, scales up) |
| **Privacy** | ✅ Data in your DB | ❌ Data stored externally |
| **Setup** | ⚡ 2 minutes | ⏱️ 20+ minutes |
| **Control** | ✅ Full control | ⚠️ Limited by API |
| **Vendor Lock-in** | ✅ None | ❌ Proprietary |

### Algolia Advantages

| Feature | Algolia | Laravel Fuzzy Search |
|---------|---------|---------------------|
| **Scalability** | ✅ Unlimited | ⚠️ Limited to DB |
| **Speed** | ✅ Extremely fast globally | ⚠️ DB-dependent |
| **Features** | ✅ Advanced (geo, facets, personalization) | ⚠️ Basic |
| **Analytics** | ✅ Built-in | ❌ DIY |
| **Global CDN** | ✅ Yes | ❌ No |

### Cost Comparison

**Algolia Pricing (approx):**
- Free: 10K searches/month
- Essential: $1/month + $0.50 per 1K searches
- Premium: $349/month+

**Laravel Fuzzy Search:**
- ✅ Free forever
- Only cost: Your existing database server

### When to Use Each

**Use Laravel Fuzzy Search if:**
- ✅ Budget < $100/month for search
- ✅ Dataset < 10M records
- ✅ Privacy requirements
- ✅ Don't need global CDN
- ✅ Basic to advanced search is sufficient

**Use Algolia if:**
- ✅ Budget > $500/month
- ✅ Need global low-latency
- ✅ Enterprise-level features required
- ✅ Advanced analytics needed
- ✅ Dedicated support required

## Vs. Elasticsearch

[Elasticsearch](https://www.elastic.co/) is the industry-standard enterprise search engine.

### Similarities

- ✅ Full-text search
- ✅ Relevance scoring
- ✅ Scalable

### Laravel Fuzzy Search Advantages

| Feature | Laravel Fuzzy Search | Elasticsearch |
|---------|---------------------|---------------|
| **Setup** | ⚡ 2 minutes | ⏱️ Hours (cluster setup) |
| **Complexity** | ⚡ Simple | ⏱️ Very complex |
| **Infrastructure** | ✅ None | ❌ Requires dedicated servers |
| **Cost** | ✅ Free | 💰 Expensive (servers/cloud) |
| **Maintenance** | ✅ None | ⚠️ Significant |
| **Learning Curve** | ⚡ Easy | ⏱️ Steep |

### Elasticsearch Advantages

| Feature | Elasticsearch | Laravel Fuzzy Search |
|---------|---------------|---------------------|
| **Scalability** | ✅ Billions of records | ✅ Up to 10M (optimized) |
| **Features** | ✅ Most advanced | ✅ Good |
| **Analytics** | ✅ Kibana integration | ❌ None |
| **Distributed** | ✅ Cluster support | ❌ Single DB |
| **Aggregations** | ✅ Advanced | ⚠️ Basic |

### When to Use Each

**Use Laravel Fuzzy Search if:**
- ✅ Startup or small-large business
- ✅ Dataset < 10M records
- ✅ Simple to advanced search needs
- ✅ Limited DevOps resources
- ✅ Budget-conscious

**Use Elasticsearch if:**
- ✅ Enterprise-scale (> 10M records)
- ✅ Complex queries & aggregations
- ✅ Log analysis needs
- ✅ Dedicated search team
- ✅ High availability requirements

## Which Should You Choose?

### Decision Tree

```
Start Here
│
├─ Dataset size?
│  ├─ < 1M rows → Laravel Fuzzy Search ✅
│  ├─ 1M - 10M rows → Laravel Fuzzy Search (optimized) or Meilisearch
│  └─ > 10M rows → Meilisearch, Algolia, or Elasticsearch
│
├─ Budget?
│  ├─ $0/month → Laravel Fuzzy Search ✅ or TNTSearch
│  ├─ $0-$100/month → Meilisearch (self-hosted)
│  └─ $100+/month → Algolia or Elasticsearch Cloud
│
├─ Setup time available?
│  ├─ < 5 minutes → Laravel Fuzzy Search ✅
│  ├─ < 1 hour → Scout + Meilisearch
│  └─ > 1 hour → Elasticsearch
│
├─ Privacy requirements?
│  ├─ Data must stay in your DB → Laravel Fuzzy Search ✅ or TNTSearch
│  └─ External OK → Any solution
│
└─ Feature needs?
   ├─ Basic to advanced search → Laravel Fuzzy Search ✅
   ├─ Advanced faceting → Meilisearch or Algolia
   └─ Enterprise features → Elasticsearch
```

### Recommendation by Project Type

| Project Type | Recommended Solution | Why |
|-------------|---------------------|-----|
| **Personal Blog** | Laravel Fuzzy Search | Free, simple, sufficient |
| **Startup MVP** | Laravel Fuzzy Search | Fast setup, zero cost |
| **E-commerce (< 10K products)** | Laravel Fuzzy Search | Good enough, no overhead |
| **E-commerce (> 10K products)** | Meilisearch | Better performance, faceting |
| **SaaS (< 50K records/tenant)** | Laravel Fuzzy Search | Data isolation, privacy |
| **SaaS (> 50K records/tenant)** | Meilisearch or Algolia | Scalability |
| **Content Platform** | Meilisearch | Good balance |
| **Enterprise** | Elasticsearch | Features, scalability |
| **Global Consumer App** | Algolia | CDN, low latency |

## Migration Path

Start with Laravel Fuzzy Search, migrate later if needed:

### Stage 1: Laravel Fuzzy Search (MVP)
- ✅ Quick to implement
- ✅ Zero cost
- ✅ Learn what users actually search for

### Stage 2: Optimize (1M+ rows)
- Add caching (Redis)
- Add search index table
- Add database indexes (FULLTEXT, pg_trgm)
- Use partitioning for large tables

### Stage 3: Scale (5M+ rows)
- Materialized views
- Read replicas
- Tiered caching

### Stage 4: Migrate if Needed (10M+ rows)
When you hit limits:
- → Meilisearch (best balance, free)
- → Algolia (if budget allows)
- → Elasticsearch (if enterprise needs)

**Pro tip:** You can run Laravel Fuzzy Search alongside Scout, using Fuzzy Search for quick prototyping and Scout for production.

## Summary

| If you need... | Use |
|---------------|-----|
| Zero setup, zero cost | **Laravel Fuzzy Search** ✅ |
| Best value for money | **Laravel Fuzzy Search** or Meilisearch |
| Maximum privacy | **Laravel Fuzzy Search** ✅ |
| Up to 10M records | **Laravel Fuzzy Search** (optimized) ✅ |
| Best performance at scale (10M+) | Meilisearch or Algolia |
| Enterprise features | Elasticsearch |
| Global low-latency | Algolia |

**Bottom line:** Laravel Fuzzy Search is perfect for 90% of Laravel applications. With proper optimization, it handles millions of records. Start here, scale later if needed.

---

Questions? Check out our [Getting Started Guide](GETTING_STARTED.md).

