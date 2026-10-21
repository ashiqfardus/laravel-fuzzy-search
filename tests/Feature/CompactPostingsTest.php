<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CompactNote extends Model
{
    use Searchable;

    protected $table   = 'compact_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10, 'body' => 5]];
}

/**
 * S2/S8 (owner decision, ruling ER-168). The postings table is clustered by term: its primary key is
 * (term_id, model_type_id, model_id, column_id), so a word's postings are one range of it, and each
 * posting carries its document's length, so the ranking reads no other table. A common word was read
 * as one random lookup per posting, joined to fuzzy_index_documents: 22 s for a word in 7% of 6M rows
 * on MySQL, 0.5 s on this layout.
 */
class CompactPostingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('compact_notes');
        Schema::create('compact_notes', function ($table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('compact_notes');

        parent::tearDown();
    }

    /** @return list<string> fuzzy_index_postings' columns, sorted */
    private function columns(): array
    {
        $columns = Schema::getColumnListing('fuzzy_index_postings');
        sort($columns);

        return $columns;
    }

    /** @return list<string> the primary key's columns in key order; and its INCLUDE columns on PostgreSQL */
    private function primaryKey(): array
    {
        $table = DB::connection()->getTablePrefix() . 'fuzzy_index_postings';

        $rows = match ($this->dbDriver) {
            'sqlite' => DB::select('select name as col from pragma_table_info(?) where pk > 0 order by pk', [$table]),
            'pgsql'  => DB::select(
                'select a.attname as col from pg_index i, unnest(i.indkey) with ordinality k(attnum, n), pg_attribute a'
                . ' where i.indrelid = to_regclass(quote_ident(?)) and i.indisprimary and a.attrelid = i.indrelid and a.attnum = k.attnum order by k.n',
                [$table]
            ),
            'sqlsrv' => DB::select(
                'select c.name as col from sys.indexes i join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id'
                . ' join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id'
                . " where i.object_id = object_id(?) and i.is_primary_key = 1 and i.type_desc = 'CLUSTERED' order by ic.key_ordinal",
                [$table]
            ),
            default  => DB::select(
                "select column_name as col from information_schema.statistics where table_schema = database() and table_name = ? and index_name = 'PRIMARY' order by seq_in_index",
                [$table]
            ),
        };

        return array_map(fn ($row) => strtolower((string) $row->col), $rows);
    }

    /** @return list<string> the columns of the index $name on the postings, in key order */
    private function indexColumns(string $name): array
    {
        $table = DB::connection()->getTablePrefix() . 'fuzzy_index_postings';

        $rows = match ($this->dbDriver) {
            'sqlite' => DB::select('select name as col from pragma_index_info(?) order by seqno', [$name]),
            'pgsql'  => DB::select(
                'select a.attname as col from pg_index i join pg_class c on c.oid = i.indexrelid, unnest(i.indkey) with ordinality k(attnum, n), pg_attribute a'
                . ' where i.indrelid = to_regclass(quote_ident(?)) and c.relname = ? and a.attrelid = i.indrelid and a.attnum = k.attnum and k.n <= i.indnkeyatts order by k.n',
                [$table, $name]
            ),
            'sqlsrv' => DB::select(
                'select c.name as col from sys.indexes i join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id'
                . ' join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id where i.object_id = object_id(?) and i.name = ? and ic.is_included_column = 0 order by ic.key_ordinal',
                [$table, $name]
            ),
            default  => DB::select(
                'select column_name as col from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? order by seq_in_index',
                [$table, $name]
            ),
        };

        return array_map(fn ($row) => strtolower((string) $row->col), $rows);
    }

    public function test_the_postings_are_clustered_by_term_and_carry_the_document_length(): void
    {
        $this->assertSame(['column_id', 'doc_length', 'frequency', 'model_id', 'model_type_id', 'term_id'], $this->columns());
        $this->assertSame(['term_id', 'model_type_id', 'model_id', 'column_id', ...($this->dbDriver === 'pgsql' ? ['frequency', 'doc_length'] : [])], $this->primaryKey(), 'the key, then PostgreSQL\'s INCLUDE columns');
        $this->assertSame(['model_type_id', 'model_id'], $this->indexColumns('postings_model_idx'));
        $this->assertTrue(Schema::hasTable('fuzzy_index_model_types'));
        $this->assertTrue(Schema::hasTable('fuzzy_index_columns'));

        if ($this->dbDriver === 'sqlite') {
            $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', DB::connection()->getTablePrefix() . 'fuzzy_index_postings')->value('sql');
            $this->assertStringContainsStringIgnoringCase('without rowid', $sql);
        }
    }

    /** @return array<string, list<array{int, int}>> model_id => each posting's doc_length and its document row's */
    private function lengths(): array
    {
        $documents = DB::table('fuzzy_index_documents')->where('model_type', CompactNote::class)->pluck('doc_length', 'model_id');
        $out       = [];
        foreach ($this->namedPostings()->where('model_type', CompactNote::class)->get(['model_id', 'doc_length']) as $posting) {
            $out[(string) $posting->model_id][] = [(int) $posting->doc_length, (int) ($documents[$posting->model_id] ?? -1)];
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    private function assertLengthsMatch(string $label): void
    {
        foreach ($this->lengths() as $id => $pairs) {
            foreach ($pairs as [$posted, $document]) {
                $this->assertSame($document, $posted, "{$label}: a posting of {$id} carries its document's length");
            }
        }
    }

    /**
     * VC-6: column names are shared by every model and never deleted, so a searchableText() keyed per
     * attribute, locale or custom field can pass a small integer's 32,767 (PostgreSQL) or 65,535
     * (MySQL): the dictionary's id and the postings' column_id hold a full integer.
     */
    public function test_a_column_id_past_a_small_integer_is_stored(): void
    {
        $note = CompactNote::create(['title' => 'Copper kettle', 'body' => 'boils water']);
        app(IndexManager::class)->indexModel($note);

        $posting = (array) DB::table('fuzzy_index_postings')->first();
        DB::table('fuzzy_index_postings')->where($posting)->update(['column_id' => 70000]);
        $this->assertSame(1, DB::table('fuzzy_index_postings')->where('column_id', 70000)->count(), 'column_id');
        DB::table('fuzzy_index_postings')->where('column_id', 70000)->update(['column_id' => $posting['column_id']]); // a rollback maps every column id to its name

        if ($this->dbDriver !== 'sqlsrv') { // SQL Server takes no explicit value in an identity column
            DB::table('fuzzy_index_columns')->insert(['id' => 70000, 'name' => 'attribute_70000']);
            $this->assertSame(70000, (int) DB::table('fuzzy_index_columns')->where('name', 'attribute_70000')->value('id'), 'dictionary id');
        }
    }

    public function test_every_posting_carries_its_documents_length_after_each_write(): void
    {
        config(['fuzzy-search.indexing.enabled' => true]); // index on save and delete
        $short = CompactNote::create(['title' => 'Copper kettle', 'body' => 'boils water']);
        $long  = CompactNote::create(['title' => 'Copper pan', 'body' => 'fries eggs and bacon slowly']);
        $this->assertLengthsMatch('created');
        $this->assertSame([[4, 4], [4, 4], [4, 4], [4, 4]], $this->lengths()[(string) $short->id]);

        $before = $this->lengths()[(string) $long->id][0][0];

        $short->update(['body' => 'boils water for tea every single morning']);
        $this->assertLengthsMatch('grown');
        $grown = $this->lengths()[(string) $short->id][0][0];
        $this->assertGreaterThan(4, $grown);

        $long->update(['body' => 'fries']);
        $this->assertLengthsMatch('shrunk');
        $this->assertLessThan($before, $this->lengths()[(string) $long->id][0][0]);

        $long->delete();
        $this->assertArrayNotHasKey((string) $long->id, $this->lengths(), 'deleted');

        DB::table('fuzzy_index_documents')->update(['doc_length' => 99]); // a stale copy the rebuild replaces
        $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => CompactNote::class, '--fresh' => true]));
        $this->assertLengthsMatch('rebuilt');
        $this->assertSame($grown, $this->lengths()[(string) $short->id][0][0]);

        // The ranking reads the length from the postings alone: a search ranks without the document rows.
        DB::table('fuzzy_index_documents')->delete();
        $this->assertSame([$short->id => true], array_map(fn () => true, app(Bm25Scorer::class)->rank(['tea'], CompactNote::class)));
    }

    /** @return list<array{string, array}> the statements rank() reads the postings with, and their bindings */
    private function rankReads(array $terms): array
    {
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$reads) {
            if (str_contains($query->sql, 'fuzzy_index_postings') && stripos($query->sql, 'group by') !== false) { // a word under the cap is read unordered
                $reads[] = [$query->sql, $query->bindings];
            }
        });
        try {
            $this->assertNotSame([], app(Bm25Scorer::class)->rank($terms, CompactNote::class, ['title' => 10, 'body' => 5]));
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $reads;
    }

    /** 3,000 notes, a word in one in fifty, analyzed. */
    private function seedNotes(): void
    {
        foreach (array_chunk(range(1, 3000), 500) as $chunk) {
            DB::table('compact_notes')->insert(array_map(fn ($i) => ['title' => sprintf($i % 50 === 0 ? 'rare walnut %05d' : 'plain oak %05d', $i), 'body' => 'shelf'], $chunk));
            app(IndexManager::class)->indexBatch(CompactNote::query()->whereIn('id', $chunk)->get());
        }
    }

    /** MySQL/MariaDB: the ranking reads a range of the postings' primary key, and never the document rows. */
    public function test_the_ranking_reads_a_range_of_the_postings_primary_key(): void
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB EXPLAIN; the CI MySQL and MariaDB jobs run this.');
        }

        $this->seedNotes();
        DB::statement('ANALYZE TABLE ' . implode(', ', array_map(fn ($t) => DB::getQueryGrammar()->wrapTable($t), ['fuzzy_index_postings', 'fuzzy_index_terms', 'fuzzy_index_documents'])));

        [[$sql, $bindings]] = $this->rankReads(['walnut']);
        $explain            = $this->dbDriver === 'mysql' ? 'explain format=traditional ' : 'explain '; // MySQL 9 may default to the tree format
        $plan               = array_map(fn ($row) => (array) $row, DB::select($explain . $sql, $bindings));
        $tables             = array_column($plan, 'table');

        $this->assertNotContains(DB::connection()->getTablePrefix() . 'd', $tables, 'no document rows');
        $this->assertStringNotContainsString('fuzzy_index_documents', $sql);
        $postings = array_values(array_filter($plan, fn ($row) => $row['table'] === DB::connection()->getTablePrefix() . 'p'))[0];
        $this->assertSame('PRIMARY', $postings['key'], json_encode($plan));
        $this->assertContains($postings['type'], ['ref', 'range'], json_encode($plan)); // a prefix of the key: ref for one word, range for several
    }

    /** PostgreSQL: the ranking is an Index Only Scan of the postings' primary key (its INCLUDE columns). */
    public function test_the_ranking_is_an_index_only_scan_of_the_postings_primary_key(): void
    {
        if ($this->dbDriver !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL EXPLAIN; the CI PostgreSQL job runs this.');
        }

        $this->seedNotes();

        [[$sql, $bindings]] = $this->rankReads(['walnut']);
        DB::statement('set enable_seqscan = off');
        DB::statement('set enable_bitmapscan = off');
        try {
            $plan = implode("\n", array_column(array_map(fn ($row) => (array) $row, DB::select('explain ' . $sql, $bindings)), 'QUERY PLAN'));
        } finally {
            DB::statement('reset enable_seqscan');
            DB::statement('reset enable_bitmapscan');
        }

        $this->assertMatchesRegularExpression('/Index Only Scan using postings_primary on \S*fuzzy_index_postings/', $plan);
        $this->assertStringNotContainsString('fuzzy_index_documents', $plan);
    }
}
