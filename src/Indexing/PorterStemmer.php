<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

// StemmerFactory is aliased to the English concrete class — it is present if and only if
// wamania/php-stemmer is installed. Using a concrete class avoids class_exists() returning
// false for the Stemmer interface even when the package is present.
use Wamania\Snowball\English as StemmerFactory;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class PorterStemmer implements StemmerInterface
{
    /** @var object */
    private $stemmer;

    public function __construct(string $language = 'English')
    {
        if (!class_exists(StemmerFactory::class)) {
            throw new \RuntimeException(
                'PorterStemmer requires wamania/php-stemmer. Install with: ' .
                'composer require wamania/php-stemmer'
            );
        }

        $class = 'Wamania\\Snowball\\' . ucfirst($language);
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Language '{$language}' is not supported.");
        }
        $this->stemmer = new $class();
    }

    public function stem(string $word): string
    {
        return $this->stemmer->stem($word);
    }
}
