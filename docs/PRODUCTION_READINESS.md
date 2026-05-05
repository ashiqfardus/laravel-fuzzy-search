# Production Readiness Checklist

Tick each item before you ship an application using `ashiqfardus/laravel-fuzzy-search` v2 to production.

## Initial setup

- [ ] Run `php artisan migrate` — four BM25 index tables and two alpha.4 schema migrations applied automatically
- [ ] Publish config: `php artisan vendor:publish --tag=fuzzy-search-config`
- [ ] Review `config/fuzzy-search.php` — pay attention to `max_candidates` (default 1000) and `indexing.enabled` (default false)

## If using the BM25 inverted index

- [ ] Set `indexing.enabled = true` in config
- [ ] Configure a queue driver (Redis, SQS, database) — `indexing.async = true` (default) dispatches `IndexModelJob` after each model save; sync mode blocks the web request
- [ ] Run the initial build: `php artisan fuzzy-search:rebuild "App\Models\User"` (repeat for each searchable model)
- [ ] Schedule incremental re-index if your data changes via bulk imports that bypass Eloquent observers
- [ ] Check `php artisan fuzzy-search:status` — confirm `total_docs` matches your row count
- [ ] Set `bm25.max_postings_per_term` (default 50 000) — raise for large corpora with high-frequency terms

## Cache

- [ ] Set a unique `cache.prefix` in `config/cache.php` — prevents cross-app poisoning when two apps share a Redis instance
- [ ] Confirm your cache driver supports tags if you plan to invalidate search results on model writes (file driver does not support tags)

## Security

- [ ] `highlight()` tag argument — use the default `mark` or a safe alphanumeric tag; avoid passing raw user input as the tag name
- [ ] `searchIn()` column names should come from an application-side whitelist, not directly from `$request->input('columns')`
- [ ] Extended-search queries (`->extended($request->input('q'))`) are bounded by `query.max_tokens` (default 32) and `query.max_depth` (default 8) — verify these limits match your UI
- [ ] Rate-limit the search endpoint; BM25 queries with many tokens can be CPU-intensive on large corpora

## Metaphone shadow columns (if using `metaphone` algorithm)

- [ ] Run `php artisan fuzzy-search:add-shadow-column "App\Models\User" name` to generate the migration
- [ ] Run `php artisan migrate`
- [ ] Populate shadow columns on existing rows: `User::chunk(500, fn($users) => $users->each->touch())` — or run `php artisan fuzzy-search:rebuild` which populates them as a side-effect

## Testing

- [ ] Test suite uses `orchestra/testbench` with the package `TestCase` — the four index tables exist in tests automatically
- [ ] Factories or seeders populate the searchable columns your tests rely on
- [ ] Run `php artisan fuzzy-search:upgrade-v1` in CI to catch v1-era API regressions before production

## References

- [Inverted index guide](INVERTED_INDEX.md)
- [Scout driver guide](SCOUT_DRIVER.md)
- [v1 → v2 migration guide](UPGRADE_v1_TO_v2.md)
- [Security policy](../SECURITY.md)
