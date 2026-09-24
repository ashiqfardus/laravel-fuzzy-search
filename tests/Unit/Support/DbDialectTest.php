<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Support;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use PHPUnit\Framework\TestCase;

class DbDialectTest extends TestCase
{
    public function test_mysql_and_mariadb_are_the_mysql_family(): void
    {
        $this->assertTrue(DbDialect::isMySqlFamily('mysql'));
        $this->assertTrue(DbDialect::isMySqlFamily('mariadb'));
        $this->assertFalse(DbDialect::isMySqlFamily('pgsql'));
        $this->assertFalse(DbDialect::isMySqlFamily('sqlite'));
        $this->assertFalse(DbDialect::isMySqlFamily('sqlsrv'));
    }

    public function test_quote_identifier_per_driver(): void
    {
        $this->assertSame('`name`',  DbDialect::quoteIdentifier('name', 'mysql'));
        $this->assertSame('`name`',  DbDialect::quoteIdentifier('name', 'mariadb'));
        $this->assertSame('"name"',  DbDialect::quoteIdentifier('name', 'pgsql'));
        $this->assertSame('[name]',  DbDialect::quoteIdentifier('name', 'sqlsrv'));
        $this->assertSame('name',    DbDialect::quoteIdentifier('name', 'sqlite'));
    }

    public function test_quote_identifier_handles_table_qualified_names(): void
    {
        $this->assertSame('`users`.`name`',   DbDialect::quoteIdentifier('users.name', 'mariadb'));
        $this->assertSame('"users"."name"',   DbDialect::quoteIdentifier('users.name', 'pgsql'));
        $this->assertSame('[users].[name]',   DbDialect::quoteIdentifier('users.name', 'sqlsrv'));
    }

    public function test_quote_identifier_escapes_embedded_quote_characters(): void
    {
        $this->assertSame('`we``ird`', DbDialect::quoteIdentifier('we`ird', 'mysql'));
        $this->assertSame('"we""ird"', DbDialect::quoteIdentifier('we"ird', 'pgsql'));
        $this->assertSame('[we]]ird]', DbDialect::quoteIdentifier('we]ird', 'sqlsrv'));
    }

    public function test_length_function_per_driver(): void
    {
        $this->assertSame('CHAR_LENGTH', DbDialect::lengthFunction('mysql'));
        $this->assertSame('CHAR_LENGTH', DbDialect::lengthFunction('mariadb'));
        $this->assertSame('LEN',         DbDialect::lengthFunction('sqlsrv'));
        $this->assertSame('LENGTH',      DbDialect::lengthFunction('pgsql'));
        $this->assertSame('LENGTH',      DbDialect::lengthFunction('sqlite'));
    }

    public function test_like_operator_is_ilike_only_on_postgres(): void
    {
        $this->assertSame('ILIKE', DbDialect::likeOperator('pgsql'));
        foreach (['mysql', 'mariadb', 'sqlite', 'sqlsrv'] as $driver) {
            $this->assertSame('LIKE', DbDialect::likeOperator($driver), $driver);
        }
    }

    public function test_the_like_escape_character_is_backslash_where_it_is_the_default_and_bang_elsewhere(): void
    {
        foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
            $this->assertSame('\\', DbDialect::likeEscapeCharacter($driver), $driver);
        }
        $this->assertSame('!', DbDialect::likeEscapeCharacter('sqlite'));
        $this->assertSame('!', DbDialect::likeEscapeCharacter('sqlsrv'));
    }

    public function test_escape_like_escapes_the_metacharacters_with_the_escape_character(): void
    {
        // Backslash databases: \ % _ (a ! or [ is ordinary). SQLite: ! % _ (a \ is ordinary).
        // SQL Server: ! % _ and [, which opens a character class there.
        foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
            $this->assertSame('a\\\\b\\%\\_[c]!', DbDialect::escapeLike('a\\b%_[c]!', $driver), $driver);
        }
        $this->assertSame('a\\b!%!_[c]!!', DbDialect::escapeLike('a\\b%_[c]!', 'sqlite'));
        $this->assertSame('a\\b!%!_![c]!!', DbDialect::escapeLike('a\\b%_[c]!', 'sqlsrv'));
        $this->assertSame('Straße ঠ', DbDialect::escapeLike('Straße ঠ', 'sqlsrv'));
    }

    public function test_like_adds_an_escape_clause_only_where_the_database_has_no_default_escape(): void
    {
        $this->assertSame("col LIKE ? ESCAPE '!'", DbDialect::like('col', 'sqlite'));
        $this->assertSame("col LIKE ? ESCAPE '!'", DbDialect::like('col', 'sqlsrv'));
        $this->assertSame('col LIKE ?', DbDialect::like('col', 'mysql'));
        $this->assertSame('col LIKE ?', DbDialect::like('col', 'mariadb'));
        $this->assertSame('col ILIKE ?', DbDialect::like('col', 'pgsql', 'ILIKE'));
    }
}
