<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Query;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\Query\Token;

class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lexer = new Lexer();
    }

    public function test_bare_word_produces_fuzzy_token(): void
    {
        $tokens = $this->lexer->tokenize('john');
        $this->assertCount(1, $tokens);
        $this->assertEquals(Token::TYPE_FUZZY, $tokens[0]->type);
        $this->assertEquals('john', $tokens[0]->value);
    }

    public function test_exact_operator(): void
    {
        $tokens = $this->lexer->tokenize('=John');
        $this->assertEquals(Token::TYPE_EXACT, $tokens[0]->type);
        $this->assertEquals('John', $tokens[0]->value);
    }

    public function test_prefix_operator(): void
    {
        $tokens = $this->lexer->tokenize('^John');
        $this->assertEquals(Token::TYPE_PREFIX, $tokens[0]->type);
        $this->assertEquals('John', $tokens[0]->value);
    }

    public function test_suffix_operator(): void
    {
        $tokens = $this->lexer->tokenize('Doe$');
        $this->assertEquals(Token::TYPE_SUFFIX, $tokens[0]->type);
        $this->assertEquals('Doe', $tokens[0]->value);
    }

    public function test_include_match_operator(): void
    {
        $tokens = $this->lexer->tokenize("'Man");
        $this->assertEquals(Token::TYPE_INCLUDE_MATCH, $tokens[0]->type);
        $this->assertEquals('Man', $tokens[0]->value);
    }

    public function test_negation_on_fuzzy(): void
    {
        $tokens = $this->lexer->tokenize('!banned');
        $this->assertEquals(Token::TYPE_NOT_FUZZY, $tokens[0]->type);
        $this->assertEquals('banned', $tokens[0]->value);
    }

    public function test_negated_prefix(): void
    {
        $tokens = $this->lexer->tokenize('!^old');
        $this->assertEquals(Token::TYPE_NOT_PREFIX, $tokens[0]->type);
        $this->assertEquals('old', $tokens[0]->value);
    }

    public function test_negated_suffix(): void
    {
        $tokens = $this->lexer->tokenize('!fiction$');
        $this->assertEquals(Token::TYPE_NOT_SUFFIX, $tokens[0]->type);
        $this->assertEquals('fiction', $tokens[0]->value);
    }

    public function test_or_operator(): void
    {
        $tokens = $this->lexer->tokenize('john | jane');
        $this->assertCount(3, $tokens);
        $this->assertEquals(Token::TYPE_OR, $tokens[1]->type);
    }

    public function test_parens(): void
    {
        $tokens = $this->lexer->tokenize('(john)');
        $this->assertEquals(Token::TYPE_LPAREN, $tokens[0]->type);
        $this->assertEquals(Token::TYPE_FUZZY, $tokens[1]->type);
        $this->assertEquals(Token::TYPE_RPAREN, $tokens[2]->type);
    }

    public function test_quoted_phrase_is_one_token(): void
    {
        $tokens = $this->lexer->tokenize('"hello world"');
        $this->assertCount(1, $tokens);
        $this->assertEquals(Token::TYPE_FUZZY, $tokens[0]->type);
        $this->assertEquals('hello world', $tokens[0]->value);
    }

    public function test_complex_query(): void
    {
        $tokens = $this->lexer->tokenize("=John ^Doe !banned 'admin");
        $this->assertCount(4, $tokens);
        $this->assertEquals(Token::TYPE_EXACT, $tokens[0]->type);
        $this->assertEquals(Token::TYPE_PREFIX, $tokens[1]->type);
        $this->assertEquals(Token::TYPE_NOT_FUZZY, $tokens[2]->type);
        $this->assertEquals(Token::TYPE_INCLUDE_MATCH, $tokens[3]->type);
    }

    public function test_unterminated_quote_throws(): void
    {
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException::class);
        $this->lexer->tokenize('"hello world');
    }

    public function test_empty_query_returns_empty_array(): void
    {
        $this->assertEquals([], $this->lexer->tokenize(''));
        $this->assertEquals([], $this->lexer->tokenize('   '));
    }

    public function test_token_count_limit_throws(): void
    {
        config(['fuzzy-search.query.max_tokens' => 5]);
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException::class);
        $this->lexer->tokenize('a b c d e f g'); // 7 tokens, max 5
    }

    /**
     * Regression: alpha.4 fix — '!' must act as a word boundary.
     * Before the fix, 'john!banned' was a single FUZZY token; it should split into
     * FUZZY('john') + NOT_FUZZY('banned').
     */
    public function test_bang_is_word_boundary_in_embedded_position(): void
    {
        $tokens = $this->lexer->tokenize('john!banned');

        $this->assertCount(2, $tokens,
            "'john!banned' should split into 2 tokens — FUZZY('john') + NOT_FUZZY('banned')");
        $this->assertEquals(Token::TYPE_FUZZY, $tokens[0]->type);
        $this->assertEquals('john', $tokens[0]->value);
        $this->assertEquals(Token::TYPE_NOT_FUZZY, $tokens[1]->type);
        $this->assertEquals('banned', $tokens[1]->value);
    }
}
