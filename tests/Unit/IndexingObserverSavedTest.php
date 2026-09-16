<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

use Ashiqfardus\LaravelFuzzySearch\Jobs\IndexModelJob;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * SearchableIndexingObserver::saved() short-circuited with wasChanged($searchableColumns).
 * A searchable field backed by an accessor (brand name pulled through a relation) is never
 * "changed", so moving a product to another brand left it findable under the old one. And
 * on the synchronous path the in-memory model was indexed with whatever relations it had
 * already loaded, so even a forced reindex could store the previous brand.
 */
class IndexingObserverSavedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('obs_products');
        Schema::dropIfExists('obs_brands');

        Schema::create('obs_brands', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('obs_products', function ($table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->integer('stock')->default(0);
            $table->timestamps();
        });

        config(['fuzzy-search.indexing.enabled' => true]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('obs_products');
        Schema::dropIfExists('obs_brands');

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Async path — which saves dispatch a job
    // -------------------------------------------------------------------------

    public function test_changing_a_column_behind_a_computed_searchable_field_triggers_a_reindex(): void
    {
        config(['fuzzy-search.indexing.async' => true]);
        Queue::fake();

        $apple   = ObsBrand::create(['name' => 'apple']);
        $samsung = ObsBrand::create(['name' => 'samsung']);

        $created = ObsProductComputed::create(['name' => 'watch', 'brand_id' => $apple->id]);
        Queue::assertPushed(IndexModelJob::class, 1); // the create

        // A later request loads the product fresh (wasRecentlyCreated is false) and moves it.
        $product = ObsProductComputed::find($created->id);
        $product->brand_id = $samsung->id;
        $product->save();

        // brand_name is an accessor, so the observer cannot know whether it changed:
        // it must reindex rather than skip.
        Queue::assertPushed(IndexModelJob::class, 2);
    }

    public function test_declared_reindex_triggers_restore_the_skip_for_unrelated_columns(): void
    {
        config(['fuzzy-search.indexing.async' => true]);
        Queue::fake();

        $apple   = ObsBrand::create(['name' => 'apple']);
        $samsung = ObsBrand::create(['name' => 'samsung']);

        $created = ObsProductWithTriggers::create(['name' => 'watch', 'brand_id' => $apple->id]);
        Queue::assertPushed(IndexModelJob::class, 1);

        // stock is neither searchable nor a declared trigger — a hot-path update must not reindex.
        $product = ObsProductWithTriggers::find($created->id);
        $product->stock = 99;
        $product->save();
        Queue::assertPushed(IndexModelJob::class, 1);

        // brand_id is declared in reindex_on — this one must.
        $product = ObsProductWithTriggers::find($created->id);
        $product->brand_id = $samsung->id;
        $product->save();
        Queue::assertPushed(IndexModelJob::class, 2);
    }

    public function test_plain_models_still_skip_saves_that_touch_no_searchable_column(): void
    {
        config(['fuzzy-search.indexing.async' => true]);
        Queue::fake();

        $created = ObsProductPlain::create(['name' => 'watch']);
        Queue::assertPushed(IndexModelJob::class, 1);

        $product = ObsProductPlain::find($created->id);
        $product->stock = 5;
        $product->save();
        Queue::assertPushed(IndexModelJob::class, 1);

        $product = ObsProductPlain::find($created->id);
        $product->name = 'smart watch';
        $product->save();
        Queue::assertPushed(IndexModelJob::class, 2);
    }

    public function test_a_model_created_and_updated_in_the_same_request_is_reindexed_on_both_saves(): void
    {
        // Eloquent keeps wasRecentlyCreated set for the instance's lifetime; the observer
        // treats that as "reindex", which errs on the side of a correct index.
        config(['fuzzy-search.indexing.async' => true]);
        Queue::fake();

        $product = ObsProductPlain::create(['name' => 'watch']);
        $product->stock = 5;
        $product->save();

        Queue::assertPushed(IndexModelJob::class, 2);
    }

    // -------------------------------------------------------------------------
    // Sync path — what actually lands in the index
    // -------------------------------------------------------------------------

    public function test_sync_indexing_reloads_the_model_so_a_stale_loaded_relation_is_not_indexed(): void
    {
        config(['fuzzy-search.indexing.async' => false]);

        $apple   = ObsBrand::create(['name' => 'apple']);
        $samsung = ObsBrand::create(['name' => 'samsung']);

        $created = ObsProductWithTriggers::create(['name' => 'watch', 'brand_id' => $apple->id]);
        $this->assertSame(['apple', 'watch'], $this->indexedTermsFor($created));

        // A later request loads the product, touches the relation (now cached on the
        // instance), then moves it to another brand.
        $product = ObsProductWithTriggers::find($created->id);
        $this->assertSame('apple', $product->brand->name);
        $product->brand_id = $samsung->id;
        $product->save();

        $this->assertSame(['samsung', 'watch'], $this->indexedTermsFor($product));
    }

    public function test_sync_indexing_of_a_computed_field_without_triggers_reindexes_on_any_save(): void
    {
        config(['fuzzy-search.indexing.async' => false]);

        $apple   = ObsBrand::create(['name' => 'apple']);
        $samsung = ObsBrand::create(['name' => 'samsung']);

        $created = ObsProductComputed::create(['name' => 'watch', 'brand_id' => $apple->id]);

        $product = ObsProductComputed::find($created->id);
        $product->brand_id = $samsung->id;
        $product->save();

        $this->assertSame(['samsung', 'watch'], $this->indexedTermsFor($product));
    }

    /** @return string[] sorted terms currently indexed for the model */
    private function indexedTermsFor(Model $model): array
    {
        return DB::table('fuzzy_index_postings as p')
            ->join('fuzzy_index_terms as t', 't.id', '=', 'p.term_id')
            ->where('p.model_type', $model::class)
            ->where('p.model_id', $model->getKey())
            ->orderBy('t.term')
            ->pluck('t.term')
            ->map(fn ($term) => (string) $term)
            ->all();
    }
}

// ---------------------------------------------------------------------------
// Named models: IndexModelJob reloads by class name, which anonymous classes cannot provide.
// ---------------------------------------------------------------------------

class ObsBrand extends Model
{
    protected $table   = 'obs_brands';
    protected $guarded = [];
    public $timestamps = false;
}

class ObsProductComputed extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table   = 'obs_products';
    protected $guarded = [];

    protected array $searchable = [
        'columns' => ['name' => 1, 'brand_name' => 1],
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ObsBrand::class, 'brand_id');
    }

    public function getBrandNameAttribute(): ?string
    {
        return $this->brand?->name;
    }
}

class ObsProductWithTriggers extends ObsProductComputed
{
    protected array $searchable = [
        'columns'    => ['name' => 1, 'brand_name' => 1],
        'reindex_on' => ['brand_id'],
    ];
}

class ObsProductPlain extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

    protected $table   = 'obs_products';
    protected $guarded = [];

    protected array $searchable = [
        'columns' => ['name' => 1],
    ];
}
