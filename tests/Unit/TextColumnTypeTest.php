<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SearchableColumns::isTextType() matches whole type names, in any case and with any size or
 * parameters stripped. A substring match took a PostgreSQL enum named `charge_status` for text,
 * and its ILIKE failed exactly as a numeric column's did (#8).
 */
class TextColumnTypeTest extends TestCase
{
    public static function textTypes(): array
    {
        $names = [
            'char', 'CHAR', 'char(10)', 'varchar', 'VARCHAR(255)', 'character', 'character varying', 'Character Varying(20)',
            'bpchar', 'nchar', 'nvarchar', 'nvarchar(max)', 'national character varying', 'varying character', 'native character',
            'text', 'tinytext', 'mediumtext', 'longtext', 'ntext', 'citext', 'clob', 'string', 'enum', 'set',
            '', // an untyped SQLite column (ruling ER-60)
        ];

        return array_combine(array_map(fn ($n) => "[{$n}]", $names), array_map(fn ($n) => [$n], $names));
    }

    public static function otherTypes(): array
    {
        $names = [
            'charge_status', 'context_kind', 'textile_grade', 'my_varchar_domain', // PostgreSQL enums and domains
            '_text', '_varchar', 'json', 'jsonb', 'uuid', 'uniqueidentifier', 'int4', 'int8', 'bigint', 'integer', 'numeric',
            'decimal(10,2)', 'bool', 'boolean', 'bit', 'tinyint', 'date', 'datetime', 'timestamp', 'binary', 'varbinary', 'bytea',
            'blob', 'tsvector', 'xml',
        ];

        return array_combine(array_map(fn ($n) => "[{$n}]", $names), array_map(fn ($n) => [$n], $names));
    }

    #[DataProvider('textTypes')]
    public function test_a_text_type_is_text(string $type): void
    {
        $this->assertTrue(SearchableColumns::isTextType($type));
    }

    #[DataProvider('otherTypes')]
    public function test_any_other_type_is_not(string $type): void
    {
        $this->assertFalse(SearchableColumns::isTextType($type));
    }

    public function test_an_unknown_type_keeps_the_column(): void
    {
        $this->assertTrue(SearchableColumns::isTextType(null));
    }
}
