<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Fuzzy Search Algorithm
    |--------------------------------------------------------------------------
    |
    | Supported: "simple", "fuzzy", "levenshtein", "soundex", "trigram"
    |
    | - "fuzzy": General purpose with typo tolerance (recommended)
    | - "levenshtein": Edit distance based, configurable tolerance
    | - "soundex": Phonetic matching for similar sounding words
    | - "trigram": N-gram similarity (best with PostgreSQL pg_trgm)
    | - "simple": Basic LIKE matching (fastest, no typo tolerance)
    |
    */
    'default_algorithm' => 'fuzzy',

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
    | Configure how relevance scores are calculated
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
        'enabled' => false,
        'table' => 'search_index',
        'async' => true,
        'queue' => 'default',
        'chunk_size' => 500,
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
    */
    'unicode' => [
        'normalize' => true,
        'accent_insensitive' => true,
    ],
];

