# Text Processing and Tokenization

[← Back to the README](../README.md)

---

## Text Processing

### Stop-Word Filtering

```php
// Use default stop words
User::search('the quick brown fox')
    ->ignoreStopWords()
    ->get();

// Custom stop words
User::search('the quick brown fox')
    ->ignoreStopWords(['the', 'a', 'an', 'and', 'or', 'but'])
    ->get();

// Language-specific stop words
User::search('der schnelle braune fuchs')
    ->ignoreStopWords('de')  // German stop words
    ->get();
```

Built-in lists cover eight locales — `en`, `de`, `fr`, `es`, `it`, `pt`, `nl`, `ru` — configured under `stop_words` in `config/fuzzy-search.php`. Any entry may instead be an absolute path to a text file, one word per line (`#` starts a comment, blank lines are ignored); a missing file throws `InvalidArgumentException` naming the path:

```php
// config/fuzzy-search.php
'stop_words' => [
    'en' => storage_path('fuzzy-search/stop-words-en.txt'),
],
```

`ignoreStopWords('xx')` reads `stop_words.{xx}` from config first, and only falls back to the builder's built-in en/de/fr/es lists (longer than the shipped config's: 36 English words against 14) when that key isn't configured — pass an array (`ignoreStopWords([...])`) when you want a list that ignores config entirely. The bare `->ignoreStopWords()` shown above is unaffected by this change — it always uses the built-in English list regardless of config; call `->ignoreStopWords('en')` explicitly to get the configured list. A term made only of stop words (`search('the')`) matches nothing: no rows and a total of 0, on the LIKE path as on the index. An `extended()`/`searchBoolean()` query drops no stop words, so there it is still searched.

### Synonym Support

```php
User::search('laptop')
    ->withSynonyms([
        'laptop' => ['notebook', 'computer', 'macbook'],
        'phone' => ['mobile', 'cell', 'smartphone'],
    ])
    ->get();

// Or use synonym groups
User::search('laptop')
    ->synonymGroup(['laptop', 'notebook', 'computer'])
    ->get();
```

