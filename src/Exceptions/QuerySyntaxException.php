<?php

namespace Ashiqfardus\LaravelFuzzySearch\Exceptions;

class QuerySyntaxException extends LaravelFuzzySearchException
{
    public static function tokenLimitExceeded(int $tokens, int $max): self
    {
        return new self(
            "Query has {$tokens} tokens, exceeding the configured maximum of {$max}. " .
            "Increase config('fuzzy-search.query.max_tokens') or simplify the query."
        );
    }

    public static function depthLimitExceeded(int $depth, int $max): self
    {
        return new self(
            "Query nesting depth {$depth} exceeds the configured maximum of {$max}. " .
            "Increase config('fuzzy-search.query.max_depth') or flatten the query."
        );
    }

    public static function unbalancedParens(): self
    {
        return new self('Unbalanced parentheses in query.');
    }

    public static function unterminatedQuote(): self
    {
        return new self('Unterminated quoted phrase in query.');
    }

    public static function emptyQuery(): self
    {
        return new self('Query has no searchable terms after parsing.');
    }

    public static function notBeforeGroup(): self
    {
        return new self(
            "The NOT operator (!) cannot precede a parenthesized group. " .
            "Use individual NOT terms instead: '!term1 !term2'."
        );
    }

    public static function typoOperatorNeedsTerm(): self
    {
        return new self('The typo operator (~) needs a term after it, for example ~john.');
    }

    public static function typoOperatorCombination(): self
    {
        return new self(
            "The typo operator (~) cannot be combined with ', =, ^ or a trailing $. " .
            "Use ~word on its own (a field scope is fine: name:~word)."
        );
    }

    public static function fieldNeedsTerm(string $field): self
    {
        return new self("The field scope \"{$field}:\" needs a term after the colon, for example {$field}:john.");
    }

    public static function unknownSearchField(string $field, array $known): self
    {
        $list = $known === [] ? 'none' : implode(', ', $known);

        return new self("Unknown search field \"{$field}\". Searchable fields: {$list}.");
    }
}
