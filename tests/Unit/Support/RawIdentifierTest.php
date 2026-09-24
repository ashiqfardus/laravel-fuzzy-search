<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit\Support;

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * DbDialect::rawIdentifier(): a table or table.column for raw SQL on the default connection,
 * as written without a table prefix (the SQL stays byte-identical) and through the grammar,
 * prefix included, with one. The migrations and the indexer's raw statements use it.
 */
class RawIdentifierTest extends TestCase
{
    public function test_it_is_the_identifier_as_written_without_a_prefix(): void
    {
        $this->assertSame('fuzzy_index_terms', DbDialect::rawIdentifier('fuzzy_index_terms'));
        $this->assertSame('p.frequency', DbDialect::rawIdentifier('p.frequency'));
    }

    public function test_it_carries_the_prefix_the_builder_writes_on_tables_and_aliases(): void
    {
        $connection = DB::connection();
        $connection->setTablePrefix('pfx_');

        try {
            $grammar = $connection->getQueryGrammar();
            $this->assertSame($grammar->wrapTable('fuzzy_index_terms'), DbDialect::rawIdentifier('fuzzy_index_terms'));
            $this->assertStringContainsString('pfx_fuzzy_index_terms', DbDialect::rawIdentifier('fuzzy_index_terms'));
            // The builder writes "fuzzy_index_postings as p" with the alias prefixed as well.
            $this->assertStringContainsString('pfx_p', DbDialect::rawIdentifier('p.frequency'));
            $this->assertStringContainsString('pfx_p', DB::table('fuzzy_index_postings as p')->select('p.frequency')->toSql());
        } finally {
            $connection->setTablePrefix('');
        }
    }
}
