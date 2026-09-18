<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-detection must never pick an `encrypted` or `hashed` column. The indexer reads a column
 * through getAttribute(), which decrypts it, so an auto-detected encrypted column would put its
 * plaintext into fuzzy_index_terms — and suggest() serves that dictionary to any caller.
 */
class EncryptedColumnDetectionTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Testbench leaves app.key unset; the encrypted cast needs one.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('patient_notes');
        Schema::create('patient_notes', function ($table) {
            $table->id();
            $table->text('bio');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('patient_notes');

        parent::tearDown();
    }

    private function createAndIndex(): void
    {
        EncryptedNote::create(['bio' => 'diabetes insulin']);

        $this->assertStringNotContainsString('diabetes', (string) DB::table('patient_notes')->value('bio'), 'precondition: stored encrypted');

        foreach (EncryptedNote::all() as $note) {
            app(IndexManager::class)->indexModel($note);
        }
    }

    public function test_an_encrypted_column_is_not_indexed_in_plaintext(): void
    {
        $this->createAndIndex();

        $this->assertFalse(
            DB::table('fuzzy_index_terms')->where('term', 'diabetes')->exists(),
            'decrypted text of an encrypted column was written to fuzzy_index_terms'
        );
    }

    public function test_suggest_does_not_serve_an_encrypted_columns_plaintext(): void
    {
        $this->createAndIndex();

        $this->assertNotContains('diabetes', EncryptedNote::search('dia')->suggest(5));
    }

    public function test_encrypted_and_hashed_columns_are_never_auto_detected_in_any_letter_case(): void
    {
        foreach (['encrypted', 'Encrypted', 'ENCRYPTED', 'hashed', 'Hashed'] as $cast) {
            SearchableColumns::reset(); // detection is memoised per class
            $note = new EncryptedNote;
            $note->mergeCasts(['bio' => $cast]);

            $this->assertNotContains('bio', $note->getSearchableColumns(), "cast '{$cast}'");
        }
    }

    public function test_a_hashed_column_is_not_auto_detected(): void
    {
        $this->assertNotContains('bio', (new HashedNote)->getSearchableColumns());
    }
}

/** Zero-config model whose only priority column is encrypted at rest. */
class EncryptedNote extends Model
{
    use Searchable;

    protected $table   = 'patient_notes';
    protected $guarded = [];
    protected $casts   = ['bio' => 'encrypted'];
    public $timestamps = false;
}

class HashedNote extends EncryptedNote
{
    protected $casts = ['bio' => 'hashed'];
}
