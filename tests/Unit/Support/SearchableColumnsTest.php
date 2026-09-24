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
            ''                        => true, // no cast (null) — see the loop
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
            // A null key is deprecated since PHP 8.5, so the no-cast case is keyed '' and mapped back.
            $cast = $cast === '' ? null : (string) $cast;

            $this->assertSame(
                $expected,
                SearchableColumns::isTextLikeCast($cast),
                sprintf('cast %s', var_export($cast, true))
            );
        }
    }

    /** Auto-detection never picks a column whose name says it holds a secret. */
    public function test_secret_names(): void
    {
        $secret = [
            'password', 'Password', 'password_hash', 'app_password', 'old_passwords',
            'token', 'secret', 'api_key', 'API_KEY', 'api_secret', 'access_token', 'refresh_token',
            'client_secret', 'private_key', 'otp_secret', 'recovery_codes', 'remember_token',
            'api_token', 'invite_token', 'Reset_Token', 'webhook_secret', 'two_factor_secret',
            'two_factor_recovery_codes',
        ];
        $ordinary = [
            'name', 'nickname', 'title', 'sort_key', 'lookup_key', 'idempotency_key', 'token_count',
            'tokens', 'secretary', 'secret_santa_name', 'keyword', 'description',
        ];

        foreach ($secret as $column) {
            $this->assertTrue(SearchableColumns::isSecretName($column), "{$column} should be secret");
        }
        foreach ($ordinary as $column) {
            $this->assertFalse(SearchableColumns::isSecretName($column), "{$column} should not be secret");
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
