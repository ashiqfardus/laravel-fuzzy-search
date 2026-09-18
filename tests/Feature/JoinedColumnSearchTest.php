<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Support\SearchableColumns;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\FakesDriverConnections;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A query that joins a table with a column of the same name as a searched column ("name") must
 * still search. The model's own columns are qualified in the SQL, and only there: scores,
 * highlight keys and facet keys keep the logical name. Every user has exactly one team, so the
 * joined search must return what the same search returns without the join.
 */
class JoinedColumnSearchTest extends TestCase
{
    use FakesDriverConnections;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('teams');
        Schema::create('teams', function ($table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('title');
        });

        // Alice's team is "Johnson Crew": a search that read teams.name would return her for "john".
        $teams = [
            'John Doe'      => ['Crimson', 'Team Captain'],
            'Jane Doe'      => ['Azure', 'Member'],
            'Jon Snow'      => ['Emerald', 'Member'],
            'Johnny Bravo'  => ['Amber', 'Member'],
            'Alice Smith'   => ['Johnson Crew', 'Member'],
            'Bob Johnson'   => ['Ivory', 'Member'],
            'Charlie Brown' => ['Onyx', 'Member'],
        ];
        foreach (DB::table('users')->pluck('id', 'name') as $name => $id) {
            DB::table('teams')->insert(['user_id' => $id, 'name' => $teams[$name][0], 'title' => $teams[$name][1]]);
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('teams');

        parent::tearDown();
    }

    private function userId(string $name): int
    {
        return (int) DB::table('users')->where('name', $name)->value('id');
    }

