<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../TestModels.php';
require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Http\Resources\FuzzySearchResource;
use Ashiqfardus\LaravelFuzzySearch\Indexing\IndexManager;
use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class HiddenEmailUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];
    protected $hidden  = ['email'];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'like',
    ];
}

class VisibleNameUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];
    protected $visible = ['id', 'name'];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'like',
    ];
}

class HiddenNameAuthor extends Author
{
    protected $hidden = ['name'];
}

class PostWithHiddenAuthorName extends Post
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(HiddenNameAuthor::class, 'author_id');
    }
}

class PostHidingItsAuthor extends Post
{
    protected $hidden = ['author'];
}

/**
 * _highlighted and _matches showed every searched column, $hidden ones included: `toArray()`
 * omitted email while `_highlighted.email` was `<em>john</em>@example.com`. They now hold only
 * what toArray() would show — $hidden and $visible read off the row itself when the search
 * builds them, related rows included — and FuzzySearchResource re-applies the row's own rule
 * when it renders.
 */
class HiddenColumnHighlightTest extends TestCase
{
    use CreatesRelationTables;

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    /** @return array<string, \Closure(class-string): \Illuminate\Support\Collection> */
    private function paths(): array
    {
        return [
            'like'           => fn (string $model) => $model::search('john')->highlight('em')->get(),
            'extended'       => fn (string $model) => $model::search('')->extended('john')->highlight('em')->get(),
            'index'          => fn (string $model) => $model::search('john')->useInvertedIndex()->highlight('em')->get(),
            'paginate'       => fn (string $model) => collect($model::search('john')->highlight('em')->paginate(10)->items()),
            'index paginate' => fn (string $model) => collect($model::search('john')->useInvertedIndex()->highlight('em')->paginate(10)->items()),
        ];
    }

    /** @return array<string, string[]> path => the columns _highlighted and _matches name for John Doe */
    private function highlightedColumns(string $model): array
    {
        app(IndexManager::class)->indexBatch($model::all());

        $columns = [];
        foreach ($this->paths() as $path => $run) {
            $row = $run($model)->firstWhere('name', 'John Doe');
            $columns[$path]               = array_keys($row->_highlighted);
            $columns["{$path} _matches"]  = array_column($row->_matches, 'column');
            $columns["{$path} resource"]  = array_keys((new FuzzySearchResource($row))->toArray(Request::create('/'))['_highlighted']);
        }

        return $columns;
    }

    public function test_a_hidden_column_is_never_highlighted(): void
    {
        foreach ($this->highlightedColumns(HiddenEmailUser::class) as $path => $columns) {
            $this->assertSame(['name'], $columns, $path);
        }
    }

    public function test_with_visible_set_only_those_columns_are_highlighted(): void
    {
        foreach ($this->highlightedColumns(VisibleNameUser::class) as $path => $columns) {
            $this->assertSame(['name'], $columns, $path);
        }
    }

    public function test_make_visible_at_runtime_brings_the_column_back(): void
    {
        HiddenEmailUser::retrieved(fn (HiddenEmailUser $user) => $user->makeVisible('email'));

        foreach ($this->highlightedColumns(HiddenEmailUser::class) as $path => $columns) {
            $this->assertSame(['name', 'email'], $columns, $path);
        }
    }

    public function test_the_resource_honours_make_hidden_after_the_search(): void
    {
        $row = HiddenEmailUser::search('john')->highlight('em')->get()->firstWhere('name', 'John Doe')->makeHidden('name');
        $data = (new FuzzySearchResource($row))->toArray(Request::create('/'));

        $this->assertSame([], $data['_highlighted']);
        $this->assertSame([], $data['_matches']);
    }

    public function test_a_relation_column_follows_the_related_models_hidden(): void
    {
        $this->createRelationTables();
        $this->seedRelationFixtures();

        $search = fn (string $model) => $model::search('tolkien')->searchIn(['title', 'author.name'])->highlight('em')->get()->first();

        $this->assertSame(['title', 'author.name'], array_keys($search(Post::class)->_highlighted), 'control: shown when nothing hides it');

        foreach ([PostWithHiddenAuthorName::class => 'the related model hides name', PostHidingItsAuthor::class => 'the post hides its author relation'] as $model => $why) {
            $row = $search($model);

            $this->assertSame(['title'], array_keys($row->_highlighted), $why);
            $this->assertSame([], $row->_matches, $why);
            $this->assertSame(['title'], array_keys((new FuzzySearchResource($row))->toArray(Request::create('/'))['_highlighted']), "{$why}, resource");
        }
    }
    /** Finding 11: _debug follows the #5 rule — no hidden column in its columns, weights or column_scores. */
    public function test_debug_output_leaves_hidden_columns_out(): void
    {
        app(IndexManager::class)->indexBatch(HiddenEmailUser::all());

        foreach ([
            'like'     => fn () => HiddenEmailUser::search('john')->debugScore()->get(),
            'extended' => fn () => HiddenEmailUser::search('')->extended('john')->debugScore()->get(),
            'index'    => fn () => HiddenEmailUser::search('john')->useInvertedIndex()->debugScore()->get(),
        ] as $path => $run) {
            $debug = $run()->firstWhere('name', 'John Doe')->_debug;

            // The index path scores documents, not columns: its column_scores is empty.
            $this->assertSame($path === 'index' ? [] : ['name'], array_keys($debug['column_scores']), $path);
            $this->assertSame(['name'], $debug['columns'], $path);
            $this->assertSame(['name'], array_keys($debug['weights']), $path);
        }

        $this->createRelationTables();
        $this->seedRelationFixtures();
        $debug = PostWithHiddenAuthorName::search('tolkien')->searchIn(['title', 'author.name'])->debugScore()->get()->first()->_debug;
        $this->assertSame(['title'], array_keys($debug['column_scores']), 'the related model hides name');
    }

    /** Ruling ER-51: the suggest() table scan never offers words from a hidden column. */
    public function test_suggestions_never_come_from_a_hidden_column(): void
    {
        $suggestions = HiddenEmailUser::search('jo')->suggestFrom('table')->suggest(10);

        $this->assertNotEmpty($suggestions, 'the visible name column still suggests');
        foreach ($suggestions as $suggestion) {
            $this->assertStringNotContainsString('@', $suggestion);
        }

        $this->assertSame([], VisibleNameUser::search('example')->suggestFrom('table')->suggest(10), 'only an email holds "example"');
    }
}
