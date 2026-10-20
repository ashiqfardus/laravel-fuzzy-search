<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NgramTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\NullStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\PorterStemmer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\ScriptAwareTokenizer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\WhitespaceTokenizer;
use PHPUnit\Framework\TestCase;

/**
 * ER-208: docs/tokenization.md and the shipped indexing.tokenizer / indexing.stemmer tell users to
 * name these classes in their config, so none is marked @internal ("may change without notice"),
 * which static analysis flags on every use. IndexManager stays internal.
 */
class DocumentedPipelineClassesTest extends TestCase
{
    public function test_the_tokenizers_and_stemmers_users_configure_are_not_internal(): void
    {
        foreach ([WhitespaceTokenizer::class, NgramTokenizer::class, ScriptAwareTokenizer::class, NullStemmer::class, PorterStemmer::class] as $class) {
            $this->assertStringNotContainsString('@internal', (string) (new \ReflectionClass($class))->getDocComment(), $class);
        }

        $this->assertStringContainsString('@internal', (string) (new \ReflectionClass(IndexManager::class))->getDocComment());
    }
}