Synonyms for every search go in the `synonyms` config key (word => its synonyms; case is ignored, non-ASCII letters included). Every `SearchBuilder` starts from them, as if `withSynonyms()` were called first; a model's `$searchable['synonyms']` and a query's `withSynonyms()` are merged on top, and a word they also set takes their synonyms. An `extended()`/`searchBoolean()` query applies no synonyms and drops no stop words (see [the extended syntax](extended-syntax.md#synonyms-and-stop-words)). The Scout engine, `FuzzySearch::on()` and the query-builder macros apply no synonyms.

### Language / Locale Awareness

A locale selects a stop-word list — nothing else. Pass it where it is read: `ignoreStopWords()`
at query time, `$searchable['locale']` for a model's index pipeline (see "Per-Model Pipelines"
below).

```php
// Query time: drop German stop words from this search
User::search('der schnelle fuchs')
    ->ignoreStopWords('de')
    ->get();
```

```php
// Index time: this model's postings are built with the French list
class Article extends Model
{
    use Searchable;

    protected $searchable = [
        'columns' => ['title' => 10, 'body' => 5],
        'locale'  => 'fr',
    ];
}
```

Umlauts, accents and other marks are handled per character by every algorithm regardless of
locale — see "Unicode & Accent Insensitivity" below. `SearchBuilder::locale()` never selected a
list or a collation; it is deprecated since v2.1.0, does nothing, and is removed in v3.0.0.

### Unicode & Accent Insensitivity

```php
// Matches "Zoë Müller" and "Muller": the accent-free form is searched beside the typed one
User::search('Müller')
    ->accentInsensitive()   // or rely on unicode.accent_insensitive, on by default
    ->get();

// A decomposed "naïve" (i followed by U+0308, as some keyboards and macOS produce) is
// NFC-normalised before the search, so it matches a stored, composed "naïve"
User::search("nai\u{0308}ve")
    ->unicodeNormalize()    // needs ext-intl; a no-op without it
    ->get();
```

With `unicode.accent_insensitive` on (the shipped default), every `Model::search()` (and any other `SearchBuilder`) also looks for the term's accent-free form, beside the term as typed: `Müller` finds both `Zoë Müller` and `Muller`. The `whereFuzzy`-style macros, the `Fuzzy` scopes, `tableSearch()` and `FuzzySearch::on()` search only the term as typed, and so does `FederatedSearch` for a model without the `Searchable` trait. The folded form works like a synonym. It is OR'd into the LIKE conditions of every algorithm and into `extended()` terms (where `!Müller` excludes both forms), and it counts for relevance and highlighting. A term with no accents compiles to exactly the SQL it would without the setting. `->accentInsensitive()` does the same for a single query. On PostgreSQL with `use_native_functions=true` it also ORs `unaccent(column) ILIKE unaccent(term)` beside the algorithm, so the column is folded too; that needs `CREATE EXTENSION unaccent`, and without it such a search fails with `function unaccent(…) does not exist`. `$searchable['accent_insensitive']` and a preset's `accent_insensitive` opt in the same way. The global key alone never runs `unaccent()`.

Folding the term never folds the column. An unaccented `cafe` finds `Café` as a substring match (`simple`/`like`) only where the database folds the column: under an accent-insensitive collation on MySQL/MariaDB (`utf8mb4_unicode_ci`, `utf8mb4_0900_ai_ci`), or on PostgreSQL through the explicit `accentInsensitive()` with unaccent and `use_native_functions`. SQLite, and PostgreSQL without native functions, cannot fold the column side; SQL Server follows the column's collation. The typo-tolerant algorithms may still reach `Café` from `cafe` as a one-letter typo.

Search terms are handled per character, not per byte, so Bengali, Hindi, Thai and accented Latin work with every algorithm, and the BM25 tokenizer keeps combining marks (vowel signs, virama, tone marks) attached to their letters. If you indexed such text with a release before 2.1.0, rebuild once with `fuzzy-search:rebuild "App\Models\Product" --fresh`.

---

### Tokenizers

The index splits each column's text into tokens before storing it. The default, `WhitespaceTokenizer`, splits on anything that isn't a letter, mark or digit and drops single-character tokens — it works for Latin, Cyrillic, Greek, Bengali, Hindi and every other script that separates words with spaces. Thai does not: a Thai phrase stays one token under the whitespace rule (and under `ScriptAwareTokenizer`, which only n-grams CJK), so use `NgramTokenizer` for Thai-only columns.

Chinese, Japanese and Korean don't use spaces between words, so `WhitespaceTokenizer` keeps a whole CJK run as one token — searching for part of it won't match. Two opt-in tokenizers cut character n-grams instead:

- **`NgramTokenizer(int $n = 2)`** — cuts every run of letters/marks/digits into `n`-character windows (a run of `n` characters or fewer is kept whole). It n-grams *everything*, Latin included — pick it only when a column is entirely CJK.
- **`ScriptAwareTokenizer(int $n = 2)`** — n-grams only the Han/Hiragana/Katakana/Hangul runs and applies the whitespace rule to everything else, so mixed text tokenizes correctly in one pass:

  ```php
  (new \Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer())->tokenize('Tokyo 東京 tower');
  // ['tokyo', '東京', 'tower'] — WhitespaceTokenizer would keep a longer CJK run
  // (e.g. "東京都心タワー") as a single token instead of overlapping bigrams.
  ```

  Pick this whenever a column can contain both CJK and non-CJK text.

Both tokenizers cut windows per Unicode code point, not per grapheme: on scripts that write accents as combining marks (decomposed Latin, Vietnamese, Indic, Thai) a window can separate a base letter from its mark. `ScriptAwareTokenizer` sidesteps this outside CJK runs by falling back to the whitespace rule, which keeps marks attached.

With an n-gram tokenizer the dictionary holds CJK text as `n`-character fragments, so dictionary completions cannot extend a CJK prefix: `suggest()` needs at least two characters, and with the default `n = 2` a two-character prefix such as `東京` matches only the fragment `東京` itself, which `suggest()` never offers back. Call `suggestFrom('table')` on such models; it completes from the stored values:

```php
// Rows "東京タワー" and "東京都庁", indexed with NgramTokenizer
Place::search('東京')->suggest(5);                        // []
Place::search('東京')->suggestFrom('table')->suggest(5);  // ['東京都庁', '東京タワー']
```

Enable a tokenizer globally, or per model (see **Per-Model Pipelines** below):

```php
// config/fuzzy-search.php
'indexing' => [
    'tokenizer' => \Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer::class,
],
```

Either way, rebuild after changing it — existing postings were tokenized the old way:

```bash
php artisan fuzzy-search:rebuild "App\Models\Product" --fresh
```

On the index path, `highlight()` marks whatever the query actually matched (see [the BM25 guide](bm25.md#typo-tolerance-as-you-type-synonyms-and-stop-words-on-the-index)); for a CJK term tokenized into n-grams, that is the matching n-gram fragment, which may be shorter than the whole word.

### Per-Model Pipelines

Every model shares the global `indexing.*` config by default. Override the tokenizer, stemmer (and its language) or stop-word locale for one model with `$searchable`:

```php
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer;

class Product extends Model
{
    use Searchable;

    protected array $searchable = [
        'columns'          => ['name' => 10, 'description' => 5],
        'tokenizer'        => ScriptAwareTokenizer::class,
        'stemmer'          => PorterStemmer::class,
        'stemmer_language' => 'French',
        'locale'           => 'fr',
    ];
}
```

Any key you omit falls back to the global config. `stemmer_language` is passed to the stemmer's constructor (`new PorterStemmer('French')`) — see "Stemming (Optional)" below for the full list of Snowball languages. It only means something to a stemmer whose constructor accepts one; naming it on a stemmer that takes none (`NullStemmer`) throws `InvalidArgumentException` instead of silently ignoring it. `locale` picks the stop-word list from `config('fuzzy-search.stop_words')` for this model's index pipeline — it is the only place a locale changes what is indexed (the builder's deprecated `->locale()` never did anything).

The resolved pipeline is cached per model class for the lifetime of the request/worker; nothing in a running process needs to call `IndexManager::resetPipelineCache()` yourself unless you swap `$searchable` at runtime (tests that do this between cases should call it). Query-time processing — typo expansion and `suggest()` — follows the same per-model pipeline automatically, and the Scout engine passes its model too. `didYouMean()` is a separate lookup, scoped to the searched model's postings: it queries that model's terms in the dictionary on the raw search term, without running it through any model's tokenizer, stemmer or stop-word list. Rebuild after changing any of these keys, same as the global tokenizer/stemmer:

```bash
php artisan fuzzy-search:rebuild "App\Models\Product" --fresh
```

### Accent Folding on the Index

Off by default. Turn it on to fold accents at both index time and query time on the BM25 path, so `café` and `cafe` land in the same dictionary term:

```php
// config/fuzzy-search.php
'indexing' => [
    'accent_insensitive' => true,
],
```

With `ext-intl` installed, folding decomposes the string (`Normalizer::FORM_D`), strips the combining-diacritical-mark blocks, then recomposes (`FORM_C`) — that handles most accented Latin/Greek/Cyrillic — before a built-in map runs for characters a decomposition doesn't cover (`ß` → `ss`, `ø` → `o`). Without `ext-intl`, only the map runs, which is the same coverage v2.0 had. This is a global setting — it applies to every model's index, there's no per-model override.

Rebuild after flipping it, same as the tokenizer and stemmer:

```bash
php artisan fuzzy-search:rebuild "App\Models\Product" --fresh
```

The LIKE path's accent handling (the `unicode.accent_insensitive` default and `->accentInsensitive()`, see *Unicode & Accent Insensitivity* above) folds with the exact same `Accents::fold()`, adding the folded term beside the typed one. The index also folds the stored text, while the LIKE path never folds the column, so with both on `cafe` finds `Café` on the index path but not on the LIKE path on SQLite, or on PostgreSQL without native functions (see above). `suggest()` also folds the typed prefix, but only when the model's index pipeline itself folds.

### Stemming (Optional)

Default: no stemming (`NullStemmer`). With `NullStemmer`, `running` only matches `running`, not `run` or `ran`.

To enable Porter stemming, install the 1.x line of `wamania/php-stemmer`, 1.3 or later (1.2 is a fatal error on PHP 8; a bare `composer require wamania/php-stemmer` installs 4.x, whose classes `PorterStemmer` cannot load):

```bash
composer require "wamania/php-stemmer:^1.3"
```

```php
// config/fuzzy-search.php
'indexing' => [
    'stemmer' => \Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer::class,
],
```

Supported languages: English, French, German, Spanish, Italian, Russian, Romanian, Dutch, Portuguese, Swedish, Danish, Norwegian. You must rebuild the index after changing the stemmer.

