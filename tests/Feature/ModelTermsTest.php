<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * fuzzy_index_model_terms (ruling ER-197, T-1): per model type, the dictionary's words it posts,
 * with its own document count.
 */
class ModelTermsTest extends TestCase
{
    /**
     * The prefix read's key holds the word as the dictionary keys it: on MySQL/MariaDB term(191),
     * which fits 767 bytes, so the table is created where the dictionary is (a COMPACT or REDUNDANT
     * row format: an upgraded MySQL, an older MariaDB); on PostgreSQL with varchar_pattern_ops,
     * which serves the prefix's LIKE as a range.
     */
    public function test_the_prefix_key_fits_where_the_dictionary_does(): void
    {
        $table = DB::connection()->getTablePrefix() . 'fuzzy_index_model_terms';

        if (in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $parts = [];
            foreach (DB::select("show index from `{$table}` where Key_name = 'model_terms_term_idx'") as $row) {
                $row                                = (object) $row;
                $parts[(string) $row->Column_name] = $row->Sub_part === null ? null : (int) $row->Sub_part;
            }
            $this->assertSame(['model_type_id' => null, 'term' => 191], $parts);

            // Under the 767-byte limit a full key on the 255-character term (1,020 bytes) is refused (1071).
            DB::statement("alter table `{$table}` row_format = compact");
            $this->assertSame('Compact', DB::selectOne('select row_format as f from information_schema.tables where table_schema = database() and table_name = ?', [$table])->f);

            return;
        }

        if ($this->dbDriver === 'pgsql') {
            $this->assertStringContainsString('(model_type_id, term varchar_pattern_ops)', (string) DB::selectOne('select indexdef from pg_indexes where indexname = ?', ['model_terms_term_idx'])->indexdef);

            return;
        }

        $this->markTestSkipped('MySQL, MariaDB and PostgreSQL key the term their own way; SQLite and SQL Server take the whole column.');
    }
}
