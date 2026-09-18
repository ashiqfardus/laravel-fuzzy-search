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

`ignoreStopWords('xx')` reads `stop_words.{xx}` from config first, and only falls back to the builder's smaller built-in en/de/fr/es lists when that key isn't configured — pass an array (`ignoreStopWords([...])`) when you want a list that ignores config entirely. The bare `->ignoreStopWords()` shown above is unaffected by this change — it always uses the built-in English list regardless of config; call `->ignoreStopWords('en')` explicitly to get the configured list.

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

### Language / Locale Awareness

```php
User::search('john')
    ->locale('en')      // English
    ->get();

User::search('münchen')
    ->locale('de')      // German - handles umlauts
    ->get();
```

### Unicode & Accent Insensitivity

```php
// Matches "café", "cafe", "Café"
User::search('cafe')
    ->accentInsensitive()
    ->get();

// Matches "naïve", "naive"
User::search('naive')
    ->unicodeNormalize()
    ->get();
```

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

With an n-gram tokenizer `suggest()` completes only prefixes of up to `n` characters (the dictionary holds n-grams, so `東` completes to `東京` but `東京タ` finds nothing) — call `suggestFrom('table')` on such models when you need longer completions.

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

Any key you omit falls back to the global config. `stemmer_language` is passed to the stemmer's constructor (`new PorterStemmer('French')`) — see "Stemming (Optional)" below for the full list of Snowball languages. It only means something to a stemmer whose constructor accepts one; naming it on a stemmer that takes none (`NullStemmer`) throws `InvalidArgumentException` instead of silently ignoring it. `locale` picks the stop-word list from `config('fuzzy-search.stop_words')` for this model's index pipeline — it's independent of the query builder's `->locale()`.

The resolved pipeline is cached per model class for the lifetime of the request/worker; nothing in a running process needs to call `IndexManager::resetPipelineCache()` yourself unless you swap `$searchable` at runtime (tests that do this between cases should call it). Query-time processing — typo expansion and `suggest()` — follows the same per-model pipeline automatically, and the Scout engine passes its model too. `didYouMean()` is a separate, unscoped lookup: it queries the whole cross-model dictionary on the raw search term without running it through any model's tokenizer, stemmer or stop-word list. Rebuild after changing any of these keys, same as the global tokenizer/stemmer:

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

The LIKE path's `->accentInsensitive()` (see *Unicode & Accent Insensitivity* above) folds with the exact same `Accents::fold()`, so turning both on gives one consistent behaviour across paths. `suggest()` also folds the typed prefix, but only when the model's index pipeline itself folds.

### Stemming (Optional)

Default: no stemming (`NullStemmer`). With `NullStemmer`, `running` only matches `running`, not `run` or `ran`.

To enable Porter stemming:

```bash
composer require wamania/php-stemmer
```

```php
// config/fuzzy-search.php
'indexing' => [
    'stemmer' => \Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer::class,
],
```

Supported languages: English, French, German, Spanish, Italian, Russian, Romanian, Dutch, Portuguese, Swedish, Danish, Norwegian. You must rebuild the index after changing the stemmer.

