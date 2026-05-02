<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class IndexManagerTest extends TestCase
{
    public function test_fuzzy_index_terms_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_terms'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'term'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_terms', 'doc_count'));
    }

    public function test_fuzzy_index_postings_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_postings'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'term_id'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_type'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'model_id'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_postings', 'frequency'));
    }

    public function test_fuzzy_index_meta_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('fuzzy_index_meta'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'model_type'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'total_docs'));
        $this->assertTrue(Schema::hasColumn('fuzzy_index_meta', 'avg_doc_length'));
    }
}
