<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Tests\SoftDeletedUser;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A soft delete removes the row from the index. restore() saves the model, but the observer
 * only reindexed when a searchable column (or reindex_on) changed — the deleted-at column is
 * neither — so a restored row stayed unsearchable until the next rebuild.
 */
class RestoreReindexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('archivable_notes');
        Schema::create('archivable_notes', function ($table) {
            $table->id();
            $table->string('body');
            $table->timestamp('archived_at')->nullable();
        });

        config(['fuzzy-search.indexing.enabled' => true, 'fuzzy-search.indexing.async' => false]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('archivable_notes');

        parent::tearDown();
    }

    /** @param class-string<Model> $class */
    private function indexedHits(string $class, string $term): int
    {
        return $class::search($term)->useInvertedIndex()->get()->count();
    }

    /** The index itself, not a search: the SoftDeletes scope would hide a trashed row either way. */
    private function indexedDocuments(string $class, int|string $id): int
    {
        return DB::table('fuzzy_index_documents')->where('model_type', $class)->where('model_id', (string) $id)->count();
    }

    public function test_a_restored_model_is_searchable_on_the_index_again(): void
    {
        $id = SoftDeletedUser::create(['name' => 'Zelda Restore', 'email' => 'zelda@example.com'])->getKey();
        $this->assertSame(1, $this->indexedHits(SoftDeletedUser::class, 'zelda'), 'precondition: indexed on create');

        SoftDeletedUser::find($id)->delete();
        $this->assertSame(0, $this->indexedDocuments(SoftDeletedUser::class, $id), 'precondition: a soft delete removes it');

        // A later request: a freshly loaded model (a just-created one always reindexes on save).
        SoftDeletedUser::withTrashed()->find($id)->restore();
        $this->assertSame(1, $this->indexedHits(SoftDeletedUser::class, 'zelda'), 'the restored row was not re-indexed');
    }

    public function test_a_custom_deleted_at_column_is_honoured(): void
    {
        $id = ArchivableNote::create(['body' => 'quarterly zeppelin report'])->getKey();
        $this->assertSame(1, $this->indexedHits(ArchivableNote::class, 'zeppelin'), 'precondition: indexed on create');

        ArchivableNote::find($id)->delete();
        $this->assertSame(0, $this->indexedDocuments(ArchivableNote::class, $id), 'precondition: a soft delete removes it');

        ArchivableNote::withTrashed()->find($id)->restore();
        $this->assertSame(1, $this->indexedHits(ArchivableNote::class, 'zeppelin'), 'the restored row was not re-indexed');
    }
}

/** SoftDeletes on a renamed column: the observer must ask the model, not assume deleted_at. */
class ArchivableNote extends Model
{
    use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable, SoftDeletes;

    public const DELETED_AT = 'archived_at';

    protected $table   = 'archivable_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['body' => 1]];
}
