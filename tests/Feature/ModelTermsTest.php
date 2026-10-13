<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Indexing\Bm25Scorer;
use Ashiqfardus\LaravelFuzzySearch\Tests\Product;
use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Another model's codes: 'w00001 abc00001', one word of six characters and one under the prefix abc a row. */
class ModelTermsCode extends Model
{
    use Searchable;

    protected $table   = 'model_terms_codes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['code' => 1]];
}

/** The users, with email hidden: suggest() and didYouMean() hand words back, so never an email's. */
class ModelTermsHiddenUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];
    protected $hidden  = ['email'];

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5]];
}

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
     * rebuild --fresh leaves the dictionary exact where its count of a word had drifted: a count one
     * too low on a word both types hold (an upgrade's stale postings taken off twice) and one too
     * high on a word only the users hold (2.0's). The flush counts each of the model's words again
     * from the other types' own counts, rather than take its share off (R13-L7).
     */
    public function test_a_flush_counts_the_models_words_again_from_the_other_models(): void
    {
        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
        $manager = app(IndexManager::class);
        $manager->indexBatch(SoftDeletedUser::all());
        $manager->indexBatch(Product::all());
        SoftDeletedUser::create(['name' => 'Apple Pro Smith', 'email' => 'x@example.com']);
        Product::create(['title' => 'Smith Apple', 'description' => 'pro tablet']);
        $this->assertModelTermsMirrorPostings('before the drift');

        $own    = fn (string $class) => DB::table('fuzzy_index_model_terms')->where('model_type_id', IndexManager::modelTypeId($class))->pluck('term_id')->map(fn ($id) => (int) $id)->all();
        $shared = array_values(array_intersect($own(SoftDeletedUser::class), $own(Product::class)));
        $only   = array_values(array_diff($own(SoftDeletedUser::class), $own(Product::class)));
        $this->assertNotEmpty($shared);
        $this->assertNotEmpty($only);
        DB::table('fuzzy_index_terms')->where('id', $shared[0])->decrement('doc_count');
        DB::table('fuzzy_index_terms')->where('id', $only[0])->increment('doc_count');

        $this->artisan('fuzzy-search:rebuild', ['model' => SoftDeletedUser::class, '--fresh' => true])->assertExitCode(0);
        $this->assertModelTermsMirrorPostings('rebuild --fresh after the drift');

        $this->artisan('fuzzy-search:clear', ['model' => SoftDeletedUser::class])->assertExitCode(0);
        $this->assertModelTermsMirrorPostings('clear <model>');
    }

    /**
     * P6-B2: a write's changes to a model's counts go out as one upsert per 300 words, in term id
     * order, whatever the changes are, raised and lowered alike. One upsert per distinct change sent
     * 97 to 100 of them for a 500-row rebuild batch, where 20 carry its words.
     */
    public function test_a_writes_changes_to_the_models_counts_go_out_as_one_upsert_per_300_words(): void
    {
        $words = ['quartz', 'harbor', 'lantern', 'meadow', 'falcon', 'ember', 'willow', 'cobalt', 'sierra', 'tundra', 'pebble', 'orchid'];
        Schema::dropIfExists('model_terms_codes');
        Schema::create('model_terms_codes', function ($table) {
            $table->id();
            $table->string('code');
        });
        // Row i holds the first i words, so word j is in 13 - j rows: twelve different counts. Then
        // 320 rows of a word each, all at 1: 332 words.
        foreach (range(1, 12) as $i) {
            DB::table('model_terms_codes')->insert(['code' => implode(' ', array_slice($words, 0, $i))]);
        }
        foreach (array_chunk(range(13, 332), 100) as $chunk) {
            DB::table('model_terms_codes')->insert(array_map(fn (int $i) => ['code' => sprintf('w%05d', $i)], $chunk));
        }
        $upserts = '/^\s*(insert|merge)\b.*\bfuzzy_index_model_terms\b/is';
        $manager = app(IndexManager::class);
        $codes   = fn () => ModelTermsCode::query()->orderBy('id')->get();

        $this->assertCount(2, $this->reads($upserts, fn () => $manager->indexBatch($codes())), 'the first index');
        $this->assertModelTermsMirrorPostings('the first index');

        // Row i now holds the last i words, so word j is in j rows: changes of -11, -9, ... +11.
        foreach (range(1, 12) as $i) {
            DB::table('model_terms_codes')->where('id', ModelTermsCode::query()->orderBy('id')->skip($i - 1)->value('id'))->update(['code' => implode(' ', array_slice($words, 12 - $i))]);
        }
        $this->assertCount(1, $this->reads($upserts, fn () => $manager->indexBatch($codes()->take(12))), 'a re-index');
        $this->assertModelTermsMirrorPostings('the re-index');

        $this->assertCount(1, $this->reads($upserts, fn () => $manager->removeFromIndex(ModelTermsCode::class, (string) $codes()->last()->id)), 'a removal');
        $this->assertModelTermsMirrorPostings('the removal');

        Schema::dropIfExists('model_terms_codes');
    }

    /** @return list<string> the SQL of the reads $run sends that match $pattern */
    private function reads(string $pattern, \Closure $run): array
    {
        $reads = [];
        DB::listen(function (QueryExecuted $query) use (&$reads, $pattern) {
            if (preg_match($pattern, $query->sql)) {
                $reads[] = $query->sql;
            }
        });
        try {
            $run();
        } finally {
            DB::getEventDispatcher()->forget(QueryExecuted::class);
        }

        return $reads;
    }

    /** MySQL/MariaDB: the rows the handler read while $run ran (Handler_read_*); null elsewhere. */
    private function handlerReads(\Closure $run): ?int
    {
        if (!in_array($this->dbDriver, ['mysql', 'mariadb'], true)) {
            $run();

            return null;
        }

        DB::statement('flush status');
        $run();

        return (int) array_sum(array_map(fn ($row) => (int) ((array) $row)['Value'], DB::select("show session status like 'Handler_read%'")));
    }

    /**
     * T-1 (ruling ER-197). Another model's 10,000 distinct codes crowd the six-character words and
     * the prefix abc; the users hold one word of each. A users typo search read every word of each
     * length in its window through the shared dictionary until it held candidate_pool (500) of the
     * users' own, so it walked all 10,000 codes (70,286 rows read at 30,000 codes on MySQL), and so
     * did suggest() and asYouType() under abc (90,089). Each length, and the prefix, is now one read
     * of the users' own words in fuzzy_index_model_terms, and the dictionary is not read for them.
     */
    public function test_another_models_words_crowding_a_length_or_a_prefix_are_not_read(): void
    {
        Schema::dropIfExists('model_terms_codes');
        Schema::create('model_terms_codes', function ($table) {
            $table->id();
            $table->string('code');
        });
        foreach (array_chunk(range(1, 10000), 500) as $chunk) {
            DB::table('model_terms_codes')->insert(array_map(fn (int $i) => ['code' => sprintf('w%05d abc%05d', $i, $i)], $chunk));
        }
        ModelTermsCode::query()->chunkById(1000, fn ($codes) => app(IndexManager::class)->indexBatch($codes));
        DB::table('users')->insert(['name' => 'Walker Abcott', 'email' => 'walker@abcott.test', 'created_at' => now(), 'updated_at' => now()]);
        app(IndexManager::class)->indexBatch(User::all());
        $this->assertGreaterThan(10000, (int) DB::table('fuzzy_index_terms')->where('term_length', 6)->count()); // the codes, and walker

        $typo    = fn () => User::search('walkr')->typoTolerance(2)->useInvertedIndex()->get()->pluck('name')->all();
        $suggest = fn () => User::search('abc')->suggestFrom('index')->suggest();
        $prefix  = fn () => User::search('abc')->asYouType()->typoTolerance(0)->useInvertedIndex()->get()->pluck('name')->all();
        $this->assertSame(['Walker Abcott'], $typo());
        $this->assertSame(['abcott'], $suggest());
        $this->assertSame(['Walker Abcott'], $prefix());

        // One read of the model's words per length (3 to 7), none of the dictionary's by length.
        $this->assertCount(5, $this->reads('/\bfuzzy_index_model_terms\b.*\bterm_length\b/s', $typo));
        $this->assertSame([], $this->reads('/\bfuzzy_index_terms\b.*\bterm_length\b/s', $typo));
        // The prefix from the model's words, by their own key, not the dictionary's.
        foreach ([$suggest, $prefix] as $run) {
            $this->assertCount(1, $this->reads('/\bfuzzy_index_model_terms\b.*(\blike\b|>=)/is', $run));
            $this->assertSame([], $this->reads('/\bfuzzy_index_terms\b.*(\blike\b|>=)/is', $run));
        }

        // The rows read stay far below the 10,000 codes (each was read before ER-197).
        foreach (['typo' => $typo, 'suggest' => $suggest, 'asYouType' => $prefix] as $search => $run) {
            $read = $this->handlerReads($run);
            if ($read !== null) {
                $this->assertLessThan(2500, $read, "{$search}: rows read");
            }
        }

        Schema::dropIfExists('model_terms_codes');
    }

    /**
     * fuzzy_index_model_terms has no column: suggest() and didYouMean(), which hand words back,
     * still check the model's postings for a column it shows, in the same read. A word only in a
     * hidden column is never offered, with or without a prefix; one also in a shown column is.
     */
    public function test_a_word_only_in_a_hidden_column_is_never_offered(): void
    {
        DB::table('users')->insert([
            ['name' => 'Apple Orchard', 'email' => 'zebra@hidden.test', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pear Grove', 'email' => 'kiwis@hidden.test', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Kiwis Farm', 'email' => 'farm@hidden.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
        app(IndexManager::class)->indexBatch(ModelTermsHiddenUser::all());
        $this->assertSame(1, (int) DB::table('fuzzy_index_model_terms')->where('term', 'zebra')->value('doc_count'), 'the word is indexed');

        $offered = fn (string $term) => [
            array_column(ModelTermsHiddenUser::search($term)->didYouMean(5), 'term'),
            ModelTermsHiddenUser::search(mb_substr($term, 0, 3))->suggestFrom('index')->suggest(),
        ];

        [$didYouMean, $suggest] = $offered('zebre');
        $this->assertNotContains('zebra', $didYouMean);
        $this->assertNotContains('zebra', $suggest);

        [$didYouMean, $suggest] = $offered('kiwiz');
        $this->assertContains('kiwis', $didYouMean);
        $this->assertContains('kiwis', $suggest);

        // The typo and prefix expansions only match: every column.
        $this->assertSame(['Apple Orchard'], ModelTermsHiddenUser::search('zebre')->useInvertedIndex()->get()->pluck('name')->all());
    }

    /**
     * BM25's document frequency within the model is its own count in fuzzy_index_model_terms, read
     * by key: the postings' COUNT(DISTINCT model_id) read every posting of a common word. For a word
     * two models share, each count equals the postings', and the ranking reads no such count.
     */
    public function test_the_document_frequency_comes_from_the_models_own_count(): void
    {
        DB::table('products')->insert(['title' => 'John Phone', 'description' => 'apple', 'price' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('users')->insert(['name' => 'John Major', 'email' => 'major@example.com', 'created_at' => now(), 'updated_at' => now()]);
        app(IndexManager::class)->indexBatch(User::all());
        app(IndexManager::class)->indexBatch(Product::all());

        $postings = fn (string $type) => (int) DB::table('fuzzy_index_postings as p')->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('t.term', 'john')->where('p.model_type_id', IndexManager::modelTypeId($type))->distinct()->count('p.model_id');
        $own = fn (string $type) => (int) DB::table('fuzzy_index_model_terms')->where('term', 'john')->where('model_type_id', IndexManager::modelTypeId($type))->value('doc_count');
        $this->assertSame([2, 1], [$postings(User::class), $postings(Product::class)]); // John Doe (in both his columns) and John Major; the phone
        $this->assertSame([$postings(User::class), $postings(Product::class)], [$own(User::class), $own(Product::class)]);

        $counts = $this->reads('/count\s*\(\s*distinct/i', fn () => app(Bm25Scorer::class)->rank(['john'], User::class));
        $this->assertSame([], $counts);
        $this->assertCount(1, $this->reads('/\bfuzzy_index_model_terms\b/', fn () => app(Bm25Scorer::class)->rank(['john'], User::class)));
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
