<?php

namespace Ashiqfardus\LaravelFuzzySearch\Support;

/**
 * Driver-name helpers shared by every class that emits raw SQL.
 *
 * Laravel 11+ reports MariaDB connections as "mariadb" (Laravel 10 reports "mysql").
 * Every SQL branch that means "MySQL syntax" must accept both, which is what
 * isMySqlFamily() is for. Identifier quoting was previously copied in four places.
 *
 * @internal Not part of the public API.
 */
final class DbDialect
{
    public const MYSQL  = 'mysql';
    public const MARIADB = 'mariadb';
    public const PGSQL  = 'pgsql';
    public const SQLITE = 'sqlite';
    public const SQLSRV = 'sqlsrv';

    public static function isMySqlFamily(string $driver): bool
    {
        return $driver === self::MYSQL || $driver === self::MARIADB;
    }

    /**
     * Quote a (possibly table-qualified) column for the given driver.
     * Splits on "." so `users.name` becomes `users`.`name`, not a single identifier.
     */
    public static function quoteIdentifier(string $column, string $driver): string
    {
        $parts = explode('.', $column);

        $quoted = array_map(static function (string $part) use ($driver): string {
            if (self::isMySqlFamily($driver)) {
                return '`' . str_replace('`', '``', $part) . '`';
            }

            return match ($driver) {
                self::PGSQL  => '"' . str_replace('"', '""', $part) . '"',
                self::SQLSRV => '[' . str_replace(']', ']]', $part) . ']',
                default      => $part,
            };
        }, $parts);

        return implode('.', $quoted);
    }

    /**
     * A string's length as the strictest supported column counts it. VARCHAR(n) holds n
     * characters on MySQL, MariaDB, PostgreSQL and SQLite, but SQL Server's nvarchar(n) holds n
     * UTF-16 code units, and a character outside the BMP (emoji, CJK Extension B, Gothic) is two.
     */
    public static function varcharLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8') + (int) preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
    }

    /** Cut $value to at most $max varcharLength() units without splitting a character. */
    public static function truncateToVarchar(string $value, int $max): string
    {
        $value = mb_substr($value, 0, $max, 'UTF-8');

        while (self::varcharLength($value) > $max) {
            $value = mb_substr($value, 0, -1, 'UTF-8');
        }

        return $value;
    }

    /** Character-length function name (not byte length). */
    public static function lengthFunction(string $driver): string
    {
        if (self::isMySqlFamily($driver)) {
            return 'CHAR_LENGTH';
        }

        return $driver === self::SQLSRV ? 'LEN' : 'LENGTH';
    }

    /** Case-insensitive LIKE operator: PostgreSQL needs ILIKE, everything else is already insensitive. */
    public static function likeOperator(string $driver): string
    {
        return $driver === self::PGSQL ? 'ILIKE' : 'LIKE';
    }
}
