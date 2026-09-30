<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TermCollationItem extends Model
{
    use Searchable;

    protected $table    = 'term_collation_items';
    protected $guarded  = [];
    public $timestamps  = false;

    protected array $searchable = ['columns' => ['title' => 1]];
}

/**
 * RC-1. SQL Server compared fuzzy_index_terms.term under the database's collation, which folds
 * words the tokenizer keeps apart: straße and strasse, cœur and coeur, m² and m2, full- and
 * half-width forms, hiragana and katakana (SQL_Latin1_General_CP1_CI_AS, the default). A rebuild
 * holding both spellings died on the unique key; single writes raised the first spelling's
 * doc_count and posted nothing for the second, which no search then found. 2026_09_30_000001
 * gives the column Latin1_General_100_BIN2, as 2026_09_17_000002 gave MySQL/MariaDB utf8mb4_bin.
 */
class SqlServerTermCollationTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/2026_09_30_000001_binary_collation_on_fuzzy_index_terms_term_on_sqlsrv.php';

    /** Each pair is two words to the tokenizer and one to SQL_Latin1_General_CP1_CI_AS. */
    private const PAIRS = [['straße', 'strasse'], ['cœur', 'coeur'], ['m²', 'm2'], ['ａｂｃｄ', 'abcd'], ['カタカナ', 'かたかな']];

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->dbDriver !== 'sqlsrv') {
            $this->markTestSkipped('SQL Server only: the other drivers compare term byte-wise already (MySQL/MariaDB since 2026_09_17_000002); the CI SQL Server jobs run this.');
        }

        Schema::dropIfExists('term_collation_items');
        Schema::create('term_collation_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
    }

    protected function tearDown(): void
    {
        if ($this->dbDriver === 'sqlsrv') {
            Schema::dropIfExists('term_collation_items');
        }

        parent::tearDown();
    }

    /** @return list<string> */
    private function words(): array
    {
        return array_merge(...self::PAIRS);
    }

    /** Every word is its own term, whose doc_count is the number of rows posted under it. */
    private function assertEveryTermCountsItsPostings(int $perWord): void
    {
        $rows = DB::table('fuzzy_index_terms as t')
            ->leftJoin('fuzzy_index_postings as p', 'p.term_id', '=', 't.id')
            ->groupBy('t.term', 't.doc_count')
            ->selectRaw('t.term, t.doc_count, count(distinct p.model_id) as postings')
            ->get();

        $this->assertEqualsCanonicalizing($this->words(), $rows->pluck('term')->map(fn ($t) => (string) $t)->all());
        foreach ($rows as $row) {
            $this->assertSame([$perWord, $perWord], [(int) $row->doc_count, (int) $row->postings], "term {$row->term}");
        }
    }

    private function assertEveryRowIsFoundByItsOwnSpelling(): void
    {
        foreach ($this->words() as $word) {
            $found = TermCollationItem::search($word)->useInvertedIndex()->typoTolerance(0)->get()->pluck('title')->all();
            $this->assertContains($word, $found, "search '{$word}'");
        }
    }

    public function test_single_saves_index_every_spelling_under_its_own_term(): void
    {
        config(['fuzzy-search.indexing.enabled' => true]);

        foreach ($this->words() as $word) {
            TermCollationItem::create(['title' => $word]);
        }

        $this->assertEveryTermCountsItsPostings(1);
        $this->assertSame(10, DB::table('fuzzy_index_documents')->count());
        $this->assertEveryRowIsFoundByItsOwnSpelling();
    }

    public function test_a_rebuild_indexes_every_spelling_under_its_own_term(): void
    {
        DB::table('term_collation_items')->insert(array_map(fn ($word) => ['title' => $word], $this->words()));

        $this->artisan('fuzzy-search:rebuild', ['model' => TermCollationItem::class, '--fresh' => true])->assertExitCode(0);

        $this->assertEveryTermCountsItsPostings(1);
        $this->assertSame(10, DB::table('fuzzy_index_documents')->count());
        $this->assertEveryRowIsFoundByItsOwnSpelling();
    }

    public function test_one_row_holding_both_spellings_is_indexed(): void
    {
        config(['fuzzy-search.indexing.enabled' => true]);

        $item = TermCollationItem::create(['title' => implode(' ', $this->words())]);

        $this->assertEveryTermCountsItsPostings(1);
        $this->assertSame(10, (int) DB::table('fuzzy_index_documents')->where('model_id', (string) $item->id)->value('doc_length'));
    }

    /**
     * An install upgraded from 2.0.1: term under the database's collation, rows in it, and an
     * index an app added beside the unique key (descending, with an INCLUDE and a filter). The
     * migration converts the column, keeps the rows, recreates both indexes as they were, and
     * does nothing when it runs again.
     */
    public function test_the_migration_converts_a_dictionary_under_the_database_collation(): void
    {
        $table = DB::connection()->getTablePrefix() . 'fuzzy_index_terms';
        Schema::table('fuzzy_index_terms', fn (Blueprint $t) => $t->dropUnique('fuzzy_index_terms_term_unique'));
        DB::statement("ALTER TABLE {$table} ALTER COLUMN term NVARCHAR(255) COLLATE DATABASE_DEFAULT NOT NULL");
        Schema::table('fuzzy_index_terms', fn (Blueprint $t) => $t->unique('term', 'fuzzy_index_terms_term_unique'));
        DB::statement("CREATE INDEX terms_term_lookup ON {$table} (term DESC) INCLUDE (doc_count) WHERE doc_count > 0");

        foreach (['straße', 'cœur', 'm²'] as $term) {
            DB::table('fuzzy_index_terms')->insert(['term' => $term, 'doc_count' => 1, 'term_length' => mb_strlen($term)]);
        }
        $this->assertNotSame('Latin1_General_100_BIN2', $this->collation());
        $this->assertInsertFails('strasse'); // the database's collation folds it into straße
        $before = $this->indexes();
        $this->assertSame([
            ['fuzzy_index_terms_term_unique', 1, 'term,', null],
            ['terms_term_lookup', 0, 'term desc,+doc_count,', '([doc_count]>(0))'],
        ], $before);

        $migration = require self::MIGRATION;
        $migration->up();

        $this->assertSame('Latin1_General_100_BIN2', $this->collation());
        $this->assertSame($before, $this->indexes());
        $this->assertSame(['cœur', 'm²', 'straße'], DB::table('fuzzy_index_terms')->pluck('term')->sort()->values()->all());
        DB::table('fuzzy_index_terms')->insert(['term' => 'strasse', 'doc_count' => 1, 'term_length' => 7]);
        $this->assertInsertFails('straße'); // the unique key holds

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });
        $migration->up();
        $this->assertSame([], preg_grep('/^\s*(alter|drop|create)\b/i', $statements), 'a second run changes nothing');
    }

    /** A connection with a table prefix: the migration finds the prefixed table and its unique key. */
    public function test_the_migration_converts_a_prefixed_dictionary(): void
    {
        config(['database.connections.term_prefixed' => ['prefix' => 'tcp_'] + config('database.connections.integration')]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('term_prefixed');

        try {
            Schema::dropIfExists('fuzzy_index_terms');
            (require __DIR__ . '/../../database/migrations/2026_05_02_205327_create_fuzzy_index_terms_table.php')->up();
            $this->assertNotSame('Latin1_General_100_BIN2', $this->collation());
            $before = $this->indexes();

            (require self::MIGRATION)->up();

            $this->assertSame('Latin1_General_100_BIN2', $this->collation());
            $this->assertSame($before, $this->indexes());
            $this->assertSame([1], array_column($before, 1)); // the unique key, under the name the prefix gave it
        } finally {
            Schema::dropIfExists('fuzzy_index_terms');
            DB::setDefaultConnection($default);
            DB::purge('term_prefixed');
        }
    }

    private function collation(): ?string
    {
        return DB::selectOne(
            "select collation_name as c from sys.columns where object_id = object_id(?) and name = 'term'",
            [DB::connection()->getTablePrefix() . 'fuzzy_index_terms']
        )?->c;
    }

    /** @return list<array{string, int, string, ?string}> name, unique, columns, filter of each index on term */
    private function indexes(): array
    {
        $rows = DB::select(
            "select i.name, i.is_unique, i.filter_definition, c.name as col, ic.is_descending_key, ic.is_included_column
             from sys.indexes i
             join sys.index_columns ic on ic.object_id = i.object_id and ic.index_id = i.index_id
             join sys.columns c on c.object_id = ic.object_id and c.column_id = ic.column_id
             where i.object_id = object_id(?)
             order by i.name, ic.is_included_column, ic.key_ordinal, ic.index_column_id",
            [DB::connection()->getTablePrefix() . 'fuzzy_index_terms']
        );

        $indexes = [];
        $onTerm  = [];
        foreach ($rows as $row) {
            $indexes[$row->name] ??= [$row->name, (int) $row->is_unique, '', $row->filter_definition];
            $indexes[$row->name][2] .= ($row->is_included_column ? '+' : '') . $row->col . ($row->is_descending_key ? ' desc' : '') . ',';
            if ($row->col === 'term') {
                $onTerm[$row->name] = true;
            }
        }

        return array_values(array_intersect_key($indexes, $onTerm));
    }

    private function assertInsertFails(string $term): void
    {
        try {
            DB::table('fuzzy_index_terms')->insert(['term' => $term, 'doc_count' => 1, 'term_length' => mb_strlen($term)]);
            $this->fail("the unique key let a second '{$term}' in");
        } catch (QueryException $e) {
            $this->assertStringContainsString('fuzzy_index_terms_term_unique', $e->getMessage());
        }
    }
}
