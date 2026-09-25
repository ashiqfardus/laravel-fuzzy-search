<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Fuzzy Search Algorithm
    |--------------------------------------------------------------------------
    |
    | Supported: "simple", "like", "fuzzy", "levenshtein", "soundex", "trigram", "metaphone", "similar_text"
    |
    | - "fuzzy": General purpose with typo tolerance (recommended)
    | - "levenshtein": Edit distance based, configurable tolerance
    | - "soundex": Phonetic matching for similar sounding words
    | - "trigram": N-gram similarity (best with PostgreSQL pg_trgm)
    | - "metaphone": Double-metaphone phonetic matching
    | - "similar_text": PHP similar_text() percentage similarity
    | - "simple" / "like": Basic LIKE matching (fastest, no typo tolerance)
    |
    */
    'default_algorithm' => 'fuzzy',

    /*
    |--------------------------------------------------------------------------
    | Candidate ceiling for PHP-side rescoring
    |--------------------------------------------------------------------------
    |
    | executeSearch() fetches up to this many rows from SQL before PHP rescoring
    | and slicing to the requested limit/offset. Higher = more accurate top-N
    | at the cost of fetching more rows. For indexed search (Phase 1), this
    | ceiling is replaced by BM25 scoring in SQL.
    |
    | Recommendation: lower to 200-500 on tables with 100k+ rows.
    |
    */
    'max_candidates' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Legacy dispatch fallback
    |--------------------------------------------------------------------------
    |
    | When true, unknown algorithm names silently fall back to LevenshteinDriver
    | (v1.x behavior). Set to false in production once all callers use valid
    | algorithm names.
    |
    */
    'legacy_dispatch' => false,

    /*
    |--------------------------------------------------------------------------
    | Allow Empty Search
    |--------------------------------------------------------------------------
    |
    | If true, empty search terms will return all results instead of throwing exception
    |
    */
    'allow_empty_search' => false,

    /*
    |--------------------------------------------------------------------------
    | Search Presets
    |--------------------------------------------------------------------------
    |
    | Predefined configurations for common use cases.
    | Use with: $model->search('term')->preset('blog')
    |
    */
    'presets' => [
        'blog' => [
            'columns' => ['title' => 10, 'body' => 5, 'excerpt' => 3],
            'algorithm' => 'fuzzy',
            'typo_tolerance' => 2,
            'stop_words_enabled' => true,
            'accent_insensitive' => true,
        ],
        'ecommerce' => [
            'columns' => ['name' => 10, 'description' => 5, 'sku' => 8, 'brand' => 6],
            'algorithm' => 'fuzzy',
            'typo_tolerance' => 1,
            'partial_match' => true,
            'stop_words_enabled' => false,
        ],
        'users' => [
            'columns' => ['name' => 10, 'email' => 8, 'username' => 9],
            'algorithm' => 'levenshtein',
            'typo_tolerance' => 2,
            'accent_insensitive' => true,
        ],
        'phonetic' => [
            'algorithm' => 'soundex',
            'typo_tolerance' => 0,
            'columns' => ['name' => 10],
        ],
        'exact' => [
            'algorithm' => 'simple',
            'typo_tolerance' => 0,
            'partial_match' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typo Tolerance
    |--------------------------------------------------------------------------
    */
    'typo_tolerance' => [
        'enabled' => true,
        'max_distance' => 2,
        'min_word_length' => 4,  // No typo tolerance for words shorter than this
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring Configuration
    |--------------------------------------------------------------------------
    |
    | Base points for the relevance scorer (SQL ordering and PHP rescoring
    | share these). A column's score is the matching tier × its searchIn()
    | weight; prefix is further multiplied by prefixBoost().
    |
    */
    'scoring' => [
        'exact_match' => 100,
        'prefix_match' => 80,
        'contains' => 60,
        'fuzzy_match' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stop Words
    |--------------------------------------------------------------------------
    |
    | Words to ignore during search (by locale)
    |
    | Each entry may also be an absolute path to a text file with one word per
    | line (`#` starts a comment).
    |
    */
    'stop_words' => [
        'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'it'],
        'de' => ['der', 'die', 'das', 'und', 'oder', 'aber', 'in', 'auf', 'an', 'zu', 'für', 'von'],
        'fr' => ['le', 'la', 'les', 'un', 'une', 'des', 'et', 'ou', 'mais', 'dans', 'sur', 'à'],
        'es' => ['el', 'la', 'los', 'las', 'un', 'una', 'y', 'o', 'pero', 'en', 'sobre', 'a'],
        'it' => ['il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'una', 'e', 'o', 'ma', 'in', 'su', 'per', 'di', 'a', 'da', 'che', 'non', 'con'],
        'pt' => ['o', 'a', 'os', 'as', 'um', 'uma', 'e', 'ou', 'mas', 'em', 'no', 'na', 'de', 'do', 'da', 'para', 'com', 'que', 'não', 'por'],
        'nl' => ['de', 'het', 'een', 'en', 'of', 'maar', 'in', 'op', 'aan', 'te', 'voor', 'van', 'is', 'dat', 'die', 'met', 'niet', 'zijn', 'er'],
        'ru' => ['и', 'в', 'не', 'на', 'я', 'что', 'он', 'с', 'а', 'как', 'это', 'по', 'но', 'из', 'у', 'за', 'от', 'то', 'же', 'к'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Synonyms
    |--------------------------------------------------------------------------
    |
    | Global synonym mappings
    |
    */
    'synonyms' => [
        // 'laptop' => ['notebook', 'computer'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexing Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the BM25 inverted index (fuzzy_index_* tables).
    |
    | Laravel merges package config one top-level key at a time, so if you override
    | 'indexing' in your own config file, copy the whole block — a partial override
    | drops the keys you leave out (the code falls back to these defaults).
    |
    */
    'indexing' => [
        'enabled'            => false,   // Set true to enable observer-based auto-indexing on model save/delete
        'async'              => true,
        'queue'              => 'default',
        'chunk_size'         => 500,
        'tokenizer'          => \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer::class,
        'stemmer'            => \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer::class,
        'max_tokens_per_doc' => 5000,  // Cap unique tokens per document to prevent index poisoning

        // Fold accents when indexing and when processing query terms for the BM25 index
        // (café and cafe become one dictionary term). Off by default: turning it on changes
        // the dictionary, so run `fuzzy-search:rebuild {Model} --fresh` afterwards.
        'accent_insensitive' => false,

        /*
         * Retry limits for IndexModelJob and RebuildIndexJob. Without these a failing
         * index write retries on the queue worker's defaults with no delay between attempts.
         */
        'job' => [
            'tries'   => 3,
            'backoff' => [10, 60, 300], // seconds before the 2nd, 3rd, ... attempt
            'timeout' => 120,           // seconds a single job may run
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | BM25 Ranking Parameters
    |--------------------------------------------------------------------------
    |
    | k1: term-frequency saturation (1.2–2.0; default 1.5)
    | b:  length normalisation (0–1; default 0.75)
    |
    */
    'bm25' => [
        'k1' => 1.5,
        'b'  => 0.75,
        /*
         * max_postings_per_term: SQL-side top-K cutoff shared by all the matched terms of a
         * query. The cap applies to (document, term) rows — the per-column postings are summed
         * in SQL first — ordered by weighted frequency DESC, so the highest-signal rows are
         * always retained and a document is never partially cut across its columns. For typical
         * corpora this cap is never reached; it exists purely to bound memory usage when a term
         * matches tens of thousands of documents.
         */
        'max_postings_per_term' => 50000,
        /*
         * candidate_chunk: when a BM25 search runs under Eloquent constraints (filters,
         * wheres, global scopes), ranked ids are checked against the database in chunks
         * of this size until the requested page is full. Smaller = less over-fetching
         * on selective filters; larger = fewer round trips.
         */
        'candidate_chunk' => 200,
        /*
         * fuzzy: typo-tolerant BM25. Each query term of at least
         * typo_tolerance.min_word_length characters is expanded with up to max_expansions
         * dictionary terms within typoTolerance() edits, closest first, chosen from the
         * candidate_pool most common terms of a similar length (a term outside that window is
         * never reached — raise the pool for catalogs of rare terms). With damping on, an
         * expansion contributes 1 - distance / length of what the exact term would; it always
         * counts for less, but BM25 weighs rarity (idf), so a rare expansion can still outscore
         * a common exact term.
         */
        'fuzzy' => [
            'candidate_pool' => 500,
            'max_expansions' => 5,
            'damping'        => true,
        ],
        /*
         * prefix: asYouType() expands the last query token with the most common dictionary
         * terms that start with it, capped at max_expansions.
         */
        'prefix' => [
            'max_expansions' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Extended-search query parser
    |--------------------------------------------------------------------------
    |
    | max_depth: maximum nesting depth of parentheses (DoS guard)
    | max_tokens: a query throws once it reaches this many tokens (words, | and
    |   parentheses each count), so 32 allows 31 (DoS guard)
    | max_term_length: maximum character length of a single search term; longer
    |   terms are truncated before driver pattern generation to prevent O(n²)
    |   LIKE-pattern explosion on LevenshteinDriver and FuzzyDriver
    |
    */
    'query' => [
        'max_depth'       => 16,
        'max_tokens'      => 32,
        'max_term_length' => 128,
    ],

    /*
    |--------------------------------------------------------------------------
    | In-memory search
    |--------------------------------------------------------------------------
    |
    | max_items: ceiling for FuzzySearch::on($collection) — prevents
    | accidentally loading gigabytes into PHP memory.
    |
    */
    'in_memory' => [
        'max_items'      => 10_000,
        'min_similarity' => 60,    // 0–100; similarity threshold for FuzzySearch::on() results
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => false,     // true: cache every get(), first() and simplePaginate() without ->cache()
        'driver' => 'default',  // Use default cache driver
        'ttl' => 3600,          // seconds (1 hour); ->cache($minutes) counts minutes
        'prefix' => 'fuzzy_search_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Analytics
    |--------------------------------------------------------------------------
    |
    | When enabled, every executed search attempt (get(), paginate(), in-memory) writes one row
    | to the analytics table through the FuzzySearchExecuted event — fallback() records one row
    | per algorithm tried and a federated search one per inner model; a cache hit and count()
    | record nothing. See "What counts as one row" in the README. Search terms are user input:
    | keep retention short, or set hash_terms to store only a keyed SHA-256 of the normalized
    | term (HMAC with APP_KEY, so the table alone cannot be brute-forced; rotating APP_KEY
    | splits history at the rotation).
    |
    | queue: null inserts inline; a queue name dispatches RecordSearchLogJob there instead.
    | sample_rate: 0.0–1.0 share of searches recorded.
    | retention_days: what `php artisan fuzzy-search:analytics:prune` deletes beyond.
    | table: the table the migration creates and the recorder writes; set it before
    |   `php artisan migrate`.
    |
    */
    'analytics' => [
        'enabled'        => false,
        'queue'          => null,
        'sample_rate'    => 1.0,
        'retention_days' => 30,
        'hash_terms'     => false,
        'table'          => 'fuzzy_search_logs',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Default cap for LIKE-pattern generation; override per query with
    | ->maxPatterns().
    |
    */
    'performance' => [
        'max_patterns' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Levenshtein Settings
    |--------------------------------------------------------------------------
    */
    'levenshtein' => [
        'max_distance' => 2,
        'cost_insert' => 1,
        'cost_replace' => 1,
        'cost_delete' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trigram Settings
    |--------------------------------------------------------------------------
    */
    'trigram' => [
        'min_similarity' => 30,  // 0-100
    ],

    /*
    |--------------------------------------------------------------------------
    | Similar Text Settings
    |--------------------------------------------------------------------------
    |
    | min_percentage: the least similar_text() percentage a match may have. The
    | term is contained in every match, so this is a length bound: a column
    | value may be at most t·(200 − p) / p characters for a t-character term
    | (~1.86t at 70). 0 turns it off, the 2.0 behaviour. The min_percentage
    | option of a single call overrides it.
    |
    */
    'similar_text' => [
        'min_percentage' => 70,
    ],

    /*
    |--------------------------------------------------------------------------
    | Highlighting Settings
    |--------------------------------------------------------------------------
    |
    | Defaults for ->highlight(); set enabled=true to highlight every search
    | without calling highlight().
    |
    */
    'highlighting' => [
        'enabled' => false,
        'tag_open' => '<em>',
        'tag_close' => '</em>',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Native Functions
    |--------------------------------------------------------------------------
    |
    | Enable if you have native database extensions installed:
    | - MySQL: LEVENSHTEIN UDF
    | - PostgreSQL: pg_trgm, fuzzystrmatch extensions
    |
    */
    'use_native_functions' => false,

    /*
    |--------------------------------------------------------------------------
    | Minimum Search Length
    |--------------------------------------------------------------------------
    */
    'min_search_length' => 2,

    /*
    |--------------------------------------------------------------------------
    | Default Locale
    |--------------------------------------------------------------------------
    */
    'locale' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Unicode & Accent Handling
    |--------------------------------------------------------------------------
    |
    | normalize: NFC-normalise search terms (requires ext-intl); opt-in — off
    | by default so an untouched config keeps v2.0 behaviour. Enable per query
    | with ->unicodeNormalize(), or flip this to true to enable it globally.
    | accent_insensitive: also search the accent-folded form of a term by default,
    | beside the typed form ("Müller" finds "Zoë Müller" and "Muller"). It never
    | runs PostgreSQL's unaccent(): that needs an explicit ->accentInsensitive(),
    | $searchable['accent_insensitive'] or a preset, use_native_functions, and
    | the unaccent extension (CREATE EXTENSION unaccent).
    |
    */
    'unicode' => [
        'normalize' => false,
        'accent_insensitive' => true,
    ],
];

