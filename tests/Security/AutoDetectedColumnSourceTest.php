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
 * secret-named column, and an auto-detected column is indexed as the raw attribute value, not
 * through a get accessor, which may decrypt it. A column the model
 * chose (declared, or supplied by overriding getSearchableColumns()) keeps its accessor.
 */
class AutoDetectedColumnSourceTest extends TestCase
{
    private const TABLES = [
        'secret_notes', 'hidden_things', 'visible_things', 'credentials', 'token_only', 'fillable_secrets',
        'cased_secrets', 'shadow_people', 'override_things',
    ];

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
        Schema::create('cased_secrets', function ($table) {
            $table->id();
            $table->string('Password');
            $table->string('nickname');
        });
        Schema::create('shadow_people', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('name_metaphone')->nullable();
            $table->string('title');
        });
        Schema::create('override_things', function ($table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
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

    public function test_secret_names_are_matched_in_any_letter_case(): void
    {
        $this->assertSame(['nickname'], (new CasedSecretThing)->getSearchableColumns());
    }

    /** A table that was listed is final even when every column is filtered out: re-listing it changes nothing. */
    public function test_an_empty_detection_of_a_listed_table_is_cached(): void
    {
        $this->assertSame([], (new TokenOnlyThing)->getSearchableColumns());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->assertSame([], (new TokenOnlyThing)->getSearchableColumns());
        $this->assertSame([], DB::getQueryLog(), 'the table was listed again on every call');
    }

    public function test_the_schema_failure_fallback_never_picks_an_encrypted_name(): void
    {
        $this->useUnreachableConnection();

        $this->assertSame([], (new UnreachableEncryptedNameThing)->getSearchableColumns());
    }

    /** The fallback is a stand-in for a table that could not be listed: it must not outlive the outage. */
    public function test_the_schema_failure_fallback_is_not_cached(): void
    {
        $this->useUnreachableConnection();
        $this->assertSame(['name'], (new UnreachableThing)->getSearchableColumns());

        config(['database.connections.unreachable' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('unreachable');
        Schema::connection('unreachable')->create('recovering_things', function ($table) {
            $table->id();
            $table->string('title');
        });

        $this->assertSame(['title'], (new UnreachableThing)->getSearchableColumns());
    }

    private function useUnreachableConnection(): void
    {
        config(['database.connections.unreachable' => [
            'driver' => 'sqlite', 'database' => sys_get_temp_dir() . '/fuzzy-search-no-such-dir/none.sqlite', 'prefix' => '',
        ]]);
        DB::purge('unreachable');
    }

    /** @return array<string, array{class-string<Model>}> */
    public static function shadowModels(): array
    {
        return ['auto-detected' => [ShadowPerson::class], 'declared' => [DeclaredShadowPerson::class]];
    }

    /**
     * A save that never loaded `name` (a partial select) did not change it, so its shadow still
     * holds. It was wiped to NULL, or, in strict mode, the save threw.
     */
    #[DataProvider('shadowModels')]
    public function test_saving_a_partially_selected_model_keeps_its_shadow_column(string $class): void
    {
        $id = $class::create(['name' => 'Bob Jones', 'title' => 'Engineer'])->getKey();
        $this->assertSame(metaphone('Bob Jones'), DB::table('shadow_people')->value('name_metaphone'), 'precondition');

        foreach (['default' => false, 'strict' => true] as $mode => $strict) {
            Model::preventAccessingMissingAttributes($strict);

            try {
                $person        = $class::select(['id', 'title'])->find($id);
                $person->title = 'Manager ' . $mode;
                $person->save();
            } finally {
                Model::preventAccessingMissingAttributes(false);
            }

            $this->assertSame(metaphone('Bob Jones'), DB::table('shadow_people')->value('name_metaphone'), "$mode mode");
        }

        // A loaded column is still written.
        $class::find($id)->update(['name' => 'Ada Byron']);
        $this->assertSame(metaphone('Ada Byron'), DB::table('shadow_people')->value('name_metaphone'));
    }

    /** Overriding getSearchableColumns() chooses the columns, exactly as declaring them does. */
    public function test_a_model_that_overrides_get_searchable_columns_keeps_its_accessor(): void
    {
        $thing = ColumnsOverrideThing::create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->assertTrue($thing->hasDeclaredSearchableColumns());

        app(IndexManager::class)->indexModel($thing);

        $this->assertSame(2, DB::table('fuzzy_index_postings')->where('column_name', 'full_name')->count());
        $this->assertSame(2, DB::table('fuzzy_index_terms')->whereIn('term', ['grace', 'hopper'])->count());
    }

    /** Detection builds a fresh instance; a constructor that reads the columns must not recurse through it. */
    public function test_a_constructor_that_reads_the_columns_does_not_recurse(): void
    {
        $this->assertSame(['name', 'title', 'body'], (new ConstructorReadsColumns)->columnsAtConstruction);
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

class CasedSecretThing extends Model
{
    use Searchable;

    protected $table = 'cased_secrets';
}

/** Its connection points at a database file that does not exist, so detection cannot list the table. */
class UnreachableThing extends Model
{
    use Searchable;

    protected $connection = 'unreachable';
    protected $table      = 'recovering_things';
}

class UnreachableEncryptedNameThing extends UnreachableThing
{
    protected $casts = ['name' => 'encrypted'];
}

class ShadowPerson extends Model
{
    use Searchable;

    protected $table   = 'shadow_people';
    protected $guarded = [];
    public $timestamps = false;
}

class DeclaredShadowPerson extends ShadowPerson
{
    protected array $searchable = ['columns' => ['name' => 10, 'title' => 10]];
}

/** Supplies its columns by overriding getSearchableColumns(), with no $searchable['columns']. */
class ColumnsOverrideThing extends Model
{
    use Searchable;

    protected $table   = 'override_things';
    protected $guarded = [];
    public $timestamps = false;

    public function getSearchableColumns(): array
    {
        return ['full_name'];
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}

class ConstructorReadsColumns extends Model
{
    use Searchable;

    public static int $depth = 0;

    protected $table = 'visible_things';

    public array $columnsAtConstruction = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        // Fail the test on recursion instead of exhausting the stack.
        if (self::$depth >= 5) {
            throw new \RuntimeException('the constructor was re-entered without bound');
        }

        self::$depth++;

        try {
            $this->columnsAtConstruction = $this->getSearchableColumns();
        } finally {
            self::$depth--;
        }
    }
}
