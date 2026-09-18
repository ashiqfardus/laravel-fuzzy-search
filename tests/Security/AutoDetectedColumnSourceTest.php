<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ER-32. Auto-detection never picks a column the model hides from serialization or a
 * secret-named column, and an auto-detected column is indexed as its stored value — the value
 * the LIKE path searches — not through a get accessor, which may decrypt it. A declared column
 * is the caller's explicit choice and keeps its accessor.
 */
class AutoDetectedColumnSourceTest extends TestCase
{
    private const TABLES = ['secret_notes', 'hidden_things', 'visible_things', 'credentials', 'token_only', 'fillable_secrets'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Testbench leaves app.key unset; Crypt needs one.
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropTables();
        Schema::create('secret_notes', function ($table) {
            $table->id();
            $table->text('bio');
            $table->text('bio_metaphone')->nullable();
        });
        Schema::create('hidden_things', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('other')->nullable();
        });
        Schema::create('visible_things', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('title');
            $table->text('body');
        });
        Schema::create('credentials', function ($table) {
            $table->id();
            $table->string('password');
            $table->string('remember_token');
            $table->string('nickname');
        });
        Schema::create('token_only', function ($table) {
            $table->id();
            $table->string('remember_token');
            $table->string('api_token');
            $table->text('two_factor_secret');
            $table->text('two_factor_recovery_codes');
        });
        Schema::create('fillable_secrets', function ($table) {
            $table->id();
            $table->text('two_factor_secret');
            $table->string('api_token');
            $table->string('nickname');
        });
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    private function dropTables(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** @param class-string<Model> $class */
    private function createAndIndex(string $class): void
    {
        $class::create(['bio' => 'diabetes insulin']);

        $this->assertStringNotContainsString('diabetes', (string) DB::table('secret_notes')->value('bio'), 'precondition: stored encrypted');
        $this->assertSame('diabetes insulin', $class::first()->bio, 'precondition: the accessor decrypts');

        foreach ($class::all() as $note) {
            app(IndexManager::class)->indexModel($note);
        }
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function accessorModels(): array
    {
        return [
            'get accessor method'  => [AccessorNote::class],
            'Attribute::make(get)' => [AttributeNote::class],
        ];
    }

    #[DataProvider('accessorModels')]
    public function test_an_auto_detected_accessor_column_is_indexed_as_stored_not_decrypted(string $class): void
    {
        $this->createAndIndex($class);

        $this->assertFalse(
            DB::table('fuzzy_index_terms')->whereIn('term', ['diabetes', 'insulin'])->exists(),
            'the accessor-decrypted plaintext of an auto-detected column reached fuzzy_index_terms'
        );
        $this->assertTrue(DB::table('fuzzy_index_postings')->where('column_name', 'bio')->exists(), 'the stored value is still indexed');
    }

    #[DataProvider('accessorModels')]
    public function test_suggest_and_did_you_mean_do_not_serve_the_plaintext(string $class): void
    {
        $this->createAndIndex($class);

        $this->assertNotContains('diabetes', $class::search('dia')->suggest(5));
        $this->assertStringNotContainsString('diabetes', json_encode($class::search('diabetis')->didYouMean(5)));
    }

    #[DataProvider('accessorModels')]
    public function test_the_metaphone_shadow_column_holds_the_stored_value_not_the_plaintext(string $class): void
    {
        $this->createAndIndex($class);

        $this->assertNotSame(metaphone('diabetes insulin'), DB::table('secret_notes')->value('bio_metaphone'));
        $this->assertSame(metaphone((string) DB::table('secret_notes')->value('bio')), DB::table('secret_notes')->value('bio_metaphone'));
    }

    /** Unchanged since 2.0: the LIKE path searches the stored column. */
    public function test_the_like_search_still_searches_the_stored_value(): void
    {
        $this->createAndIndex(AccessorNote::class);
        $stored = (string) DB::table('secret_notes')->value('bio');

        $this->assertCount(0, AccessorNote::search('diabetes')->using('like')->get());
        $this->assertCount(1, AccessorNote::search(substr($stored, 0, 12))->using('like')->get());
    }

    /** Unchanged: a declared column is the caller's choice, and accessor-backed declared fields are documented. */
    public function test_a_declared_accessor_column_is_still_indexed_through_the_accessor(): void
    {
        $this->createAndIndex(DeclaredAccessorNote::class);

        $this->assertTrue(DB::table('fuzzy_index_terms')->where('term', 'diabetes')->exists());
        $this->assertSame(metaphone('diabetes insulin'), DB::table('secret_notes')->value('bio_metaphone'));
    }

    public function test_a_hidden_priority_column_is_not_auto_detected(): void
    {
        $this->assertNotContains('title', (new HiddenTitleThing)->getSearchableColumns());

        // Detection is memoised per class, so an instance that un-hides the column must not
        // decide it for every later caller.
        SearchableColumns::reset();
        $this->assertNotContains('title', (new HiddenTitleThing)->makeVisible('title')->getSearchableColumns());
    }

    public function test_a_visible_list_limits_auto_detection_to_its_columns(): void
    {
        $this->assertSame(['title'], (new VisibleTitleThing)->getSearchableColumns());
    }

    public function test_secret_named_columns_are_never_the_first_string_like_pick(): void
    {
        $this->assertSame(['nickname'], (new CredentialThing)->getSearchableColumns());
        $this->assertSame([], (new TokenOnlyThing)->getSearchableColumns());
    }

    public function test_secret_named_columns_are_skipped_in_the_fillable_branch(): void
    {
        $this->assertSame(['nickname'], (new FillableSecretThing)->getSearchableColumns());
    }
}

/** Zero-config model whose `bio` is encrypted by a mutator and decrypted by a get accessor. */
class AccessorNote extends Model
{
    use Searchable;

    protected $table   = 'secret_notes';
    protected $guarded = [];
    public $timestamps = false;

    public function getBioAttribute($value)
    {
        return Crypt::decryptString($value);
    }

    public function setBioAttribute($value): void
    {
        $this->attributes['bio'] = Crypt::encryptString($value);
    }
}

/** The same, through an Attribute cast. */
class AttributeNote extends Model
{
    use Searchable;

    protected $table   = 'secret_notes';
    protected $guarded = [];
    public $timestamps = false;

    protected function bio(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Crypt::decryptString($value),
            set: fn ($value) => Crypt::encryptString($value),
        );
    }
}

class DeclaredAccessorNote extends AccessorNote
{
    protected array $searchable = ['columns' => ['bio' => 1]];
}

class HiddenTitleThing extends Model
{
    use Searchable;

    protected $table  = 'hidden_things';
    protected $hidden = ['title'];
}

class VisibleTitleThing extends Model
{
    use Searchable;

    protected $table   = 'visible_things';
    protected $visible = ['id', 'title'];
}

/** No priority column, no $fillable: detection falls through to the first string-like column. */
class CredentialThing extends Model
{
    use Searchable;

    protected $table = 'credentials';
}

class TokenOnlyThing extends Model
{
    use Searchable;

    protected $table = 'token_only';
}

class FillableSecretThing extends Model
{
    use Searchable;

    protected $table    = 'fillable_secrets';
    protected $fillable = ['two_factor_secret', 'api_token', 'nickname'];
}
