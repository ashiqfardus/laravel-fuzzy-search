<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

class ExposeAuthor extends Model
{
    use Searchable;

    protected $table   = 'expose_authors';
    protected $guarded = [];
    public $timestamps = false;
    protected $hidden  = ['ssn'];

    protected array $searchable = ['columns' => ['name' => 10], 'algorithm' => 'like'];
}

/** Auto-detected columns: no $searchable['columns'] to allow-list anything with. */
class ExposeAutoAuthor extends ExposeAuthor
{
    protected array $searchable = ['algorithm' => 'like'];
}

/** The developer allow-lists the hidden column. */
class ExposeDeclaringAuthor extends ExposeAuthor
{
    protected array $searchable = ['columns' => ['name' => 10, 'ssn' => 1], 'algorithm' => 'like'];
}

/** A tenant id and a sort field the model keeps out of its output, as many apps do. */
class ExposeTenantAuthor extends ExposeAuthor
{
    protected $hidden = ['ssn', 'tenant_id', 'priority'];
}

/** No Searchable trait, so no $searchable['columns'] to declare anything in: the macros still apply. */
class ExposePlainAuthor extends Model
{
    protected $table   = 'expose_authors';
    protected $guarded = [];
    public $timestamps = false;
    protected $hidden  = ['email'];
}

class ExposePost extends Model
{
    use Searchable;

    protected $table   = 'expose_posts';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['title' => 10], 'algorithm' => 'like'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(ExposeAuthor::class, 'author_id');
    }
}

/** The developer allow-lists the related model's hidden column under its relation path. */
class ExposeDeclaringPost extends ExposePost
{
    protected array $searchable = ['columns' => ['title' => 10, 'author.ssn' => 1], 'algorithm' => 'like'];
}

/**
 * A column name passed to searchIn(), filter(), filterIn(), facet(), orderBy() or the Eloquent fuzzy
 * macros may come from the request. One named like a secret is refused in all of them, and one the
 * model only hides is refused where its content leaks through partial or aggregate matching
 * (searchIn(), facet(), orderByFuzzy(): `?field=ssn&q=123-4`), unless the model lists it in
 * $searchable['columns']. An equality filter or a plain ordering by a hidden column is allowed.
 */
class RequestColumnExposureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('expose_posts');
        Schema::dropIfExists('expose_authors');
        Schema::create('expose_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('ssn');
            $table->string('api_token');
            $table->unsignedInteger('tenant_id');
            $table->unsignedInteger('priority');
        });
        Schema::create('expose_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id');
            $table->string('title');
        });

        DB::table('expose_authors')->insert([
            ['name' => 'Ann Lee', 'email' => 'ann@site.test', 'ssn' => '123-45-6789', 'api_token' => 'tok-ann', 'tenant_id' => 7, 'priority' => 2],
            ['name' => 'Bo Ray', 'email' => 'bo@site.test', 'ssn' => '987-65-4321', 'api_token' => 'tok-bo', 'tenant_id' => 8, 'priority' => 1],
        ]);
        DB::table('expose_posts')->insert([
            ['author_id' => 1, 'title' => 'First post'],
            ['author_id' => 2, 'title' => 'Second post'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('expose_posts');
        Schema::dropIfExists('expose_authors');

        parent::tearDown();
    }

    /** @return array<string, array{\Closure(SearchBuilder): mixed, string}> */
    public static function requestColumns(): array
    {
        return [
            'searchIn hidden'           => [fn (SearchBuilder $b) => $b->searchIn(['ssn']), 'ssn'],
            'searchIn hidden, weighted' => [fn (SearchBuilder $b) => $b->searchIn(['ssn' => 5]), 'ssn'],
            'searchIn hidden, upper'    => [fn (SearchBuilder $b) => $b->searchIn(['SSN']), 'SSN'],
            'searchIn table-qualified'  => [fn (SearchBuilder $b) => $b->searchIn(['expose_authors.ssn']), 'expose_authors.ssn'],
            'facet hidden'              => [fn (SearchBuilder $b) => $b->facet('ssn'), 'ssn'],
            'searchIn secret'           => [fn (SearchBuilder $b) => $b->searchIn(['api_token']), 'api_token'],
            'facet secret'              => [fn (SearchBuilder $b) => $b->facet('api_token'), 'api_token'],
            'filter secret'             => [fn (SearchBuilder $b) => $b->filter('api_token', 'tok-ann'), 'api_token'],
            'filterIn secret'           => [fn (SearchBuilder $b) => $b->filterIn('api_token', ['tok-ann']), 'api_token'],
            'orderBy secret'            => [fn (SearchBuilder $b) => $b->orderBy('api_token', 'desc'), 'api_token'],
        ];
    }

    #[DataProvider('requestColumns')]
    public function test_a_hidden_or_secret_named_column_is_refused(\Closure $call, string $column): void
    {
        foreach ([ExposeAuthor::class, ExposeAutoAuthor::class] as $class) {
            try {
                $call($class::search('ann'))->get();
                $this->fail("{$class}: [{$column}] was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("[{$column}]", $e->getMessage());
                $this->assertStringContainsString("\$searchable['columns']", $e->getMessage());
            }
        }
    }

    public static function relationPaths(): array
    {
        return [
            'searchIn' => [fn (SearchBuilder $b) => $b->searchIn(['author.ssn'])],
            'case'     => [fn (SearchBuilder $b) => $b->searchIn(['Author.SSN'])],
            'secret'   => [fn (SearchBuilder $b) => $b->searchIn(['author.api_token'])],
            'filter'   => [fn (SearchBuilder $b) => $b->filter('author.api_token', 'tok-ann')],
            'facet'    => [fn (SearchBuilder $b) => $b->facet('author.ssn')],
        ];
    }

    #[DataProvider('relationPaths')]
    public function test_a_relation_path_to_the_related_models_hidden_or_secret_column_is_refused(\Closure $call): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("\$searchable['columns']");

        $call(ExposePost::search('123-45'))->get();
    }

    public function test_a_declared_hidden_column_is_searchable_and_filterable(): void
    {
        $this->assertSame(['Ann Lee'], ExposeDeclaringAuthor::search('123-45')->searchIn(['ssn'])->get()->pluck('name')->all());
        $this->assertSame(['Ann Lee'], ExposeDeclaringAuthor::search('ann')->filter('ssn', '123-45-6789')->get()->pluck('name')->all());
        $this->assertSame(['Ann Lee'], ExposeDeclaringAuthor::search('ann')->filterIn('ssn', ['123-45-6789'])->get()->pluck('name')->all());
        $this->assertSame(['123-45-6789' => 1], ExposeDeclaringAuthor::search('ann')->facet('ssn')->getFacets()['ssn']);
    }

    public function test_a_declared_relation_path_is_searchable(): void
    {
        $this->assertSame(['First post'], ExposeDeclaringPost::search('123-45')->searchIn(['author.ssn'])->get()->pluck('title')->all());
    }

    public function test_an_ordinary_column_still_works_in_every_method(): void
    {
        $this->assertSame(['Bo Ray'], ExposeAuthor::search('bo@')->searchIn(['email'])->filter('email', 'bo@site.test')->filterIn('id', [2])->get()->pluck('name')->all());
        $this->assertSame(['ann@site.test' => 1], ExposeAuthor::search('ann')->facet('email')->getFacets()['email']);
        $this->assertSame(['Second post'], ExposePost::search('bo ray')->searchIn(['author.name'])->get()->pluck('title')->all());
    }

    /** @return array<string, array{\Closure(\Illuminate\Database\Eloquent\Builder): mixed, string}> */
    public static function macroColumns(): array
    {
        return [
            'orderByFuzzy hidden'    => [fn ($q) => $q->orderByFuzzy('ssn', '123-45-6789'), 'ssn'],
            'orderByFuzzy secret'    => [fn ($q) => $q->orderByFuzzy('api_token', 'tok-ann'), 'api_token'],
            'orderByFuzzy qualified' => [fn ($q) => $q->orderByFuzzy('expose_authors.ssn', '123'), 'expose_authors.ssn'],
            'whereFuzzy secret'      => [fn ($q) => $q->whereFuzzy('api_token', 'tok-ann'), 'api_token'],
            'orWhereFuzzy secret'    => [fn ($q) => $q->orWhereFuzzy('api_token', 'tok-ann'), 'api_token'],
            'whereFuzzyMultiple'     => [fn ($q) => $q->whereFuzzyMultiple(['name', 'API_TOKEN'], 'ann'), 'API_TOKEN'],
            'fuzzySearch'            => [fn ($q) => $q->fuzzySearch('api_token', 'tok'), 'api_token'],
        ];
    }

    #[DataProvider('macroColumns')]
    public function test_the_eloquent_fuzzy_macros_refuse_a_hidden_or_secret_named_column(\Closure $call, string $column): void
    {
        foreach ([ExposeAuthor::class, ExposeAutoAuthor::class] as $class) {
            try {
                $call($class::query())->get();
                $this->fail("{$class}: [{$column}] was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("[{$column}]", $e->getMessage());
                $this->assertStringContainsString("\$searchable['columns']", $e->getMessage());
            }
        }
    }

    public function test_the_eloquent_fuzzy_macros_take_a_declared_or_ordinary_column(): void
    {
        $this->assertCount(2, ExposeDeclaringAuthor::query()->orderByFuzzy('ssn', '123-45-6789')->get());
        $this->assertContains('Ann Lee', ExposeDeclaringAuthor::query()->whereFuzzy('ssn', '123-45-6789')->pluck('name')->all());
        $this->assertCount(2, ExposeAuthor::query()->orderByFuzzy('name', 'bo ray')->get());
        $this->assertSame(['Ann Lee', 'Bo Ray'], ExposeAuthor::query()->orderBy('name')->get()->pluck('name')->all());

        // A query builder has no $hidden or $searchable['columns'] to judge by: not checked, as in the builder.
        $this->assertNotNull(DB::table('expose_authors')->orderByFuzzy('ssn', '123')->first());
    }

    public function test_an_equality_filter_a_plain_ordering_and_the_where_macros_take_a_hidden_column(): void
    {
        $both = fn () => ExposeTenantAuthor::search('site.test')->searchIn(['email']);
        $this->assertSame(['Ann Lee'], $both()->filter('tenant_id', 7)->get()->pluck('name')->all());
        $this->assertSame(['Bo Ray'], $both()->filterIn('tenant_id', [8, 9])->get()->pluck('name')->all());
        $this->assertSame(['Ann Lee'], ExposeAuthor::search('ann')->filter('ssn', '123-45-6789')->get()->pluck('name')->all());
        $this->assertSame(['Bo Ray', 'Ann Lee'], $both()->orderBy('priority')->get()->pluck('name')->all());

        $this->assertContains('Ann Lee', ExposePlainAuthor::query()->whereFuzzy('email', 'ann@site.test')->pluck('name')->all());
        $this->assertContains('Ann Lee', ExposeTenantAuthor::query()->whereFuzzyMultiple(['name', 'ssn'], 'ann')->pluck('name')->all());
        $this->assertNotEmpty(ExposeTenantAuthor::query()->fuzzySearch('ssn', '123-45-6789')->get());

        $this->expectException(\InvalidArgumentException::class);
        ExposePlainAuthor::query()->whereFuzzy('api_token', 'tok-ann')->get();
    }
}
