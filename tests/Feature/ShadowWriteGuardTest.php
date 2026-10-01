<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Feature;

use Ashiqfardus\LaravelFuzzySearch\Observers\SearchableObserver;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GuardedShadowPerson extends Model
{
    use Searchable;

    protected $table   = 'guarded_shadow_people';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'nickname' => 1]];

    /** An accessor: its code comes from no stored text, so its shadow write has nothing to check. */
    public function getNicknameAttribute(?string $value): ?string
    {
        return $value === null ? null : 'Sir ' . $value;
    }
}

/** A virtual column: an accessor with no stored column behind it, only its shadow. */
class VirtualShadowPerson extends Model
{
    use Searchable;

    protected $table   = 'guarded_shadow_people';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'display_name' => 1]];

    public function getDisplayNameAttribute(): string
    {
        return 'Doctor ' . $this->attributes['name'];
    }
}

/**
 * SB-3. The shadow code is computed from the text one process holds and written by a later,
 * separate UPDATE that did not check the text was still there. A save of the same row in between
 * (another request, or a save during fuzzy-search:rebuild's backfill of its chunk) left the row with
 * one text and the other text's code: using('metaphone') then found it by the wrong sound. Each
 * shadow write now holds only while the source column still has the text it encodes, and a save
 * writes the shadow whenever it changed the source, not only when its loaded shadow differs.
 */
class ShadowWriteGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('guarded_shadow_people');
        Schema::create('guarded_shadow_people', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_metaphone')->nullable();
            $table->string('nickname')->nullable();
            $table->string('nickname_metaphone')->nullable();
            $table->string('display_name_metaphone')->nullable();
        });
        SearchableObserver::resetColumnCache();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('guarded_shadow_people');
        parent::tearDown();
    }

    private function shadow(int|string $id): ?string
    {
        return DB::table('guarded_shadow_people')->where('id', $id)->value('name_metaphone');
    }

    public function test_a_save_that_lands_between_another_save_and_its_shadow_write_keeps_its_code(): void
    {
        $id = GuardedShadowPerson::create(['name' => 'alpha'])->id;
        $a  = GuardedShadowPerson::find($id);
        $b  = GuardedShadowPerson::find($id);

        // A's text is written; before A's shadow write, B saves the row whole (text and shadow).
        GuardedShadowPerson::updated(function (GuardedShadowPerson $model) use ($a, $b) {
            if ($model === $a) {
                $b->update(['name' => 'zebra crossing']);
            }
        });
        $a->update(['name' => 'stephen smith']);

        $this->assertSame('zebra crossing', DB::table('guarded_shadow_people')->where('id', $id)->value('name'));
        $this->assertSame(metaphone('zebra crossing'), $this->shadow($id));
    }

    public function test_a_save_whose_code_equals_its_stale_loaded_shadow_still_writes_it(): void
    {
        $id = GuardedShadowPerson::create(['name' => 'Steven'])->id;
        $a  = GuardedShadowPerson::find($id); // holds STFN

        GuardedShadowPerson::find($id)->update(['name' => 'Robert']); // the row now holds RBRT
        $a->update(['name' => 'Stephen']); // STFN again, which $a already holds

        $this->assertSame(metaphone('Stephen'), $this->shadow($id));
        $this->assertSame(['Stephen'], GuardedShadowPerson::search('steffen')->searchIn(['name'])->using('metaphone')->get()->pluck('name')->all());
    }

    public function test_the_backfill_keeps_a_code_a_save_wrote_after_its_chunk_was_read(): void
    {
        foreach (['alpha one', 'bravo two', 'charlie three', 'delta four', 'echo five'] as $name) {
            DB::table('guarded_shadow_people')->insert(['name' => $name]); // no shadow yet
        }
        DB::table('guarded_shadow_people')->insert(['name' => null]);

        // The rebuild reads its chunk, indexes it, then backfills the instances it read.
        $chunk = GuardedShadowPerson::query()->orderBy('id')->get();
        $fifth = $chunk[4];
        GuardedShadowPerson::find($fifth->id)->update(['name' => 'zebra crossing']); // a save in between

        app(SearchableObserver::class)->backfillShadowColumns($chunk);

        $this->assertSame(metaphone('zebra crossing'), $this->shadow($fifth->id));
        foreach ($chunk->take(4) as $row) {
            $this->assertSame(metaphone($row->name), $this->shadow($row->id), $row->name);
        }
        $this->assertNull($this->shadow($chunk[5]->id));
        $this->assertSame(['zebra crossing'], GuardedShadowPerson::search('zebra crossing')->searchIn(['name'])->using('metaphone')->get()->pluck('name')->all());
    }

    public function test_the_backfill_writes_in_statements_under_sql_server_s_parameter_limit(): void
    {
        foreach (array_chunk(range(1, 1100), 500) as $ids) { // SQLite before 3.32 binds at most 999
            DB::table('guarded_shadow_people')->insert(array_map(fn (int $i) => ['name' => "name {$i}"], $ids));
        }

        $bindings = [];
        DB::listen(function ($query) use (&$bindings) {
            if (preg_match('/^\s*update\b/i', $query->sql) && str_contains($query->sql, 'guarded_shadow_people')) {
                $bindings[] = count($query->bindings);
            }
        });

        app(SearchableObserver::class)->backfillShadowColumns(GuardedShadowPerson::query()->get());

        $this->assertSame(0, DB::table('guarded_shadow_people')->whereNull('name_metaphone')->count());
        $this->assertSame([2000, 2000, 400], $bindings); // 500 rows a statement, four bindings a row
    }

    /**
     * An accessor's code comes from what the accessor returns, not from the stored text, so the
     * stored text cannot tell whether it is current, and a virtual column has no stored text at all
     * (a guard on it is an unknown column): those shadows are written unguarded, as before.
     */
    public function test_accessor_columns_are_written_without_a_guard(): void
    {
        $person  = GuardedShadowPerson::create(['name' => 'Ann', 'nickname' => 'Bob']);
        $virtual = VirtualShadowPerson::create(['name' => 'Who']);
        $read    = fn (string $shadow) => DB::table('guarded_shadow_people')->orderBy('id')->pluck($shadow)->all();

        $this->assertSame([metaphone('Sir Bob'), null], $read('nickname_metaphone'));
        $this->assertSame([null, metaphone('Doctor Who')], $read('display_name_metaphone'));

        DB::table('guarded_shadow_people')->update(['nickname_metaphone' => null, 'display_name_metaphone' => null]);
        app(SearchableObserver::class)->backfillShadowColumns(GuardedShadowPerson::query()->whereKey($person->id)->get());
        app(SearchableObserver::class)->backfillShadowColumns(VirtualShadowPerson::query()->whereKey($virtual->id)->get());

        $this->assertSame([metaphone('Sir Bob'), null], $read('nickname_metaphone'));
        $this->assertSame([null, metaphone('Doctor Who')], $read('display_name_metaphone'));
    }
}
