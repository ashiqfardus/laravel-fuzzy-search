<?php

namespace App\FuzzyShadowTest {

    use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
    use Illuminate\Database\Eloquent\Model;

    /** In the app namespace: fuzzy-search:add-shadow-column accepts application models only. */
    class LongBioPerson extends Model
    {
        use Searchable;

        protected $table   = 'long_bio_people';
        protected $guarded = [];

        protected array $searchable = ['columns' => ['name' => 10, 'bio' => 1]];
    }
}

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature {

    use App\FuzzyShadowTest\LongBioPerson;
    use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
    use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
    use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
    use Illuminate\Support\Facades\Artisan;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Schema;

    /**
     * SF-2. The generated shadow column was string() (255, or 191 under defaultStringLength(191))
     * and the code was written uncapped: metaphone() is about half the value's length, so a bio past
     * about 550 characters failed its save on MySQL, MariaDB, PostgreSQL and SQL Server (22001), and
     * fuzzy-search:rebuild (--fresh: after flushing the index) stopped at that row's chunk. The code
     * is now cut to 191 characters wherever it is computed, and the generated column says 191.
     */
    class MetaphoneLongValueTest extends TestCase
    {
        private string $bio;

        protected function setUp(): void
        {
            parent::setUp();
            Schema::dropIfExists('long_bio_people');
            Schema::create('long_bio_people', function ($table) {
                $table->id();
                $table->string('name')->nullable();
                $table->text('bio')->nullable();
                $table->timestamps();
            });
            $this->bio = str_repeat('Latest Apple smartphone with great camera ', 14); // 588 characters
        }

        protected function tearDown(): void
        {
            Schema::dropIfExists('long_bio_people');
            parent::tearDown();
        }

        /** Runs the real command, then the migration it wrote; returns the migration's source. */
        private function addShadowColumn(string $column): string
        {
            $dir = sys_get_temp_dir() . '/fuzzy-shadow-' . getmypid() . '-' . uniqid();
            mkdir($dir . '/migrations', 0777, true);
            $this->app->useDatabasePath($dir);

            try {
                $this->assertSame(0, Artisan::call('fuzzy-search:add-shadow-column', ['model' => LongBioPerson::class, 'column' => $column]));
                $files = glob($dir . '/migrations/*.php');
                $this->assertCount(1, $files);
                $source = file_get_contents($files[0]);
                (require $files[0])->up();
            } finally {
                array_map('unlink', glob($dir . '/migrations/*.php'));
                rmdir($dir . '/migrations');
                rmdir($dir);
            }
            SearchableObserver::resetColumnCache();

            return $source;
        }

        private function addShadowColumns(): void
        {
            $this->addShadowColumn('name');
            $this->addShadowColumn('bio');
        }

        public function test_the_generated_shadow_column_holds_191_characters(): void
        {
            $this->assertStringContainsString("\$table->string('name_metaphone', 191)->nullable()", $this->addShadowColumn('name'));
        }

        public function test_a_long_value_saves_with_its_code_cut_to_the_column(): void
        {
            $this->addShadowColumns();
            $this->assertGreaterThan(191, strlen(metaphone($this->bio)));

            LongBioPerson::create(['name' => 'Long Bio', 'bio' => $this->bio]);
            DB::transaction(fn () => LongBioPerson::create(['name' => 'Tx Bio', 'bio' => $this->bio]));

            foreach (DB::table('long_bio_people')->get() as $row) {
                $this->assertSame(substr(metaphone($this->bio), 0, 191), $row->bio_metaphone, $row->name);
            }

            // A search for the whole value still finds its row (the term cap is raised past the
            // value's length: by default the search term is cut at 128 characters first).
            config(['fuzzy-search.query.max_term_length' => 1000]);
            $this->assertSame(['Long Bio', 'Tx Bio'], LongBioPerson::search($this->bio)->searchIn(['bio'])->using('metaphone')->get()->pluck('name')->sort()->values()->all());
            $this->assertSame(2, LongBioPerson::query()->whereFuzzy('bio', $this->bio, 'metaphone')->count());
            $this->assertSame([], LongBioPerson::search('Latest Apple')->searchIn(['bio'])->using('metaphone')->get()->all());
        }

        public function test_rebuild_fresh_indexes_and_backfills_every_row_past_a_long_one(): void
        {
            config(['fuzzy-search.indexing.chunk_size' => 2]);
            foreach (['Alpha One', 'Bravo Two', 'Charlie Long', 'Delta Four', 'Echo Five', 'Foxtrot Six'] as $name) {
                DB::table('long_bio_people')->insert(['name' => $name, 'bio' => $name === 'Charlie Long' ? $this->bio : 'short']);
            }
            app(IndexManager::class)->indexBatch(LongBioPerson::all());
            $this->addShadowColumns();

            $this->assertSame(0, Artisan::call('fuzzy-search:rebuild', ['model' => LongBioPerson::class, '--fresh' => true]));

            $this->assertSame(6, DB::table('fuzzy_index_documents')->where('model_type', LongBioPerson::class)->count());
            $this->assertSame(0, DB::table('long_bio_people')->whereNull('bio_metaphone')->orWhereNull('name_metaphone')->count());
            $this->assertSame(['Foxtrot Six'], LongBioPerson::search('foxtrot')->useInvertedIndex()->get()->pluck('name')->all());
        }
    }
}
