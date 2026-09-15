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
}