    /** @return int[] sorted: ties in relevance may come back in any order */
    private static function ids(iterable $rows): array
    {
        $ids = collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    /** @return array<int, mixed> id => $field, sorted by id */
    private static function byId(iterable $rows, string $field): array
    {
        return collect($rows)->mapWithKeys(fn ($row) => [(int) $row->id => $row->{$field}])->sortKeys()->all();
    }

    /** Each shape runs on User (no join) and TeamUser (a global-scope join); both must agree. */
    public static function shapes(): array
    {
        $s = fn (string $model, string $term = 'john') => $model::search($term);

        return [
            'get'              => [fn ($m) => [self::ids($r = $s($m)->get()), self::byId($r, '_score')]],
            'paginate'         => [fn ($m) => [$s($m)->paginate(2)->total(), self::ids($s($m)->paginate(100)->items())]],
            'count'            => [fn ($m) => $s($m)->count()],
            'simplePaginate'   => [fn ($m) => self::ids($s($m)->simplePaginate(100)->items())],
            'fuzzy'            => [fn ($m) => self::ids($s($m)->using('fuzzy')->get())],
            'levenshtein'      => [fn ($m) => self::ids($s($m)->using('levenshtein')->get())],
            'soundex'          => [fn ($m) => self::ids($s($m)->using('soundex')->get())],
            'trigram'          => [fn ($m) => self::ids($s($m)->using('trigram')->get())],
            'similar_text'     => [fn ($m) => self::ids($s($m)->using('similar_text')->get())],
            'like'             => [fn ($m) => self::ids($s($m)->using('like')->get())],
            'tokenize'         => [fn ($m) => self::ids($s($m, 'john doe')->tokenize()->get())],
            'tokenize matchAll' => [fn ($m) => self::ids($s($m, 'john doe')->tokenize()->matchAll()->get())],
            'extended field'   => [fn ($m) => self::ids($s($m, '')->extended('name:john')->get())],
            'extended include' => [fn ($m) => self::ids($s($m, '')->extended("'john")->get())],
            'extended exact'   => [fn ($m) => self::ids($s($m, '')->extended('="john doe" | ^bob')->get())],
            'extended typo'    => [fn ($m) => self::ids($s($m, '')->extended('~jonh')->get())],
            'extended paginate' => [fn ($m) => [$s($m, '')->extended("'john")->paginate(2)->total(), $s($m, '')->extended("'john")->count()]],
            'highlight'        => [fn ($m) => [self::byId($r = $s($m)->highlight()->get(), '_highlighted'), self::byId($r, '_matches')]],
            'fallback'         => [fn ($m) => self::ids($s($m, 'jhon')->using('like')->fallback('fuzzy')->get())],
        ];
    }

    #[DataProvider('shapes')]
    public function test_a_joined_table_sharing_the_searched_column_does_not_change_the_result(Closure $run): void
    {
        $expected = $run(User::class);

        $this->assertNotEmpty($expected, 'precondition: the search matches without the join');
        $this->assertSame($expected, $run(TeamUser::class));
    }

    public function test_the_models_own_column_is_searched_not_the_joined_one(): void
    {
        $ids = self::ids(TeamUser::search('john')->get());

        $this->assertContains($this->userId('John Doe'), $ids);
        $this->assertNotContains($this->userId('Alice Smith'), $ids, 'teams.name was searched');

        // A column on both tables resolves to the FROM table (the model's own).
        $this->assertSame([$this->userId('Bob Johnson')], self::ids(TeamUser::searchOn(TeamUser::query(), 'johnson', ['name'])->using('like')->get()));
    }

    public function test_facets_count_the_models_own_column(): void
    {
        $expected = User::search('john')->facet('name')->facet('email')->getFacets();

        $this->assertNotEmpty($expected['name']);
        $this->assertSame($expected, TeamJoinOnlyUser::search('john')->facet('name')->facet('email')->getFacets());
        $this->assertSame($expected, User::search('john')->join('teams', 'teams.user_id', '=', 'users.id')->facet('name')->facet('email')->getFacets());
    }

    public function test_an_aliased_from_with_a_join(): void
    {
        $aliased = fn () => User::query()->from('users as u')
            ->join('teams', 'teams.user_id', '=', 'u.id')->select('u.*')
            ->searchFuzzy('john');

        $expected = User::query()->from('users as u')->searchFuzzy('john');

        $this->assertSame(self::ids($expected->get()), self::ids($aliased()->get()));
        $this->assertSame($expected->count(), $aliased()->count());
        $this->assertSame(self::ids($expected->paginate(100)->items()), self::ids($aliased()->paginate(100)->items()));
    }

    public function test_a_forwarded_join(): void
    {
        $joined = fn () => User::search('john')->join('teams', 'teams.user_id', '=', 'users.id')->select('users.*');

        $this->assertSame(self::ids(User::search('john')->get()), self::ids($joined()->get()));
        $this->assertSame(User::search('john')->count(), $joined()->count());
        $this->assertSame(self::ids(User::search('john')->extended('name:john')->get()), self::ids($joined()->extended('name:john')->get()));
    }

    public function test_a_plain_query_builder_with_a_join(): void
    {
        $search = fn ($query) => (new SearchBuilder($query, app(FuzzySearch::class)))->search('john')->searchIn(['name', 'email']);

        $this->assertSame(
            self::ids($search(DB::table('users'))->get()),
            self::ids($search(DB::table('users')->join('teams', 'teams.user_id', '=', 'users.id')->select('users.*'))->get())
        );
    }

    public function test_a_dotted_joined_column_searches_the_joined_table(): void
    {
        $alice = [$this->userId('Alice Smith')];

        $this->assertSame($alice, self::ids(TeamUser::searchOn(TeamUser::query(), 'johnson', ['teams.name'])->using('like')->get()));
        $this->assertSame($alice, self::ids(TeamUser::searchOn(TeamUser::query(), 'johnson', ['teams.name'])->get()));
        $this->assertSame($alice, self::ids(TeamUser::searchOn(TeamUser::query(), '', ['teams.name'])->extended('name:johnson')->get()));
    }

    public function test_a_column_only_on_the_joined_table_stays_bare(): void
    {
        // A translations-style join: "title" lives on teams only.
        $captain = [$this->userId('John Doe')];
        $search  = fn (string $term = 'captain') => TeamUser::searchOn(TeamUser::query(), $term, ['title'])->using('like');

        $this->assertSame($captain, self::ids($search()->get()));
        $this->assertSame($captain, self::ids($search('')->extended('title:captain')->get()));
        $this->assertStringNotContainsString('"users"."title"', $search()->toSql());
    }

    public function test_metaphone_checks_the_shadow_column_of_a_qualified_column(): void
    {
        Schema::table('users', fn ($table) => $table->string('name_metaphone')->nullable());
        DB::table('users')->where('name', 'John Doe')->update(['name_metaphone' => metaphone('john')]);

        $this->assertSame(
            [$this->userId('John Doe')],
            self::ids(TeamUser::searchOn(TeamUser::query(), 'john', ['name'])->using('metaphone')->get())
        );
    }

    /**
     * Every grammar, with and without a table prefix: the searched column is emitted qualified
     * everywhere (driver predicates, raw PostgreSQL/SOUNDEX SQL, relevance ORDER BY, extended
     * terms), and the qualifier carries the prefix the FROM table is written with.
     */
    public function test_every_grammar_qualifies_the_searched_column_with_the_prefixed_table(): void
    {
        $checked = 0;

        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            if (!$this->fakeDriverAvailable($driver)) {
                continue;
            }

            foreach (['', 'pre_'] as $prefix) {
                $query = fn () => $this->fakeConnectionTable($driver, 'users', $prefix)
                    ->join('teams', 'teams.user_id', '=', 'users.id')->select('users.*');
                $this->seedColumnListing($query(), ['id', 'name', 'email']);

                [$open, $close] = match ($driver) {
                    'mysql', 'mariadb' => ['`', '`'],
                    'sqlsrv'           => ['[', ']'],
                    default            => ['"', '"'],
                };
                $wrapped = $open . $prefix . 'users' . $close . '.' . $open . 'name' . $close;
                $dialect = $driver === 'sqlite' ? $prefix . 'users.name' : $wrapped; // DbDialect leaves SQLite unquoted

                foreach ([false, true] as $native) {
                    config(['fuzzy-search.use_native_functions' => $native]);

                    $builders = [];
                    foreach (['fuzzy', 'levenshtein', 'soundex', 'trigram', 'similar_text', 'like'] as $algorithm) {
                        $builders[$algorithm] = (new SearchBuilder($query(), app(FuzzySearch::class)))->search('john')->searchIn(['name'])->using($algorithm);
                    }
                    $builders['extended'] = (new SearchBuilder($query(), app(FuzzySearch::class)))->searchIn(['name'])->extended('=john ^jo ~jonh name:doe');
                    $builders['accent']   = (new SearchBuilder($query(), app(FuzzySearch::class)))->search('john')->searchIn(['name'])->accentInsensitive();

                    foreach ($builders as $label => $builder) {
                        $sql  = $builder->toSql();
                        $rest = str_replace([$wrapped, $dialect], '', $sql);

                        $this->assertStringContainsString($wrapped === $dialect ? $wrapped : $prefix . 'users', $sql, "{$driver}/{$prefix}/{$label}");
                        $this->assertDoesNotMatchRegularExpression('/(?<![\w.])[`"\[]?name[`"\]]?(?![\w.])/', $rest, "{$driver}/{$prefix}/{$label} names the column bare: {$sql}");
                        if ($prefix !== '') {
                            $this->assertDoesNotMatchRegularExpression('/(?<!pre_)users[`"\]]?\.[`"\[]?name/', $sql, "{$driver}/{$label} qualifies without the table prefix: {$sql}");
                        }
                        $checked++;
                    }
                }
            }
        }

        config(['fuzzy-search.use_native_functions' => false]);
        $this->assertGreaterThanOrEqual(64, $checked);
    }

