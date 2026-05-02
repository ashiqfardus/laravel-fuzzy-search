# Capability Matrix

> Last updated: v2.0.0-alpha.1 (Phase 0)

This table shows **exactly what each algorithm does at the SQL level** on each supported database.
"Native" = the database's own function. "Pattern fallback" = PHP generates LIKE patterns.

## Algorithm × Database

| Algorithm | MySQL 8 | MariaDB 10.6 | PostgreSQL 14 | SQLite | SQL Server |
|---|---|---|---|---|---|
| **simple** / **like** | `LIKE '%term%'` | `LIKE '%term%'` | `ILIKE '%term%'` | `LIKE '%term%'` | `LOWER() LIKE` |
| **fuzzy** | LIKE pattern set (typo patterns, transpositions) | LIKE pattern set | ILIKE pattern set | LIKE pattern set | LIKE pattern set |
| **levenshtein** | Native `LEVENSHTEIN()` UDF if `use_native_functions=true`, else pattern set | Pattern set | `similarity()` via pg_trgm if `use_native_functions=true`, else pattern set | Pattern set | Pattern set |
| **trigram** | LIKE pattern set | LIKE pattern set | Native `similarity()` via pg_trgm if `use_native_functions=true` | LIKE pattern set | LIKE pattern set |
| **soundex** | Native `SOUNDEX()` | Native `SOUNDEX()` | Native `SOUNDEX()` via fuzzystrmatch if `use_native_functions=true`, else prefix LIKE | Prefix LIKE fallback | Prefix LIKE fallback |
| **metaphone** | Shadow column `{col}_metaphone` + exact `=` match | Shadow column | Shadow column | Shadow column | Shadow column |
| **similar_text** | `LIKE '%term%'` (SQL); `similar_text()` scores in PHP after fetch | Same | `ILIKE '%term%'`; PHP scores | Same | Same |

## Notes

- **Native functions** require `'use_native_functions' => true` in `config/fuzzy-search.php` AND the relevant extension/UDF installed.
- **Levenshtein UDF (MySQL):** Not installed by default. See [this gist](https://gist.github.com/yohgaki/9315991) or your DB package manager.
- **pg_trgm (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS pg_trgm;`
- **fuzzystrmatch (PostgreSQL):** `CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;`
- **unaccent (PostgreSQL, for `accentInsensitive()`):** `CREATE EXTENSION IF NOT EXISTS unaccent;` + `use_native_functions=true`
- **MySQL accent insensitive:** Use `utf8mb4_unicode_ci` or `utf8mb4_0900_ai_ci` collation on the column.
- **Metaphone shadow column:** Run `php artisan fuzzy-search:add-shadow-column {Model} {column} --type=metaphone` then `php artisan migrate`.

## PHP-side scoring

Regardless of algorithm, after SQL candidates are fetched:

1. `similar_text()` and `levenshtein()` run in PHP on each candidate.
2. Results re-sorted by the combined PHP score (higher = better).
3. `limit/offset` applied on the PHP-sorted collection (not in SQL).

**Top-N results are always the most relevant N** from the candidate set (not just the first N SQL rows).
Candidate set size is controlled by `max_candidates` (default: 1000). Lower this value on large tables.

## Pagination note

`paginate()` and `simplePaginate()` use DB-level pagination and are not affected by the PHP rescore path. They score within the current page only. For globally-ranked pagination, use the inverted index (Phase 1).
