<?php

namespace Ashiqfardus\LaravelFuzzySearch\Indexing;

class PorterStemmer implements StemmerInterface
{
    private \Wamania\Snowball\Stemmer $stemmer;

    public function __construct(string $language = 'English')
    {
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
