<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;

/**
 * Fuzz the lexer + parser with adversarial inputs.
 * No exception other than QuerySyntaxException may escape.
 */
class QueryFuzzTest extends TestCase
{
    public function test_fuzz_random_queries_do_not_crash(): void
    {
        $lexer  = new Lexer();
        $parser = new ExtendedQueryParser();
        $chars  = ['a','b','c',' ','|','(',')','!','=','^','$','\'','"','x','y','z'];

        $iterations = 1000;
        $caught     = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $len    = random_int(0, 60);
            $query  = '';
            for ($j = 0; $j < $len; $j++) {
                $query .= $chars[array_rand($chars)];
            }

            try {
                $tokens = $lexer->tokenize($query);
                if (!empty($tokens)) {
                    $parser->parse($tokens);
                }
            } catch (QuerySyntaxException $e) {
                $caught++;
                continue;
            } catch (\Throwable $e) {
                $this->fail("Unexpected exception on query [{$query}]: " . get_class($e) . ': ' . $e->getMessage());
            }
        }

        $this->assertGreaterThan(0, $caught, 'No QuerySyntaxException was thrown across 1000 random inputs — guards may not be wired');
    }

    public function test_extreme_nesting_depth(): void
    {
        $lexer  = new Lexer();
        $parser = new ExtendedQueryParser();

        $query = str_repeat('(', 100) . 'x' . str_repeat(')', 100);

        $this->expectException(QuerySyntaxException::class);
        $tokens = $lexer->tokenize($query);
        $parser->parse($tokens);
    }

    public function test_token_flood(): void
    {
        $lexer = new Lexer();
        $query = str_repeat('word ', 200);

        $this->expectException(QuerySyntaxException::class);
        $lexer->tokenize($query);
    }
}
