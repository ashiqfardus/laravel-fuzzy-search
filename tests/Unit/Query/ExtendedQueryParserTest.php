<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Query;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser;
use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{
    AndNode, OrNode, NotNode, FuzzyTerm, ExactTerm, PrefixTerm, SuffixTerm, IncludeMatchTerm
};

class ExtendedQueryParserTest extends TestCase
{
    private function parse(string $query)
    {
        $tokens = (new Lexer())->tokenize($query);
        return (new ExtendedQueryParser())->parse($tokens);
    }

    public function test_single_bare_word(): void
    {
        $ast = $this->parse('john');
        $this->assertInstanceOf(FuzzyTerm::class, $ast);
        $this->assertEquals('john', $ast->term);
    }

    public function test_exact_term(): void
    {
        $ast = $this->parse('=John');
        $this->assertInstanceOf(ExactTerm::class, $ast);
    }

    public function test_implicit_and(): void
    {
        $ast = $this->parse('john doe');
        $this->assertInstanceOf(AndNode::class, $ast);
        $this->assertCount(2, $ast->children);
    }

    public function test_explicit_or(): void
    {
        $ast = $this->parse('john | jane');
        $this->assertInstanceOf(OrNode::class, $ast);
        $this->assertCount(2, $ast->children);
    }

    public function test_negation(): void
    {
        $ast = $this->parse('!banned');
        $this->assertInstanceOf(NotNode::class, $ast);
        $this->assertInstanceOf(FuzzyTerm::class, $ast->child);
    }

    public function test_complex_query_with_and_or_not(): void
    {
        $ast = $this->parse('=John ^Doe !banned');
        $this->assertInstanceOf(AndNode::class, $ast);
        $this->assertCount(3, $ast->children);
        $this->assertInstanceOf(ExactTerm::class, $ast->children[0]);
        $this->assertInstanceOf(PrefixTerm::class, $ast->children[1]);
        $this->assertInstanceOf(NotNode::class, $ast->children[2]);
    }

    public function test_parenthesized_or_within_and(): void
    {
        $ast = $this->parse('admin (john | jane)');
        $this->assertInstanceOf(AndNode::class, $ast);
        $this->assertInstanceOf(OrNode::class, $ast->children[1]);
    }

    public function test_depth_limit_throws(): void
    {
        config(['fuzzy-search.query.max_depth' => 3]);
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException::class);
        $this->parse('((((deep))))');
    }

    public function test_unbalanced_parens_throws(): void
    {
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException::class);
        $this->parse('(unclosed');
    }

    public function test_empty_tokens_throws(): void
    {
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException::class);
        (new ExtendedQueryParser())->parse([]);
    }
}
