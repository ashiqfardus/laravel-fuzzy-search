<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Query;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Query\Lexer;
use Ashiqfardus\LaravelFuzzySearch\Query\Token;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class LexerTest extends TestCase
{
    private Lexer $lexer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lexer = new Lexer();
    }

    /**
     * ctype_space() follows LC_CTYPE, and under a UTF-8 locale on macOS/BSD it calls byte 0xA0 a
     * space. 0xA0 is inside à (C3 A0), ঠ (E0 A6 A0) and 丠 (E4 B8 A0), so those words were cut
     * mid-character: invalid UTF-8 in the binding on PHP 8.1/8.2, a literal '?' on 8.3+. Only
     * ASCII whitespace separates words. (Linux glibc never treats 0xA0 as a space.)
     *
     * In its own process: restoring the saved locale name does not restore PCRE's built-in
     * character tables, so later tests in the same process would still see 0xA0 as a space.
     */
    #[RunInSeparateProcess]
    public function test_a_utf8_locale_does_not_split_a_character_containing_byte_0xA0(): void
    {
        $previous = setlocale(LC_CTYPE, '0');
        setlocale(LC_CTYPE, 'C.UTF-8', 'en_US.UTF-8');

        try {
            $values = array_map(fn (Token $t) => $t->value, $this->lexer->tokenize("voilà ঠাকুর\t丠 name:à"));
        } finally {
            setlocale(LC_CTYPE, $previous);
        }

        $this->assertSame(['voilà', 'ঠাকুর', '丠', 'à'], $values);
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

    public function test_an_empty_quoted_phrase_is_skipped_like_an_empty_word(): void
    {
        // Compiled, "" was LIKE '%%': every row.
        $this->assertSame([], $this->lexer->tokenize('""'));
        $this->assertSame(['zzzz'], array_map(fn (Token $t) => $t->value, $this->lexer->tokenize('"" zzzz !""')));
    }

    public function test_token_count_limit_throws(): void
    {
        config(['fuzzy-search.query.max_tokens' => 5]);
        $this->expectException(\Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException::class);
        $this->lexer->tokenize('a b c d e f g'); // 7 tokens, max 5
    }

    /** @param Token[] $tokens */
    private static function shape(array $tokens): array
    {
        return array_map(fn (Token $t) => [$t->type, $t->value, $t->field], $tokens);
    }

    /**
     * ER-44: ! negates only at the start of a token, as ~ and field: are operators only there.
     * Inside or at the end of a word it is part of the word: 2.0 split "yahoo!mail" into
     * yahoo AND NOT mail, which excluded the very row typed, and read "wow!" as "wow".
     */
    public function test_a_bang_inside_or_after_a_word_is_part_of_the_word(): void
    {
        $cases = [
            'yahoo!mail'  => [[Token::TYPE_FUZZY, 'yahoo!mail', null]],
            'john!banned' => [[Token::TYPE_FUZZY, 'john!banned', null]],
            'wow!'        => [[Token::TYPE_FUZZY, 'wow!', null]],
            "'wow!"       => [[Token::TYPE_INCLUDE_MATCH, 'wow!', null]],
            '=John!!!'    => [[Token::TYPE_EXACT, 'John!!!', null]],
            '^a!b'        => [[Token::TYPE_PREFIX, 'a!b', null]],
            'a!b$'        => [[Token::TYPE_SUFFIX, 'a!b', null]],
            '~a!b'        => [[Token::TYPE_TYPO, 'a!b', null]],
            'name:a!b'    => [[Token::TYPE_FUZZY, 'a!b', 'name']],
            '!a!b'        => [[Token::TYPE_NOT_FUZZY, 'a!b', null]],
            'a! b'        => [[Token::TYPE_FUZZY, 'a!', null], [Token::TYPE_FUZZY, 'b', null]],
            'a!|b'        => [[Token::TYPE_FUZZY, 'a!', null], [Token::TYPE_OR, '', null], [Token::TYPE_FUZZY, 'b', null]],
        ];

        foreach ($cases as $query => $expected) {
            $this->assertSame($expected, self::shape($this->lexer->tokenize($query)), $query);
        }
    }

    /**
     * ER-45: ! is NOT only as the first character of a token. After another operator it is the
     * first character of the term: at ab152ae ^!a, '!a, =!a and !!a dropped the operator and
     * meant NOT a, and ~!a threw.
     */
    public function test_a_bang_after_another_operator_is_part_of_the_term(): void
    {
        $cases = [
            '^!a'   => [[Token::TYPE_PREFIX, '!a', null]],
            "'!a"   => [[Token::TYPE_INCLUDE_MATCH, '!a', null]],
            '=!a'   => [[Token::TYPE_EXACT, '!a', null]],
            '~!a'   => [[Token::TYPE_TYPO, '!a', null]],
            '!!a'   => [[Token::TYPE_NOT_FUZZY, '!a', null]],
            '!!'    => [[Token::TYPE_NOT_FUZZY, '!', null]],
            '!^!a'  => [[Token::TYPE_NOT_PREFIX, '!a', null]],
            '!~!a'  => [[Token::TYPE_NOT_TYPO, '!a', null]],
            'b ^!a' => [[Token::TYPE_FUZZY, 'b', null], [Token::TYPE_PREFIX, '!a', null]],
        ];

        foreach ($cases as $query => $expected) {
            $this->assertSame($expected, self::shape($this->lexer->tokenize($query)), $query);
        }
    }

    public function test_a_bang_at_the_start_of_a_token_still_negates(): void
    {
        $cases = [
            '!john'         => [[Token::TYPE_NOT_FUZZY, 'john', null]],
            '!^word'        => [[Token::TYPE_NOT_PREFIX, 'word', null]],
            '!word$'        => [[Token::TYPE_NOT_SUFFIX, 'word', null]],
            "!'word"        => [[Token::TYPE_NOT_INCLUDE_MATCH, 'word', null]],
            '!=word'        => [[Token::TYPE_NOT_EXACT, 'word', null]],
            '!~word'        => [[Token::TYPE_NOT_TYPO, 'word', null]],
            '!name:bob'     => [[Token::TYPE_NOT_FUZZY, 'bob', 'name']],
            '!"john doe"'   => [[Token::TYPE_NOT_FUZZY, 'john doe', null]],
            'a !b'          => [[Token::TYPE_FUZZY, 'a', null], [Token::TYPE_NOT_FUZZY, 'b', null]],
            '(a) !b'        => [[Token::TYPE_LPAREN, '', null], [Token::TYPE_FUZZY, 'a', null], [Token::TYPE_RPAREN, '', null], [Token::TYPE_NOT_FUZZY, 'b', null]],
            'a|!b'          => [[Token::TYPE_FUZZY, 'a', null], [Token::TYPE_OR, '', null], [Token::TYPE_NOT_FUZZY, 'b', null]],
            '(!b)'          => [[Token::TYPE_LPAREN, '', null], [Token::TYPE_NOT_FUZZY, 'b', null], [Token::TYPE_RPAREN, '', null]],
            '"a b"!c'       => [[Token::TYPE_FUZZY, 'a b', null], [Token::TYPE_NOT_FUZZY, 'c', null]],
            '"yahoo!mail"'  => [[Token::TYPE_FUZZY, 'yahoo!mail', null]],
        ];

        foreach ($cases as $query => $expected) {
            $this->assertSame($expected, self::shape($this->lexer->tokenize($query)), $query);
        }
    }

    public function test_a_bang_with_no_term_after_it_is_skipped_or_rejected_as_before(): void
    {
        // A lone or trailing ! is skipped (a query of nothing else then has no terms: the parser
        // throws); ! before a group throws.
        $this->assertSame([], $this->lexer->tokenize('!'));
        $this->assertSame([[Token::TYPE_FUZZY, 'x', null]], self::shape($this->lexer->tokenize('x !')));
        $this->assertSame([[Token::TYPE_FUZZY, 'x', null]], self::shape($this->lexer->tokenize('! x')));
        $this->assertSame(
            [[Token::TYPE_LPAREN, '', null], [Token::TYPE_FUZZY, 'x', null], [Token::TYPE_RPAREN, '', null]],
            self::shape($this->lexer->tokenize('(x !)'))
        );

        foreach (['!(a)', 'a !(b)', 'name:!a'] as $query) {
            try {
                $this->lexer->tokenize($query);
                $this->fail("{$query} should throw");
            } catch (QuerySyntaxException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_tilde_marks_a_typo_term(): void
    {
        $tokens = (new Lexer())->tokenize('~john');
        $this->assertSame(Token::TYPE_TYPO, $tokens[0]->type);
        $this->assertSame('john', $tokens[0]->value);
        $this->assertNull($tokens[0]->field);
    }

    public function test_bang_tilde_is_a_not_typo_term(): void
    {
        $tokens = (new Lexer())->tokenize('!~john');
        $this->assertSame(Token::TYPE_NOT_TYPO, $tokens[0]->type);
        $this->assertSame('john', $tokens[0]->value);
    }

    public function test_tilde_alone_is_rejected(): void
    {
        $this->expectException(QuerySyntaxException::class);
        (new Lexer())->tokenize('~');
    }

    public function test_tilde_cannot_combine_with_other_operators(): void
    {
        foreach (['~^john', '~=john', "~'john", '^~john', '~john$'] as $query) {
            try {
                (new Lexer())->tokenize($query);
                $this->fail("Expected QuerySyntaxException for {$query}");
            } catch (QuerySyntaxException $e) {
                $this->assertStringContainsString('~', $e->getMessage());
            }
        }
    }

    public function test_a_quoted_phrase_reads_the_same_after_every_prefix(): void
    {
        foreach (['"john doe"' => [Token::TYPE_FUZZY, null], '!"john doe"' => [Token::TYPE_NOT_FUZZY, null], 'name:"john doe"' => [Token::TYPE_FUZZY, 'name'], '!name:"john doe"' => [Token::TYPE_NOT_FUZZY, 'name']] as $query => [$type, $field]) {
            $tokens = $this->lexer->tokenize($query);
            $this->assertCount(1, $tokens, $query);
            $this->assertSame($type, $tokens[0]->type, $query);
            $this->assertSame('john doe', $tokens[0]->value, $query);
            $this->assertSame($field, $tokens[0]->field, $query);
        }
    }

    public function test_tilde_cannot_combine_with_a_quoted_phrase(): void
    {
        foreach (['~"john doe"', 'name:~"a b"'] as $query) {
            try {
                $this->lexer->tokenize($query);
                $this->fail("Expected QuerySyntaxException for {$query}");
            } catch (QuerySyntaxException $e) {
                $this->assertStringContainsString('~', $e->getMessage());
            }
        }
    }

    public function test_field_scope_is_captured(): void
    {
        $tokens = (new Lexer())->tokenize('name:john email:^admin author.name:smith');
        $this->assertSame([Token::TYPE_FUZZY, Token::TYPE_PREFIX, Token::TYPE_FUZZY], array_map(fn ($t) => $t->type, $tokens));
        $this->assertSame(['name', 'email', 'author.name'], array_map(fn ($t) => $t->field, $tokens));
        $this->assertSame(['john', 'admin', 'smith'], array_map(fn ($t) => $t->value, $tokens));
    }

    public function test_field_scope_combines_with_not_and_typo_and_quotes(): void
    {
        $tokens = (new Lexer())->tokenize('!name:john name:~jonh name:"john doe"');
        $this->assertSame([Token::TYPE_NOT_FUZZY, Token::TYPE_TYPO, Token::TYPE_FUZZY], array_map(fn ($t) => $t->type, $tokens));
        $this->assertSame(['name', 'name', 'name'], array_map(fn ($t) => $t->field, $tokens));
        $this->assertSame('john doe', $tokens[2]->value);
    }

    public function test_a_colon_inside_a_word_or_a_quoted_phrase_is_literal(): void
    {
        $tokens = (new Lexer())->tokenize('12:30 "name:john"');
        $this->assertSame('12:30', $tokens[0]->value);
        $this->assertNull($tokens[0]->field);
        $this->assertSame('name:john', $tokens[1]->value);
        $this->assertNull($tokens[1]->field);
    }

    public function test_field_without_a_term_is_rejected(): void
    {
        foreach (['name:', 'name: john', 'name:|', 'name:""', '!name:""'] as $query) {
            try {
                (new Lexer())->tokenize($query);
                $this->fail("Expected QuerySyntaxException for {$query}");
            } catch (QuerySyntaxException $e) {
                $this->assertStringContainsString('name', $e->getMessage());
            }
        }
    }

    public function test_token_values_are_capped_at_max_term_length(): void
    {
        config(['fuzzy-search.query.max_term_length' => 16]);

        $long = str_repeat('a', 300);

        $this->assertSame(16, strlen($this->lexer->tokenize($long)[0]->value), 'bare word');
        $this->assertSame(16, strlen($this->lexer->tokenize('"' . $long . '"')[0]->value), 'quoted phrase');
        $this->assertSame(16, strlen($this->lexer->tokenize('~' . $long)[0]->value), 'typo term');
        $this->assertSame(16, strlen($this->lexer->tokenize('name:"' . $long . '"')[0]->value), 'field-scoped phrase');
    }

    public function test_field_scope_combines_with_include_match_and_exact(): void
    {
        $tokens = (new Lexer())->tokenize("name:'john name:=john");
        $this->assertSame([Token::TYPE_INCLUDE_MATCH, Token::TYPE_EXACT], array_map(fn ($t) => $t->type, $tokens));
        $this->assertSame(['name', 'name'], array_map(fn ($t) => $t->field, $tokens));
        $this->assertSame(['john', 'john'], array_map(fn ($t) => $t->value, $tokens));
    }
}
