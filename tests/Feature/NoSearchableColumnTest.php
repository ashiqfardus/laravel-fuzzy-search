<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Events\FuzzySearchExecuted;
use Ashiqfardus\LaravelFuzzySearch\Exceptions\SearchableColumnsNotFoundException;
use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Closure;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A search with no searchable column (nothing declared, nothing auto-detected, no searchIn())
 * added no condition and returned every row. It now matches nothing, exactly like a term below
 * min_search_length: no rows, no FuzzySearchExecuted, no cache write. The fixture is a
 * zero-config model whose only text columns are $hidden, so auto-detection finds none.
 */
class NoSearchableColumnTest extends TestCase
{
    /** @return array<string, array{Closure(SearchBuilder): mixed, mixed}> */
    public static function terminals(): array
    {
        return [
            'get'            => [fn ($b) => $b->get()->all(), []],
            'first'          => [fn ($b) => $b->first(), null],
            'paginate'       => [fn ($b) => [($p = $b->paginate(5))->total(), $p->items()], [0, []]],
            'simplePaginate' => [fn ($b) => $b->simplePaginate(5)->items(), []],
            'count'          => [fn ($b) => $b->count(), 0],
            'getFacets'      => [fn ($b) => $b->facet('email')->getFacets(), ['email' => []]],
        ];
    }

    #[DataProvider('terminals')]
    public function test_a_search_with_no_searchable_column_matches_nothing(Closure $run, mixed $expected): void
    {
        $this->assertSame([], (new HiddenColumnsUser())->getSearchableColumns(), 'precondition: nothing is detectable');

        $events = 0;
        $writes = 0;
        Event::listen(FuzzySearchExecuted::class, function () use (&$events) {
            $events++;
        });
        Event::listen(KeyWritten::class, function () use (&$writes) {
            $writes++;
        });

        $makers = [
            'search()'          => fn () => HiddenColumnsUser::search('john'),
            'searchOn()'        => fn () => HiddenColumnsUser::searchOn(HiddenColumnsUser::query(), 'john'),
            'searchFuzzy scope' => fn () => HiddenColumnsUser::searchFuzzy('john'),
            'inverted index'    => fn () => HiddenColumnsUser::search('john')->useInvertedIndex(),
            'fallback'          => fn () => HiddenColumnsUser::search('john')->fallback('like'),
            'cached'            => fn () => HiddenColumnsUser::search('john')->cache(5),
            'plain builder'     => fn () => (new SearchBuilder(DB::table('users'), app(FuzzySearch::class)))->search('john'),
        ];

        foreach ($makers as $path => $make) {
            $this->assertSame($expected, $run($make()), $path);
        }

        $this->assertSame(0, $events, 'nothing ran, so nothing reaches FuzzySearchExecuted listeners or the analytics log');
        $this->assertSame(0, $writes, 'nothing ran, so nothing is cached');
    }

    public function test_a_federated_model_with_no_searchable_column_contributes_nothing(): void
    {
        $expected = FederatedSearch::across([User::class])->search('john')->getCounts()['User'];
        $this->assertGreaterThan(0, $expected, 'precondition: User matches');

        $federated = fn () => FederatedSearch::across([HiddenColumnsUser::class, User::class])->search('john');

        $this->assertSame(['HiddenColumnsUser' => 0, 'User' => $expected], $federated()->getCounts());
        $this->assertSame($expected, $federated()->paginate(50)->total());
        $this->assertSame([User::class], $federated()->limit(50)->get()->pluck('_model_class')->unique()->values()->all());
    }

    public function test_table_search_with_no_column_matches_nothing(): void
    {
        $names = fn (Closure $closure, string $search = 'john') => HiddenColumnsUser::query()
            ->where(fn ($q) => $closure($q, $search))->pluck('name')->all();

        $this->assertSame([], $names(FuzzySearch::tableSearch()), "the model's columns: none");
        $this->assertSame([], $names(FuzzySearch::tableSearch([])), 'no columns given');

        // Filament v4+ searchUsing() calls it bare, outside a where group.
        $query = HiddenColumnsUser::query();
        FuzzySearch::tableSearch()($query, 'john');
        $this->assertSame(0, $query->count());

        // An empty search box is no search at all.
        $this->assertCount(7, $names(FuzzySearch::tableSearch(), ''));
    }

    /**
     * A table that cannot be listed (a $table typo, not migrated yet, the connection down) is not
     * a model with no column: detection could not look. The search runs, so the error surfaces
     * instead of an empty result that hides it.
     */
    public function test_a_model_whose_table_cannot_be_read_surfaces_the_error(): void
    {
        $this->assertFalse(Schema::hasTable('no_such_table_er35'), 'precondition');

        $surfaced = [];
        foreach ([
            'get'      => fn () => MissingTableModel::search('john')->get(),
            'count'    => fn () => MissingTableModel::search('john')->count(),
            'paginate' => fn () => MissingTableModel::search('john')->paginate(5),
        ] as $terminal => $run) {
            try {
                $run();
                $surfaced[$terminal] = false;
            } catch (QueryException) {
                $surfaced[$terminal] = true;
            }
        }

        $this->assertSame(['get' => true, 'count' => true, 'paginate' => true], $surfaced);
    }

    public function test_what_still_searches(): void
    {
        // extended() has always refused a model with no column.
        try {
            HiddenColumnsUser::search('')->extended('john')->get();
            $this->fail('extended() searched a model with no column');
        } catch (SearchableColumnsNotFoundException) {
        }

        // A searchableText() hook is something to search on the index path, but a fallback()
        // retry runs on the LIKE path, where it still has no column: nothing, not every row.
        app(IndexManager::class)->indexBatch(HiddenTextHookUser::all());
        $this->assertSame(['John Doe'], HiddenTextHookUser::search('john doe')->useInvertedIndex()->take(1)->get()->pluck('name')->all());
        $this->assertSame(0, HiddenTextHookUser::search('zzzz')->useInvertedIndex()->fallback('like')->get()->count());
        $this->assertSame(0, HiddenTextHookUser::search('zzzz')->useInvertedIndex()->fallback('like')->count());

        // The cache spy sees a real search write.
        $writes = 0;
        Event::listen(KeyWritten::class, function () use (&$writes) {
            $writes++;
        });
        $this->assertNotEmpty(User::search('john')->cache(5)->get());
        $this->assertSame(1, $writes);

        // An empty term with allow_empty_search still lists every row.
        config(['fuzzy-search.allow_empty_search' => true]);
        $this->assertCount(7, HiddenColumnsUser::search('')->get());
    }
}

/** Zero-config, and every text column is hidden: auto-detection finds nothing to search. */
class HiddenColumnsUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];
    protected $hidden  = ['name', 'email'];
}

/** Zero-config on a table that does not exist. */
class MissingTableModel extends Model
{
    use Searchable;

    protected $table = 'no_such_table_er35';
}

/** No column either, but a searchableText() hook gives the index something to hold. */
class HiddenTextHookUser extends HiddenColumnsUser
{
    public function searchableText(): array
    {
        return ['name' => $this->getAttributes()['name'] ?? ''];
    }
}
