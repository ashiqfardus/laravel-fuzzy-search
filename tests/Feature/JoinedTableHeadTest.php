<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\FuzzySearch;
use Ashiqfardus\LaravelFuzzySearch\SearchBuilder;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A user model whose method names match the tables it is joined to: items() returns an array,
 * notifications() comes from Laravel's Notifiable trait, tokens() is untyped (as Sanctum's is).
 * Every call is counted.
 */
class JoinUser extends Model
{
    use Notifiable;

    public static int $calls = 0;

    protected $table   = 'users';
    protected $guarded = [];

    public function items(): array
    {
        static::$calls++;

        return [];
    }

    public function tokens()
    {
        static::$calls++;

        return $this->hasMany(JoinUser::class, 'id');
    }
}

/**
 * Ruling ER-57: a dotted head that names the FROM table or its alias, or a joined table or its
 * alias, is table.column (v2.0 and ER-33) and no method is called. ER-50's relation check applies
 * only otherwise. `join('items')->searchIn(['items.name'])` threw because the model had an
 * items() method.
 */
class JoinedTableHeadTest extends TestCase
{
    private const TABLES = ['items', 'notifications', 'tokens'];

    protected function setUp(): void
    {
        parent::setUp();
        JoinUser::$calls = 0;

        $johnId = DB::table('users')->where('name', 'John Doe')->value('id');

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function ($t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->string('name');
            });
            DB::table($table)->insert(['user_id' => $johnId, 'name' => "widget {$table}"]);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function search($query, string $column): array
    {
        return (new SearchBuilder($query, app(FuzzySearch::class)))
            ->search('widget')->searchIn([$column])->using('like')->get()->pluck('name')->all();
    }

    public function test_a_joined_table_named_like_a_model_method_is_a_table_column(): void
    {
        foreach (self::TABLES as $table) {
            $query = JoinUser::query()->join($table, "{$table}.user_id", '=', 'users.id')->select('users.*');

            $this->assertSame(['John Doe'], $this->search($query, "{$table}.name"), $table);
        }

        $this->assertSame(0, JoinUser::$calls);
    }

    public function test_a_joined_tables_alias_is_a_table_column(): void
    {
        $query = JoinUser::query()->join('items as i', 'i.user_id', '=', 'users.id')->select('users.*');
        $this->assertSame(['John Doe'], $this->search($query, 'i.name'));

        $query = JoinUser::query()->join('notifications as tokens', 'tokens.user_id', '=', 'users.id')->select('users.*');
        $this->assertSame(['John Doe'], $this->search($query, 'tokens.name'), 'the alias, not the table, names the head');

        $this->assertSame(0, JoinUser::$calls);
    }

    public function test_the_from_tables_alias_is_a_table_column(): void
    {
        $query = JoinUser::query()->from('users as items');

        $this->assertSame(['relation' => null, 'column' => 'items.name'], (new SearchBuilder($query, app(FuzzySearch::class)))
            ->search('john')->searchIn(['items.name'])->getDebugInfo()['column_targets']['items.name']);
        $this->assertSame(0, JoinUser::$calls);
    }

    public function test_without_the_join_the_method_is_still_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(JoinUser::class . '::items is not a relation');

        try {
            $this->search(JoinUser::query(), 'items.name');
        } finally {
            $this->assertSame(0, JoinUser::$calls);
        }
    }
}
