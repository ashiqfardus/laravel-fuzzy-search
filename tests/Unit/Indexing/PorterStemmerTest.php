<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Indexing;

use Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

class PorterStemmerTest extends TestCase
{
    private function hasFactory(): bool
    {
        return class_exists('Wamania\\Snowball\\StemmerFactory');
    }

    public function test_language_names_work_in_any_case(): void
    {
        foreach (['English', 'english', 'ENGLISH'] as $name) {
            $this->assertSame('run', (new PorterStemmer($name))->stem('running'), $name);
        }
        $this->assertSame('cheval', (new PorterStemmer('French'))->stem('chevaux'));
        $this->assertSame('cheval', (new PorterStemmer('french'))->stem('chevaux'));
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

    /**
     * wamania/php-stemmer 2.0.0 throws a ValueError (an Error, not an Exception) for a language it
     * does not know. In its own process: the factory it declares must not reach the other tests.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_an_unknown_language_is_invalid_argument_when_the_factory_throws_an_error(): void
    {
        eval('namespace Wamania\\Snowball; class StemmerFactory { public static function create($language) { throw new \\ValueError("not a language"); } }');

        try {
            new PorterStemmer('Klingon');
            $this->fail('Expected InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame("Language 'Klingon' is not supported.", $e->getMessage());
            $this->assertInstanceOf(\ValueError::class, $e->getPrevious());
        }
    }

    public function test_a_language_name_cannot_name_an_arbitrary_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PorterStemmer('Stemmer\\English');
    }
}
