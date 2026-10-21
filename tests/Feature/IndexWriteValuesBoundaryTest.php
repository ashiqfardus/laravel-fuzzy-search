<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BoundaryItem extends Model
{
    use Searchable;

    protected $table      = 'boundary_items';
    protected $guarded    = [];
    public $timestamps    = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/**
 * N-B4 (M-3 item 3). Under its index write lock SQL Server inserts the rows a write adds and updates
 * the ones it changes by key, one INSERT … SELECT … FROM (VALUES …) or UPDATE … FROM (VALUES …) per
 * 2,000 bindings, where SQL Server takes 1,000 rows a VALUES at most: 666 rows of the documents' three
 * columns, 1,000 of the dictionary's two (its doc_count changes), 400 of the model terms' five. A
 * write of 1,001 rows and 1,001 words crosses each boundary, and a key given twice is written once.
 * The same writes run on every database; only SQL Server sends these statements.
 */
class IndexWriteValuesBoundaryTest extends TestCase
{
    private const ROWS = 1001;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('boundary_items');
        Schema::create('boundary_items', function ($table) {
            $table->id();
            $table->string('title');
        });
        foreach (array_chunk(range(1, self::ROWS), 250) as $chunk) {
            DB::table('boundary_items')->insert(array_map(fn (int $n) => ['title' => self::word('zq', $n)], $chunk));
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('boundary_items');

        parent::tearDown();
    }

    /** A word of letters only for $n, one per row: no stemmer or stop list touches it. */
    private static function word(string $prefix, int $n): string
    {
        return $prefix . strtr(base_convert((string) $n, 10, 26), '0123456789abcdefghijklmnop', 'abcdefghijklmnopqrstuvwxyz') . 'q';
    }

    public function test_writes_across_the_values_row_limits_keep_every_count_exact(): void
    {
        $sent = [];
        DB::listen(function ($query) use (&$sent) {
            if (preg_match('/\(values /i', $query->sql)) {
                $sent[] = substr_count($query->sql, '), (') + 1;
            }
        });

        // 1,001 new documents and words in one write: the documents' lengths go out by key in
        // statements of 666 rows and fewer.
        $this->assertSame(self::ROWS, app(IndexManager::class)->indexBatch(BoundaryItem::all()));
        $this->assertExact();
        $this->assertSame([777], BoundaryItem::search(self::word('zq', 777))->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->map(fn ($id) => (int) $id)->all());

        // Every row gives back its one word and takes another: 1,001 doc_count changes of existing
        // words, out in statements of 1,000 rows and fewer, and 1,001 model terms changed.
        DB::table('boundary_items')->update(['title' => DB::raw("replace(title, 'zq', 'yq')")]);
        app(IndexManager::class)->indexBatch(BoundaryItem::all());
        $this->assertExact();
        $this->assertSame([], BoundaryItem::search(self::word('zq', 777))->useInvertedIndex()->typoTolerance(0)->get()->all());
        $this->assertSame([777], BoundaryItem::search(self::word('yq', 777))->useInvertedIndex()->typoTolerance(0)->get()->pluck('id')->map(fn ($id) => (int) $id)->all());

        // Each row given twice in one write, and keys given twice to a removal: written once.
        $items = BoundaryItem::query()->where('id', '<=', 700)->get();
        app(IndexManager::class)->indexBatch($items->concat($items));
        $this->assertExact();
        app(IndexManager::class)->removeManyFromIndex(BoundaryItem::class, [1, '1', 2, 2, '3']);
        $this->assertExact();
        $this->assertSame(self::ROWS - 3, DB::table('fuzzy_index_documents')->where('model_type', BoundaryItem::class)->count());

        if ($this->dbDriver === 'sqlsrv') {
            $this->assertNotEmpty($sent);
            $this->assertLessThanOrEqual(1000, max($sent), 'no VALUES of more than 1,000 rows');
            $this->assertContains(1000, $sent, 'the dictionary\'s changes at the limit');
            $this->assertContains(666, $sent, 'the documents\' lengths at their bindings\' limit');
        }
    }

    /** Every word's doc_count, the model's own counts and the meta a fresh count of the postings. */
    private function assertExact(): void
    {
        $held = DB::query()->fromSub(DB::table('fuzzy_index_postings')->distinct()->select(['term_id', 'model_id']), 'held')
            ->select('term_id')->selectRaw('count(*) as documents')->groupBy('term_id')->pluck('documents', 'term_id')
            ->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])->sortKeys()->all();
        $counts = DB::table('fuzzy_index_terms')->pluck('doc_count', 'id')->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])->filter()->sortKeys()->all();
        $own    = DB::table('fuzzy_index_model_terms')->pluck('doc_count', 'term_id')->mapWithKeys(fn ($count, $id) => [(int) $id => (int) $count])->sortKeys()->all();

        $this->assertSame($held, $counts, 'doc_count');
        $this->assertSame($held, $own, 'the model\'s own counts');
        $documents = DB::table('fuzzy_index_documents')->where('model_type', BoundaryItem::class)->count();
        $this->assertSame($documents, (int) DB::table('fuzzy_index_meta')->where('model_type', BoundaryItem::class)->value('total_docs'));
        $this->assertSame(
            (int) DB::table('fuzzy_index_documents')->where('model_type', BoundaryItem::class)->sum('doc_length'),
            (int) DB::table('fuzzy_index_meta')->where('model_type', BoundaryItem::class)->value('total_tokens')
        );
    }
}
