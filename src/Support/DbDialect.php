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
     * $tablePrefix is the connection's table prefix: the grammar writes the FROM table (and its
     * alias) with it, so the table part of a qualified column carries it too, as wrap() does.
     * SQLite leaves a bare column as written but quotes a qualified one: its table may be a
     * keyword (`order`, `values`) that SQLite reads as syntax.
     */
    public static function quoteIdentifier(string $column, string $driver, string $tablePrefix = ''): string
    {
        $parts     = explode('.', $column);
        $qualified = count($parts) > 1;

        if ($qualified) {
            $parts[0] = $tablePrefix . $parts[0];
        }

        $quoted = array_map(static function (string $part) use ($driver, $qualified): string {
            if (self::isMySqlFamily($driver)) {
                return '`' . str_replace('`', '``', $part) . '`';
            }

            return match (true) {
                $driver === self::PGSQL, $driver === self::SQLITE && $qualified => '"' . str_replace('"', '""', $part) . '"',
                $driver === self::SQLSRV => '[' . str_replace(']', ']]', $part) . ']',
                default                  => $part,
            };
        }, $parts);

        return implode('.', $quoted);
    }

    /**
     * A query's FROM as [table, alias]: the table as written (schema included) and its alias, or
     * null without one. null for a FROM that is not a plain table (fromSub()). The one place a
     * FROM is parsed, for everything that qualifies a column with the name the FROM goes by.
     *
     * @return array{string, ?string}|null
     */
    public static function fromTable(mixed $from): ?array
    {
        return is_string($from) ? array_pad(preg_split('/\s+as\s+/i', $from), 2, null) : null;
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

    /**
     * The LIKE escape character: backslash where it is the database's default (MySQL, MariaDB,
     * PostgreSQL), ! on SQLite and SQL Server, which have none and so take an ESCAPE clause.
     * Not '\' there: Laravel's toRawSql() and PDO's pre-8.4 SQL scanner read \' as an escaped quote.
     */
    public static function likeEscapeCharacter(string $driver): string
    {
        return $driver === self::SQLITE || $driver === self::SQLSRV ? '!' : '\\';
    }

    /** True where the escape character is not the database's default, so LIKE needs ESCAPE. */
    public static function needsLikeEscape(string $driver): bool
    {
        return self::likeEscapeCharacter($driver) !== '\\';
    }

    /**
     * A user's text for a LIKE pattern, matched literally: the escape character, % and _ get the
     * escape character in front, and so does [ on SQL Server, the one database where it opens a
     * character class. The pattern's own wildcards are added around (or between) escaped pieces,
     * never escaped. Send it with like() or whereLike().
     */
    public static function escapeLike(string $value, string $driver): string
    {
        $escape = self::likeEscapeCharacter($driver);
        $chars  = $driver === self::SQLSRV ? [$escape, '%', '_', '['] : [$escape, '%', '_'];

        return strtr($value, array_combine($chars, array_map(fn (string $c) => $escape . $c, $chars)));
    }

    /** "$column $operator ?" for a column already written as SQL, with ESCAPE where needsLikeEscape(). */
    public static function like(string $column, string $driver, string $operator = 'LIKE'): string
    {
        return "{$column} {$operator} ?"
            . (self::needsLikeEscape($driver) ? " ESCAPE '" . self::likeEscapeCharacter($driver) . "'" : '');
    }

    /**
     * Add "$column LIKE $pattern" to $query (a query or Eloquent builder) as the $boolean where.
     * PostgreSQL gets ILIKE (its LIKE is case-sensitive), SQLite and SQL Server like() with the
     * column through the query's grammar as where() writes it, MySQL and MariaDB Laravel's where().
     */
    public static function whereLike(object $query, string $column, string $pattern, string $driver, string $boolean = 'and'): void
    {
        $raw = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        if ($driver === self::PGSQL) {
            $query->$raw(self::like(self::quoteIdentifier($column, $driver, $query->getGrammar()->getTablePrefix()), $driver, 'ILIKE'), [$pattern]);
        } elseif (self::needsLikeEscape($driver)) {
            $query->$raw(self::like($query->getGrammar()->wrap($column), $driver), [$pattern]);
        } else {
            $query->{$boolean === 'or' ? 'orWhere' : 'where'}($column, 'LIKE', $pattern);
        }
    }
}
