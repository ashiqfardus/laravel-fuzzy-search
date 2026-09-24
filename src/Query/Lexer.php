<?php

namespace Ashiqfardus\LaravelFuzzySearch\Query;

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;

/**
 * @internal This class is not part of the public API and may change without notice.
 */
class Lexer
{
    /**
     * ASCII whitespace only. ctype_space() follows LC_CTYPE: under a UTF-8 locale on macOS/BSD it
     * calls byte 0xA0 a space, which cut à (C3 A0), ঠ (E0 A6 A0) and 丠 (E4 B8 A0) in half.
     */
    private static function isSpace(string $byte): bool
    {
        return strspn($byte, " \t\n\r\v\f") === 1;
    }

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
        // Every token value is capped here, so no leaf term can reach a driver's pattern
        // generator at a length that makes it build O(n^2) LIKE patterns (~word does).
        $maxTermLen = (int) config('fuzzy-search.query.max_term_length', 128);
        $tokens    = [];
        $i         = 0;
        $len       = strlen($query);

        while ($i < $len) {
            // Skip whitespace
            if (self::isSpace($query[$i])) {
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
            } else {
                // Operator-prefixed term: [!][field:][~ | ' | = | ^]word[$]  (or [!][field:]"phrase")
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

                // Field scope: an identifier (letters, digits, _ and . for relation paths) followed by ':'.
                // Only at the start of a token, so 12:30 or a colon inside a word stays literal
                // (a URL at the start of a token is read as a scope — see the upgrade guide).
                $field = null;
                if (preg_match('/\G([A-Za-z_][A-Za-z0-9_.]*):/', $query, $m, 0, $i) === 1) {
                    $field = $m[1];
                    $i    += strlen($m[0]);
                    if ($i >= $len || self::isSpace($query[$i]) || in_array($query[$i], ['|', '(', ')', '!'], true)) {
                        throw QuerySyntaxException::fieldNeedsTerm($field);
                    }
                }

                // One quoted-phrase read for every prefix combination — "phrase", !"phrase",
                // name:"phrase" and !name:"phrase" all land here.
                if ($query[$i] === '"') {
                    $end = strpos($query, '"', $i + 1);
                    if ($end === false) {
                        throw QuerySyntaxException::unterminatedQuote();
                    }
                    $phrase = substr($query, $i + 1, $end - $i - 1);
                    $i      = $end + 1;
                    // "" is skipped like an empty word: compiled, it was LIKE '%%' (every row).
                    if ($phrase === '') {
                        if ($field !== null) {
                            throw QuerySyntaxException::fieldNeedsTerm($field);
                        }
                        continue;
                    }
                    $tokens[] = new Token($isNot ? Token::TYPE_NOT_FUZZY : Token::TYPE_FUZZY, mb_substr($phrase, 0, $maxTermLen, 'UTF-8'), $field);
                    if (count($tokens) >= $maxTokens) {
                        throw QuerySyntaxException::tokenLimitExceeded(count($tokens), $maxTokens);
                    }
                    continue;
                }

                $opPrefix = null;
                if ($query[$i] === '~') {
                    $opPrefix = 'TYPO';
                    $i++;
                    if ($i < $len && in_array($query[$i], ["'", '=', '^', '~', '"'], true)) {
                        throw QuerySyntaxException::typoOperatorCombination();
                    }
                } elseif ($query[$i] === "'") {
                    $opPrefix = 'INCLUDE_MATCH';
                    $i++;
                } elseif ($query[$i] === '=') {
                    $opPrefix = 'EXACT';
                    $i++;
                } elseif ($query[$i] === '^') {
                    $opPrefix = 'PREFIX';
                    $i++;
                }
                if ($opPrefix !== null && $opPrefix !== 'TYPO' && $i < $len && $query[$i] === '~') {
                    throw QuerySyntaxException::typoOperatorCombination();
                }

                // Read bare word — stop at whitespace, | or a parenthesis. A ! here is part of the
                // term: like ~ and field:, it is an operator only as a token's first character.
                $start = $i;
                while ($i < $len
                    && !self::isSpace($query[$i])
                    && $query[$i] !== '|'
                    && $query[$i] !== '('
                    && $query[$i] !== ')'
                ) {
                    $i++;
                }
                $term = mb_substr(substr($query, $start, $i - $start), 0, $maxTermLen, 'UTF-8');
                if ($term === '') {
                    if ($opPrefix === 'TYPO') {
                        throw QuerySyntaxException::typoOperatorNeedsTerm();
                    }
                    if ($field !== null) {
                        throw QuerySyntaxException::fieldNeedsTerm($field);
                    }
                    continue;
                }

                // Suffix operator (never with ~: the fuzzy driver has no suffix mode)
                $isSuffix = false;
                if (str_ends_with($term, '$')) {
                    if ($opPrefix === 'TYPO') {
                        throw QuerySyntaxException::typoOperatorCombination();
                    }
                    if ($opPrefix === null) {
                        $isSuffix = true;
                        $term     = substr($term, 0, -1);
                        if ($term === '') {
                            continue;
                        }
                    }
                }

                // Determine type
                $base = match (true) {
                    $opPrefix === 'TYPO'          => 'TYPO',
                    $opPrefix === 'INCLUDE_MATCH' => 'INCLUDE_MATCH',
                    $opPrefix === 'EXACT'         => 'EXACT',
                    $opPrefix === 'PREFIX'        => 'PREFIX',
                    $isSuffix                     => 'SUFFIX',
                    default                       => 'FUZZY',
                };

                $type = $isNot ? "NOT_{$base}" : $base;
                $tokens[] = new Token($type, $term, $field);
            }

            if (count($tokens) >= $maxTokens) {
                throw QuerySyntaxException::tokenLimitExceeded(count($tokens), $maxTokens);
            }
        }

        return $tokens;
    }
}
