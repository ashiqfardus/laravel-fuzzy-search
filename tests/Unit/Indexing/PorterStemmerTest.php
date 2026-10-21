<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Indexing;

use Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer;
use PHPUnit\Framework\TestCase;

class PorterStemmerTest extends TestCase
{
    private function hasFactory(): bool
    {
        return class_exists('Wamania\\Snowball\\StemmerFactory');
    }

    /**
     * php-stemmer 1.3 calls utf8_decode(), deprecated since PHP 8.2 (tokenization.md says so): its
     * notice is not this package's, so it is suppressed on 1.3 only.
     */
    private function stem(string $language, string $word): string
    {
        $stemmer = new PorterStemmer($language);

        return $this->hasFactory() ? $stemmer->stem($word) : @$stemmer->stem($word);
    }

    public function test_language_names_work_in_any_case(): void
    {
        foreach (['English', 'english', 'ENGLISH'] as $name) {
            $this->assertSame('run', $this->stem($name, 'running'), $name);
        }
        $this->assertSame('cheval', $this->stem('French', 'chevaux'));
        $this->assertSame('cheval', $this->stem('french', 'chevaux'));
    }

    public function test_iso_codes_work_with_the_factory_api(): void
    {
        if (!$this->hasFactory()) {
            $this->markTestSkipped('ISO language codes need wamania/php-stemmer 2.0 or later.');
        }

        $this->assertSame('run', (new PorterStemmer('en'))->stem('running'));
        $this->assertSame('cheval', (new PorterStemmer('fr'))->stem('chevaux'));
    }

    public function test_an_unknown_language_throws_invalid_argument(): void
    {
        try {
            new PorterStemmer('Klingon');
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Language 'Klingon' is not supported.", $e->getMessage());
            if ($this->hasFactory()) {
                $this->assertNotNull($e->getPrevious(), 'the factory exception is kept as previous');
            }
        }
    }

    public function test_a_language_name_cannot_name_an_arbitrary_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PorterStemmer('Stemmer\\English');
    }
}
