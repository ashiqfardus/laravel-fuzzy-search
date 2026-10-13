<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * fuzzy_index_model_terms (ruling ER-197, T-1): per model type, the dictionary's words it posts,
 * with its own document count.
 */
class ModelTermsTest extends TestCase
{
    /**
     * Every write path keeps each type's counts equal to its postings, and their sum per word equal
     * to the dictionary's doc_count, two types sharing words: an observed save, update, soft delete,
     * restore and force delete; IndexModelJob for a row deleted without events; removeFromIndex();
     * rebuild, rebuild --fresh, clear <model> and clear --all. Scout's update and delete, and the
     * single-table-inheritance refiles, are checked after every test of ScoutEngineTest and
     * SingleTableInheritanceRebuildTest.
     */
    public function test_each_types_counts_follow_every_write_path(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $manager = app(IndexManager::class);

        $manager->indexBatch(SoftDeletedUser::all());
        $manager->indexBatch(Product::all());
        $this->assertModelTermsMirrorPostings('indexing both types');

        $user = SoftDeletedUser::create(['name' => 'Apple Pro Smith', 'email' => 'x@example.com']); // words both types hold
        Product::create(['title' => 'Smith Apple', 'description' => 'pro tablet']);
        $this->assertModelTermsMirrorPostings('an observed create');

        $user->update(['name' => 'Zebulon Quixote']);
        $this->assertModelTermsMirrorPostings('an update to new words');
        $user->update(['name' => 'Zebulon Quixote']);
        $user->touch();
        $this->assertModelTermsMirrorPostings('a save that changes no word');

        $user->delete();
        $this->assertModelTermsMirrorPostings('a soft delete');
        $user->restore();
        $this->assertModelTermsMirrorPostings('a restore');
        $user->forceDelete();
        $this->assertModelTermsMirrorPostings('a force delete');

        $gone = (int) DB::table('users')->min('id');
        DB::table('users')->where('id', $gone)->delete(); // no model event
        (new IndexModelJob(SoftDeletedUser::class, $gone))->handle($manager);
        $this->assertModelTermsMirrorPostings('IndexModelJob for a row deleted without events');

        $manager->removeFromIndex(SoftDeletedUser::class, (string) DB::table('users')->max('id'));
        $this->assertModelTermsMirrorPostings('removeFromIndex()');

        DB::table('products')->where('title', 'like', 'iPhone%')->update(['title' => 'Quixote Phone']);
        $this->artisan('fuzzy-search:rebuild', ['model' => Product::class])->assertExitCode(0);
        $this->assertModelTermsMirrorPostings('a rebuild');
        $this->artisan('fuzzy-search:rebuild', ['model' => Product::class, '--fresh' => true])->assertExitCode(0);
        $this->assertModelTermsMirrorPostings('a rebuild --fresh');

        $this->artisan('fuzzy-search:clear', ['model' => Product::class])->assertExitCode(0);
        $this->assertModelTermsMirrorPostings('clear <model>');
        $this->assertSame(0, DB::table('fuzzy_index_model_terms')->where('model_type_id', IndexManager::modelTypeId(Product::class))->count());
        $this->assertGreaterThan(0, DB::table('fuzzy_index_model_terms')->count());

        $this->artisan('fuzzy-search:clear', ['--all' => true])->assertExitCode(0);
        $this->assertModelTermsMirrorPostings('clear --all');
        $this->assertSame(0, DB::table('fuzzy_index_model_terms')->count());
    }

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
