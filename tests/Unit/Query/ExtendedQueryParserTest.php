<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Query;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\Query\ExtendedQueryParser;
use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{
    AndNode, OrNode, NotNode, FuzzyTerm, ExactTerm, PrefixTerm, SuffixTerm, IncludeMatchTerm,
    TypoTerm, FieldTerm
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

    /** ER-79 checked the depth limit too: max_depth levels of nesting parse, one more throws. */
    public function test_exactly_max_depth_parses_and_one_more_throws(): void
    {
        config(['fuzzy-search.query.max_depth' => 3]);

        $this->assertNotNull($this->parse('(((deep)))'));

        try {
            $this->parse('((((deep))))');
            $this->fail('max_depth + 1 levels must throw');
        } catch (\Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException $e) {
            $this->assertStringContainsString('Query nesting depth 4 exceeds the configured maximum of 3', $e->getMessage());
        }
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

    public function test_typo_token_becomes_a_typo_term(): void
    {
        $this->assertInstanceOf(TypoTerm::class, $this->parse('~john'));
        $this->assertSame('john', $this->parse('~john')->term);

        $not = $this->parse('!~john');
        $this->assertInstanceOf(NotNode::class, $not);
        $this->assertInstanceOf(TypoTerm::class, $not->child);
    }

    public function test_scoped_tokens_are_wrapped_in_a_field_term(): void
    {
        $node = $this->parse('email:^admin');
        $this->assertInstanceOf(FieldTerm::class, $node);
        $this->assertSame('email', $node->field);
        $this->assertInstanceOf(PrefixTerm::class, $node->term);

        $not = $this->parse('!name:john');
        $this->assertInstanceOf(NotNode::class, $not);
        $this->assertInstanceOf(FieldTerm::class, $not->child);
        $this->assertInstanceOf(FuzzyTerm::class, $not->child->term);
    }

    public function test_field_term_depth_is_its_leaf_depth(): void
    {
        $this->assertSame((new FuzzyTerm('x'))->depth(), (new FieldTerm('name', new FuzzyTerm('x')))->depth());
    }
}
