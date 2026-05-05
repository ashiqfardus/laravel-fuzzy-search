<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\Query\AstNodes\{
    AstNode, AndNode, OrNode, NotNode,
    FuzzyTerm, ExactTerm, PrefixTerm, SuffixTerm, IncludeMatchTerm
};

/**
 * @internal This class is not part of the public API and may change without notice.
 *
 * Recursive descent parser:
 *
 *   expr  := or
 *   or    := and ( '|' and )*
 *   and   := atom ( atom )*           // implicit AND on whitespace
 *   atom  := '(' expr ')' | term
 *   term  := FUZZY | EXACT | PREFIX | SUFFIX | INCLUDE_MATCH
 *          | NOT_FUZZY | NOT_EXACT | NOT_PREFIX | NOT_SUFFIX | NOT_INCLUDE_MATCH
 */
class ExtendedQueryParser
{
    /** @var Token[] */
    private array $tokens   = [];
    private int   $pos      = 0;
    private int   $parenDepth = 0;
    private int   $maxDepth  = 16;

    /**
     * @param Token[] $tokens
     */
    public function parse(array $tokens): AstNode
    {
        if (empty($tokens)) {
            throw QuerySyntaxException::emptyQuery();
        }

        $this->tokens     = $tokens;
        $this->pos        = 0;
        $this->parenDepth = 0;
        $this->maxDepth   = (int) config('fuzzy-search.query.max_depth', 16);

        $ast = $this->parseOr();

        if ($this->pos < count($this->tokens)) {
            throw QuerySyntaxException::unbalancedParens();
        }

        return $ast;
    }

    private function parseOr(): AstNode
    {
        $left = $this->parseAnd();

        $children = [$left];
        while ($this->peek()?->type === Token::TYPE_OR) {
            $this->pos++; // consume |
            $children[] = $this->parseAnd();
        }

        return count($children) === 1 ? $left : new OrNode($children);
    }

    private function parseAnd(): AstNode
    {
        $children = [$this->parseAtom()];

        while (true) {
            $next = $this->peek();
            if ($next === null
                || $next->type === Token::TYPE_OR
                || $next->type === Token::TYPE_RPAREN
            ) {
                break;
            }
            $children[] = $this->parseAtom();
        }

        return count($children) === 1 ? $children[0] : new AndNode($children);
    }

    private function parseAtom(): AstNode
    {
        $token = $this->peek();
        if ($token === null) {
            throw QuerySyntaxException::emptyQuery();
        }

        if ($token->type === Token::TYPE_LPAREN) {
            $this->parenDepth++;
            if ($this->parenDepth > $this->maxDepth) {
                throw QuerySyntaxException::depthLimitExceeded($this->parenDepth, $this->maxDepth);
            }
            $this->pos++;
            $expr = $this->parseOr();
            if ($this->peek()?->type !== Token::TYPE_RPAREN) {
                throw QuerySyntaxException::unbalancedParens();
            }
            $this->pos++;
            $this->parenDepth--;
            return $expr;
        }

        $this->pos++;
        return $this->makeTermNode($token);
    }

    private function makeTermNode(Token $token): AstNode
    {
        return match ($token->type) {
            Token::TYPE_FUZZY             => new FuzzyTerm($token->value),
            Token::TYPE_EXACT             => new ExactTerm($token->value),
            Token::TYPE_PREFIX            => new PrefixTerm($token->value),
            Token::TYPE_SUFFIX            => new SuffixTerm($token->value),
            Token::TYPE_INCLUDE_MATCH     => new IncludeMatchTerm($token->value),
            Token::TYPE_NOT_FUZZY         => new NotNode(new FuzzyTerm($token->value)),
            Token::TYPE_NOT_EXACT         => new NotNode(new ExactTerm($token->value)),
            Token::TYPE_NOT_PREFIX        => new NotNode(new PrefixTerm($token->value)),
            Token::TYPE_NOT_SUFFIX        => new NotNode(new SuffixTerm($token->value)),
            Token::TYPE_NOT_INCLUDE_MATCH => new NotNode(new IncludeMatchTerm($token->value)),
            default => throw QuerySyntaxException::emptyQuery(),
        };
    }

    private function peek(): ?Token
    {
        return $this->tokens[$this->pos] ?? null;
    }
}
