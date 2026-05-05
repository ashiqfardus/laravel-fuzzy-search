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
    | Reserved for future use — these values are not currently read by the
    | package. Scoring constants are hard-coded in SearchBuilder.
    |
    */
    'scoring' => [
        'exact_match' => 100,
        'prefix_match' => 50,
        'contains' => 25,
        'fuzzy_match' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Stop Words
    |--------------------------------------------------------------------------
    |
    | Words to ignore during search (by locale)
    |
    */
    'stop_words' => [
        'en' => ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'is', 'it'],
        'de' => ['der', 'die', 'das', 'und', 'oder', 'aber', 'in', 'auf', 'an', 'zu', 'für', 'von'],
        'fr' => ['le', 'la', 'les', 'un', 'une', 'des', 'et', 'ou', 'mais', 'dans', 'sur', 'à'],
        'es' => ['el', 'la', 'los', 'las', 'un', 'una', 'y', 'o', 'pero', 'en', 'sobre', 'a'],
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
    | Settings for the search index table
    |
    */
    'indexing' => [
        'enabled'            => false,   // Set true to enable observer-based auto-indexing on model save/delete
        'table'              => 'search_index',
        'async'              => true,
        'queue'              => 'default',
        'chunk_size'         => 500,
        'tokenizer'          => \Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer::class,
        'stemmer'            => \Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer::class,
        'max_tokens_per_doc' => 5000,  // Cap unique tokens per document to prevent index poisoning
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
         * max_postings_per_term: SQL-side top-K cutoff per matched term.
         * Postings are ordered by frequency DESC before the limit is applied,
         * so the highest-signal rows are always retained.  For typical corpora
         * this cap is never reached; it exists purely to bound memory usage
         * when a term matches tens of thousands of documents.
         */
        'max_postings_per_term' => 50000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Extended-search query parser
    |--------------------------------------------------------------------------
    |
    | max_depth: maximum nesting depth of parentheses (DoS guard)
    | max_tokens: maximum tokens in a single query (DoS guard)
    |
    */
    'query' => [
        'max_depth'  => 16,
        'max_tokens' => 32,
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
        'enabled' => false,
        'driver' => 'default',  // Use default cache driver
        'ttl' => 3600,          // 1 hour
        'prefix' => 'fuzzy_search_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Settings
    |--------------------------------------------------------------------------
    |
    | Reserved for future use — these values are not currently read by the
    | package. Use indexing.chunk_size for rebuild chunk size.
    |
    */
    'performance' => [
        'max_patterns' => 100,      // Maximum LIKE patterns to generate
        'chunk_size' => 1000,       // Chunk size for large operations
        'debounce_ms' => 300,       // Default debounce for real-time search
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
    */
    'similar_text' => [
        'min_percentage' => 70,
    ],

    /*
    |--------------------------------------------------------------------------
    | Highlighting Settings
    |--------------------------------------------------------------------------
    |
    | Reserved for future use — currently the highlight tag is configured
    | via ->highlight('<em>', '</em>') at the query level.
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
    | Note: 'normalize' is reserved for future use. Accent-insensitive search
    | is opt-in per query via ->accentInsensitive() (PostgreSQL only with
    | use_native_functions=true).
    |
    */
    'unicode' => [
        'normalize' => true,
        'accent_insensitive' => true,
    ],
];

