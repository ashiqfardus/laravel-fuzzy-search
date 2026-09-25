<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

require_once __DIR__ . '/../RelationModels.php';

use Ashiqfardus\LaravelFuzzySearch\Exceptions\QuerySyntaxException;
use Ashiqfardus\LaravelFuzzySearch\Tests\Author;
use Ashiqfardus\LaravelFuzzySearch\Tests\Concerns\CreatesRelationTables;
use Ashiqfardus\LaravelFuzzySearch\Tests\Post;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtendedHiddenEmailUser extends Model
{
    use Searchable;

    protected $table   = 'users';
    protected $guarded = [];
    protected $hidden  = ['email'];

    protected array $searchable = ['columns' => ['name' => 10, 'email' => 5], 'algorithm' => 'like'];
}

class ExtendedHiddenNameAuthor extends Author
{
    protected $hidden = ['name'];
}

class ExtendedHiddenAuthorPost extends Post
{
    public function author(): BelongsTo
    {
        return $this->belongsTo(ExtendedHiddenNameAuthor::class, 'author_id');
    }
}

/**
 * Ruling ER-95: extended()'s "Unknown search field" message, shown to whoever typed the query, named
 * every searchable column, the ones the model hides included. It now lists only the fields the model
 * shows. Matching is unchanged (ER-66): a field scope on a hidden column still searches it.
 */
class ExtendedHiddenFieldTest extends TestCase
{
    use CreatesRelationTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRelationTables();
        $this->seedRelationFixtures();
    }

    protected function tearDown(): void
    {
        $this->dropRelationTables();
        parent::tearDown();
    }

    private function unknownFieldMessage(\Closure $run): string
    {
        try {
            $run();
        } catch (QuerySyntaxException $e) {
            return $e->getMessage();
        }

        $this->fail('no QuerySyntaxException');
    }

    public function test_the_message_does_not_list_a_hidden_column(): void
    {
        $this->assertSame(
            'Unknown search field "nope". Searchable fields: name.',
            $this->unknownFieldMessage(fn () => ExtendedHiddenEmailUser::search('')->extended('nope:john')->get())
        );
    }

    public function test_the_message_does_not_list_a_relation_leaf_its_related_model_hides(): void
    {
        $this->assertSame(
            'Unknown search field "nope". Searchable fields: title.',
            $this->unknownFieldMessage(fn () => ExtendedHiddenAuthorPost::search('')->searchIn(['author.name'])->extended('nope:ring')->get())
        );
    }

    public function test_a_field_scope_on_a_hidden_column_still_matches(): void
    {
        $this->assertSame(['John Doe'], ExtendedHiddenEmailUser::search('')->extended('email:john@')->get()->pluck('name')->all());
        $this->assertSame(['The Ring'], ExtendedHiddenAuthorPost::search('')->searchIn(['author.name'])->extended('author.name:tolkien')->get()->pluck('title')->all());
    }
}