    public function test_order_by_fuzzy_prefixes_a_qualified_column(): void
    {
        foreach (['mysql' => '`pre_users`.`name`', 'pgsql' => '"pre_users"."name"', 'sqlsrv' => '[pre_users].[name]', 'sqlite' => 'pre_users.name'] as $driver => $expected) {
            $sql = $this->fakeConnectionTable($driver, 'users', 'pre_')->orderByFuzzy('users.name', 'john')->toSql();

            $this->assertStringContainsString($expected, $sql, $driver);
        }
    }

    /** A fake connection cannot read its schema: hand SearchableColumns the listing it would read. */
    private function seedColumnListing(\Illuminate\Database\Query\Builder $query, array $columns): void
    {
        $listings = new \ReflectionProperty(SearchableColumns::class, 'listings');
        $listings->setValue(null, [$query->getConnection()->getName() . '|users' => $columns] + $listings->getValue());
    }
}

/** A one-to-one global-scope join to a table that also has a "name" column. */
class TeamUser extends User
{
    protected static function booted(): void
    {
        static::addGlobalScope('team', fn ($query) => $query
            ->join('teams', 'teams.user_id', '=', 'users.id')
            ->select('users.*'));
    }
}

/** The same join without a select(): a scope's select() would replace the facet query's own. */
class TeamJoinOnlyUser extends User
{
    protected static function booted(): void
    {
        static::addGlobalScope('team', fn ($query) => $query->join('teams', 'teams.user_id', '=', 'users.id'));
    }
}
