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
     * SQLite quotes it too: a column or its table may be a keyword (`group`, `order`, `desc`,
     * `values`) that SQLite reads as syntax, and a bare one made every ranked search throw.
     */
    public static function quoteIdentifier(string $column, string $driver, string $tablePrefix = ''): string
    {
        $parts     = explode('.', $column);
        $qualified = count($parts) > 1;

        if ($qualified) {
            $parts[0] = $tablePrefix . $parts[0];
        }

        $quoted = array_map(static function (string $part) use ($driver): string {
            if (self::isMySqlFamily($driver)) {
                return '`' . str_replace('`', '``', $part) . '`';
            }

            return match (true) {
                $driver === self::PGSQL, $driver === self::SQLITE => '"' . str_replace('"', '""', $part) . '"',
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

    /**
     * Character-length function name (not byte length). SQL Server's LEN counts UTF-16 code units under a
     * collation without _SC, so a character outside the BMP counts as two there, as NVARCHAR(n) does.
     */
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
     * The LIKE escape character: backslash on PostgreSQL, its default in every configuration, and
     * ! everywhere else, with an ESCAPE clause. SQLite and SQL Server have no default; MySQL and
     * MariaDB lose theirs under the NO_BACKSLASH_ESCAPES SQL mode, and ESCAPE '!' means the same
     * in every mode. Not ESCAPE '\': Laravel's toRawSql() and PDO's pre-8.4 SQL scanner read \'
     * as an escaped quote, and MySQL has no spelling of it that parses in every SQL mode.
     */
    public static function likeEscapeCharacter(string $driver): string
    {
        return $driver === self::PGSQL ? '\\' : '!';
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

    /** @var \WeakMap<\PDO, true>|null the SQLite PDOs fuzzy_lower() is registered on */
    private static ?\WeakMap $foldRegistered = null;

    /**
     * Whether SQLite's LIKE would compare $pattern case-sensitively: it folds ASCII letters only,
     * so a pattern with any other character ("лев" for "Лев", "é" for "É") needs the column and the
     * pattern lower-cased in PHP. Every other database folds case in LIKE itself.
     */
    public static function foldsInPhp(string $driver, string $pattern): bool
    {
        return $driver === 'sqlite' && preg_match('/[^\x00-\x7F]/', $pattern) === 1;
    }

    /** $pattern as it is bound to the LIKE that likeColumn() wrote: lower-cased where the column is. */
    public static function likePattern(string $pattern, string $driver): string
    {
        return self::foldsInPhp($driver, $pattern) ? mb_strtolower($pattern, 'UTF-8') : $pattern;
    }

    /**
     * $column (already written as SQL) as the LIKE for $pattern reads it: wrapped in the package's
     * own fuzzy_lower() on SQLite for a non-ASCII pattern, registered on the connection's PDO
     * here, and unchanged, byte for byte, everywhere else. SQLite's own lower() and like() are
     * never replaced, so the application's queries do not change.
     *
     * ponytail: one PHP call per row and column for a non-ASCII term on SQLite's LIKE path; an
     * indexed lower-cased shadow column would avoid it, if that cost matters.
     */
    public static function likeColumn(string $column, string $driver, string $pattern, \Illuminate\Database\ConnectionInterface $connection): string
    {
        if (!self::foldsInPhp($driver, $pattern)) {
            return $column;
        }

        self::registerSqliteFunctions($connection);

        return "fuzzy_lower({$column})";
    }

    /**
     * The package's own SQLite functions on the connection's PDO, once per PDO: fuzzy_lower(value),
     * and fuzzy_soundex(value, position), the soundex() code of the value's first (1) or last (-1)
     * word, null for a word SOUNDEX() would not encode from its first ASCII letter. Neither
     * replaces anything of SQLite's own.
     */
    public static function registerSqliteFunctions(\Illuminate\Database\ConnectionInterface $connection): void
    {
        self::registerFold($connection->getPdo());
        self::registerFold($connection->getReadPdo()); // the same object unless reads are split
    }

    private static function registerFold(\PDO|\Closure|null $pdo): void
    {
        if ($pdo instanceof \Closure) {
            $pdo = $pdo(); // a lazy read connection
        }
        if (!$pdo instanceof \PDO || $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }
        self::$foldRegistered ??= new \WeakMap();
        if (isset(self::$foldRegistered[$pdo])) {
            return;
        }

        $functions = [
            'fuzzy_lower'   => [fn ($value) => $value === null ? null : mb_strtolower((string) $value, 'UTF-8'), 1],
            'fuzzy_soundex' => [function ($value, $position) {
                $words = preg_split('/ +/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
                $word  = $words === [] || $words === false ? '' : ((int) $position < 0 ? end($words) : $words[0]);

                // As PostgreSQL's soundex() reads a word: ASCII non-letters, then an ASCII letter.
                return preg_match('/^[\x01-\x40\x5B-\x60\x7B-\x7F]*[A-Za-z]/', $word) === 1 ? soundex($word) : null;
            }, 2],
        ];

        foreach ($functions as $name => [$function, $arguments]) {
            if (method_exists($pdo, 'createFunction')) { // Pdo\Sqlite (PHP 8.4+, PDO::connect())
                $pdo->createFunction($name, $function, $arguments, 2048); // 2048: SQLITE_DETERMINISTIC
                continue;
            }

            // A plain PDO, as Laravel opens it: PHP 8.5 deprecates this name, Pdo\Sqlite has no way
            // to take such an object over, and the function has to be there either way.
            set_error_handler(fn () => true, E_DEPRECATED);
            try {
                $pdo->sqliteCreateFunction($name, $function, $arguments, 2048);
            } finally {
                restore_error_handler();
            }
        }

        self::$foldRegistered[$pdo] = true;
    }

    /**
     * Add "$column LIKE $pattern" to $query (a query or Eloquent builder) as the $boolean where.
     * PostgreSQL gets ILIKE (its LIKE is case-sensitive), every other database like() with the
     * column through the query's grammar as where() writes it.
     */
    public static function whereLike(object $query, string $column, string $pattern, string $driver, string $boolean = 'and'): void
    {
        $raw = $boolean === 'or' ? 'orWhereRaw' : 'whereRaw';

        $query->$raw(
            self::like(self::likeColumn(self::column($query, $column, $driver), $driver, $pattern, $query->getConnection()), $driver, self::likeOperator($driver)),
            [self::likePattern($pattern, $driver)]
        );
    }

    /**
     * "$column = ?" for a column already written as SQL, true only when the column holds exactly
     * the bound text, accents and letter case included, whatever its collation: MySQL's and
     * MariaDB's default collations, and a SQL Server _AI one, hold 'muñoz' = 'munoz'. The column is
     * read as Unicode first (utf8mb4, NVARCHAR), so a latin1 or varchar column compares its
     * characters, not its encoding's bytes; trailing spaces still compare equal (utf8mb4_bin pads,
     * and so does SQL Server's =). PostgreSQL's deterministic collations and SQLite's compare
     * exactly already.
     */
    public static function sameText(string $column, string $driver): string
    {
        return match (true) {
            self::isMySqlFamily($driver) => "CAST({$column} AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin = ?",
            $driver === self::SQLSRV     => "CAST({$column} AS NVARCHAR(MAX)) COLLATE Latin1_General_100_BIN2 = ?",
            default                      => "{$column} = ?",
        };
    }

    /**
     * $column as whereLike() writes it, for any other SQL that must name the same column: quoted
     * with the table prefix on PostgreSQL, through the query's grammar (as where() writes it)
     * everywhere else.
     */
    public static function column(object $query, string $column, string $driver): string
    {
        return $driver === self::PGSQL
            ? self::quoteIdentifier($column, $driver, $query->getGrammar()->getTablePrefix())
            : $query->getGrammar()->wrap($column);
    }

    /**
     * A table, or table.column, for raw SQL on the default connection (the one the index lives
     * on): as written when the connection has no table prefix, so that SQL stays byte-identical,
     * and through the grammar, prefix included, when it has one. The query builder prefixes an
     * alias as well ("fuzzy_index_postings as p" is written as pfx_p), so a raw reference to an
     * alias the builder declared goes through here too. The migrations use it as well.
     */
    public static function rawIdentifier(string $identifier): string
    {
        $connection = \Illuminate\Support\Facades\DB::connection();
        if ($connection->getTablePrefix() === '') {
            return $identifier;
        }

        $grammar = $connection->getQueryGrammar();

        return str_contains($identifier, '.') ? $grammar->wrap($identifier) : $grammar->wrapTable($identifier);
    }

    /**
     * The name of an index the migrations name themselves, on the default connection: led by the
     * connection's table prefix when it prefixes index names (`prefix_indexes`, Laravel's own rule
     * for the names it makes), so that installs with different prefixes can share a schema, where
     * PostgreSQL, SQLite and SQL Server need an index or constraint name to be unique. Without a
     * prefix, or without `prefix_indexes`, the name as written (ER-216).
     */
    public static function indexName(string $name): string
    {
        $connection = \Illuminate\Support\Facades\DB::connection();

        return ($connection->getConfig('prefix_indexes') ? $connection->getTablePrefix() : '') . $name;
    }

    /**
     * Which of an index's names $present holds (the names the migrations read from the catalog,
     * compared case-insensitively): indexName()'s, or the name as written, which an install made
     * before index names carried the prefix has. Null when it holds neither.
     *
     * @param list<string> $present
     */
    public static function presentIndexName(string $name, array $present): ?string
    {
        $present = array_map('strtolower', $present);
        foreach (array_unique([self::indexName($name), $name]) as $candidate) {
            if (in_array(strtolower($candidate), $present, true)) {
                return $candidate;
            }
        }

        return null;
    }
}
