<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;

class IndexSuggestTest extends TestCase
{
    private function index(): void
    {
        User::create(['name' => 'Johnathan Smithers', 'email' => 'johnathan@example.com']);
        User::create(['name' => 'John Smith', 'email' => 'jsmith@example.com']); // second document for "john" → doc_count 2, a unique maximum
        app(IndexManager::class)->indexBatch(User::all());
    }

    public function test_un_indexed_models_keep_the_table_scan(): void
    {
        $this->assertSame(0, DB::table('fuzzy_index_meta')->count());

        $suggestions = User::search('jo')->suggest(5);

        $this->assertContains('John', $suggestions);          // table scan proposes the word as written
    }

    public function test_indexed_models_complete_from_the_dictionary_ordered_by_doc_count(): void
    {
        $this->index();

        $suggestions = User::search('jo')->suggest(10);

        // The tokenizer splits emails on '@' and '.', so the jo* dictionary terms are john (2 docs:
        // John Doe + John Smith), jon, johnny, johnson, johnathan (1 doc each). Only "john" has a
        // unique maximum doc_count, so only index 0 is pinned.
        $this->assertSame('john', $suggestions[0]);
        $this->assertEqualsCanonicalizing(['john', 'jon', 'johnny', 'johnson', 'johnathan'], $suggestions);
        foreach ($suggestions as $s) {
            $this->assertStringStartsWith('jo', $s);
        }
    }

    public function test_the_dictionary_is_scoped_to_the_model(): void
    {
        $this->index();
        // A term only another model indexed must not be proposed for User.
        $termId = DB::table('fuzzy_index_terms')->insertGetId(['term' => 'joystick', 'doc_count' => 99, 'term_length' => 8]);
        DB::table('fuzzy_index_postings')->insert(['term_id' => $termId, 'model_type' => 'App\\Models\\Product', 'model_id' => '1', 'frequency' => 1, 'column_name' => 'name']);
        DB::table('fuzzy_index_meta')->insert(['model_type' => 'App\\Models\\Product', 'total_docs' => 1, 'total_tokens' => 1, 'avg_doc_length' => 1]); // the meta row is what marks a model as indexed (P6-R6)

        $this->assertNotContains('joystick', User::search('jo')->suggest(10));
        $this->assertContains('joystick', User::search('jo')->useInvertedIndex('App\\Models\\Product')->suggestFrom('index')->suggest(10));
    }

    public function test_multi_word_input_completes_the_last_token(): void
    {
        $this->index();

        $suggestions = User::search('Bob jo')->suggest(10);

        $this->assertSame('Bob john', $suggestions[0]);
        $this->assertContains('Bob johnson', $suggestions);
        foreach ($suggestions as $s) {
            $this->assertStringStartsWith('Bob jo', $s);
        }
    }

    public function test_suggest_from_table_forces_the_scan_and_index_forces_the_dictionary(): void
    {
        $this->index();

        $this->assertContains('John', User::search('jo')->suggestFrom('table')->suggest(5));
        $this->assertContains('john', User::search('jo')->suggestFrom('index')->suggest(5));
        // a model with no meta row and the dictionary forced → empty, not a table-scan fallback
        $this->assertSame([], User::search('jo')->useInvertedIndex('Nope\\Model')->suggestFrom('index')->suggest(5));
    }

    public function test_a_missing_dictionary_table_falls_back_to_the_table_scan(): void
    {
        $this->index();

        // Children first: fuzzy_index_postings carries the FK to fuzzy_index_terms.
        $this->app['db']->getSchemaBuilder()->drop('fuzzy_index_postings');
        $this->app['db']->getSchemaBuilder()->drop('fuzzy_index_terms');

        // The meta row still marks User as indexed, so suggestFromIndex() reaches the dictionary
        // query, fails, finds no fuzzy_index_terms table and hands the query to the table scan.
        $this->assertContains('John', User::search('jo')->suggest(5));
    }

    public function test_a_real_dictionary_error_surfaces_instead_of_falling_back(): void
    {
        $this->index();

        // fuzzy_index_terms is still there, so the failing semi-join against the postings table
        // is a real database error, not "the dictionary was never migrated".
        $this->app['db']->getSchemaBuilder()->drop('fuzzy_index_postings');

        $this->expectException(\Illuminate\Database\QueryException::class);
        User::search('jo')->suggest(5);
    }

    public function test_invalid_source_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        User::search('jo')->suggestFrom('elastic');
    }

    public function test_index_suggestions_are_case_insensitive_and_escape_like_metacharacters(): void
    {
        $this->index();

        $this->assertContains('john', User::search('JO')->suggest(5));
        $this->assertSame([], User::search('jo%')->suggest(5));
    }
}
