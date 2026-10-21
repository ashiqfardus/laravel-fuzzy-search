<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

/**
 * Snowball stemming through wamania/php-stemmer, 1.3 or 2.0 and later: English by default,
 * another of its languages through $searchable['stemmer_language'].
 *
 * Opt-in: config('fuzzy-search.indexing.stemmer') or $searchable['stemmer'].
 */
class PorterStemmer implements StemmerInterface
{
    /** @var object */
    private $stemmer;

    public function __construct(string $language = 'English')
    {
        // 2.0 and later build a stemmer through StemmerFactory (language name or ISO code, any
        // case); 1.x has one concrete class per language, Wamania\Snowball\<Language>. The class
        // names are strings: 1.x has no StemmerFactory and 2.0 and later have no
        // Wamania\Snowball\English, so each one's presence tells the versions apart.
        if (class_exists('Wamania\\Snowball\\StemmerFactory')) {
            try {
                $this->stemmer = \Wamania\Snowball\StemmerFactory::create($language);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("Language '{$language}' is not supported.", 0, $e);
            }
            return;
        }

        if (!class_exists('Wamania\\Snowball\\English')) {
            throw new \RuntimeException(
                'PorterStemmer requires wamania/php-stemmer (1.3, or 2.0 and later). ' .
                'Install with: composer require "wamania/php-stemmer:^1.3|^4.0"'
            );
        }

        // Letters only, so the name cannot reach a class outside Wamania\Snowball.
        $class = 'Wamania\\Snowball\\' . ucfirst(strtolower($language));
        if (!preg_match('/^[A-Za-z]+$/', $language) || !class_exists($class)) {
            throw new \InvalidArgumentException("Language '{$language}' is not supported.");
        }
        $this->stemmer = new $class();
    }

    public function stem(string $word): string
    {
        return $this->stemmer->stem($word);
    }
}
