<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class Lexer
{
    /**
     * Convert a query string into a stream of tokens.
     *
     * @return Token[]
     */
    public function tokenize(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $maxTokens = (int) config('fuzzy-search.query.max_tokens', 32);
        $tokens    = [];
        $i         = 0;
        $len       = strlen($query);

        while ($i < $len) {
            // Skip whitespace
            if (ctype_space($query[$i])) {
                $i++;
                continue;
            }

            // Special single-character tokens
            if ($query[$i] === '|') {
                $tokens[] = new Token(Token::TYPE_OR);
                $i++;
            } elseif ($query[$i] === '(') {
                $tokens[] = new Token(Token::TYPE_LPAREN);
                $i++;
            } elseif ($query[$i] === ')') {
                $tokens[] = new Token(Token::TYPE_RPAREN);
                $i++;
            } elseif ($query[$i] === '"') {
                // Quoted phrase
                $end = strpos($query, '"', $i + 1);
                if ($end === false) {
                    throw QuerySyntaxException::unterminatedQuote();
                }
                $value    = substr($query, $i + 1, $end - $i - 1);
                $tokens[] = new Token(Token::TYPE_FUZZY, $value);
                $i        = $end + 1;
            } else {
                // Operator-prefixed term
                $isNot = false;
                if ($query[$i] === '!') {
                    $isNot = true;
                    $i++;
                    if ($i >= $len) {
                        break;
                    }
                    // !( is unsupported — NOT of a group would silently drop the operator
                    if ($query[$i] === '(') {
                        throw QuerySyntaxException::notBeforeGroup();
                    }
                }

                $opPrefix = null;
                if ($query[$i] === "'") {
                    $opPrefix = 'INCLUDE_MATCH';
                    $i++;
                } elseif ($query[$i] === '=') {
                    $opPrefix = 'EXACT';
                    $i++;
                } elseif ($query[$i] === '^') {
                    $opPrefix = 'PREFIX';
                    $i++;
                }

                // Read bare word — stop at whitespace, grouping chars, OR '!' (prefix operator)
                $start = $i;
                while ($i < $len
                    && !ctype_space($query[$i])
                    && $query[$i] !== '|'
                    && $query[$i] !== '('
                    && $query[$i] !== ')'
                    && $query[$i] !== '!'
                ) {
                    $i++;
                }
                $term = substr($query, $start, $i - $start);
                if ($term === '') {
                    continue;
                }

                // Suffix operator
                $isSuffix = false;
                if ($opPrefix === null && str_ends_with($term, '$')) {
                    $isSuffix = true;
                    $term     = substr($term, 0, -1);
                    if ($term === '') {
                        continue;
                    }
                }

                // Determine type
                $base = match (true) {
                    $opPrefix === 'INCLUDE_MATCH' => 'INCLUDE_MATCH',
                    $opPrefix === 'EXACT'         => 'EXACT',
                    $opPrefix === 'PREFIX'        => 'PREFIX',
                    $isSuffix                     => 'SUFFIX',
                    default                       => 'FUZZY',
                };

                $type = $isNot ? "NOT_{$base}" : $base;
                $tokens[] = new Token($type, $term);
            }

            if (count($tokens) >= $maxTokens) {
                throw QuerySyntaxException::tokenLimitExceeded(count($tokens), $maxTokens);
            }
        }

        return $tokens;
    }
}
