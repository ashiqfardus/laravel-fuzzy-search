<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

/**
 * @internal Not part of the public API.
 */
final class Utf8
{
    /** The most characters of a string that PHP scoring compares — see scoringInput(). */
    public const SCORING_MAX_CHARS = 255;

    /**
     * Drop every byte that is not part of a well-formed UTF-8 sequence (RFC 3629: no overlong
     * forms, no surrogates, nothing above U+10FFFF); valid characters stay byte-identical.
     * Applied to every search term where it enters the package: PostgreSQL and SQL Server
     * reject invalid bytes in a bind parameter, so a crafted `?q=john%C3` was an error there.
     * Dropped, not replaced: mb_scrub()'s '?' would be a literal character in a LIKE pattern.
     */
    public static function clean(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Byte mode (no /u): keep each well-formed sequence ($1), drop any other single byte.
        return preg_replace(
            '/([\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})|./s',
            '$1',
            $value,
        ) ?? '';
    }

    /**
     * strtolower() as PHP 8.2+ has it: ASCII letters only. On PHP 8.1 strtolower() follows
     * LC_CTYPE, and after setlocale(LC_CTYPE, '*.UTF-8') on macOS/BSD it lowercases the lead
     * byte of à, É or д into invalid UTF-8, which PostgreSQL and SQL Server reject as a binding.
     */
    public static function lowerAscii(string $value): string
    {
        return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * levenshtein() is O(n·m) and similar_text() worse: on whole values a 127-character term
     * against 300 rows of 20KB took seconds. Every PHP scorer (FuzzySearch::levenshteinDistance()
     * and similarityPercentage(), SearchBuilder's rescoring, InMemorySearch) hands them only the
     * first SCORING_MAX_CHARS characters, never bytes. A string within the cap is returned byte
     * for byte (mb_substr() would turn an invalid byte into "?"), so it scores exactly as before.
     */
    public static function scoringInput(string $value): string
    {
        return mb_strlen($value, 'UTF-8') <= self::SCORING_MAX_CHARS ? $value : mb_substr($value, 0, self::SCORING_MAX_CHARS, 'UTF-8');
    }
}
