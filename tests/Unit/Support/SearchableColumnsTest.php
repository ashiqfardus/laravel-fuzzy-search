<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Support;

use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use PHPUnit\Framework\TestCase;

class SearchableColumnsTest extends TestCase
{
    /**
     * Which casts auto-detection may select. Laravel matches cast names case-insensitively
     * (getCastType() lower-cases them), so every spelling of a non-text cast must be rejected.
     */
    public function test_cast_matrix(): void
    {
        $cases = [
            // text-like: auto-detection may pick these
            null                      => true,
            'string'                  => true,
            'int'                     => true,
            'integer'                 => true,
            'float'                   => true,
            'double'                  => true,
            'real'                    => true,
            'bool'                    => true,
            'boolean'                 => true,
            'decimal:2'               => true,
            'date'                    => true,
            'datetime'                => true,
            'immutable_datetime'      => true,
            'timestamp'               => true,
            'STRING'                  => true,
            'Decimal:2'               => true,

            // never auto-detected: the indexer would write a decrypted value (or a hash) to the dictionary
            'encrypted'               => false,
            'Encrypted'               => false,
            'hashed'                  => false,
            'HASHED'                  => false,

            // not text: the indexer cannot turn these into a string
            'array'                   => false,
            'json'                    => false,
            'object'                  => false,
            'collection'              => false,
            'OBJECT'                  => false,
            'encrypted:array'         => false,
            'encrypted:object'        => false,
            'encrypted:collection'    => false,
            'ENCRYPTED:OBJECT'        => false,
            'Encrypted:Json'          => false,
            AsArrayObject::class      => false,
            AsCollection::class       => false,
            'App\\Enums\\Status'       => false,
            'App\\Casts\\Money'        => false,
        ];

        foreach ($cases as $cast => $expected) {
            // PHP array keys: the null case arrives as the empty string.
            $cast = $cast === '' ? null : (string) $cast;

            $this->assertSame(
                $expected,
                SearchableColumns::isTextLikeCast($cast),
                sprintf('cast %s', var_export($cast, true))
            );
        }
    }

    public function test_names_reads_both_declaration_forms(): void
    {
        $this->assertSame(['name', 'email'], SearchableColumns::names(['name' => 10, 'email' => 5]));
        $this->assertSame(['name', 'email'], SearchableColumns::names(['name', 'email']));
        $this->assertSame(['name', 'email'], SearchableColumns::names(['name' => 10, 'email']));
        $this->assertSame([], SearchableColumns::names([]));
    }
}
