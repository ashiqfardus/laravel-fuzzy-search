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

/** Its columns compare accent-insensitively where the database can (see setUp()). */
class AccentShadowPerson extends Model
{
    use Searchable;

    protected $table   = 'accent_shadow_people';
    protected $guarded = [];
    public $timestamps = false;

    protected array $searchable = ['columns' => ['name' => 10, 'legacy' => 1]];
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
        Schema::dropIfExists('accent_shadow_people');
        Schema::create('accent_shadow_people', function ($table) {
            $table->id();
            $name   = $table->string('name')->nullable();
            $legacy = $table->string('legacy')->nullable();
            $table->string('name_metaphone')->nullable();
            $table->string('legacy_metaphone')->nullable();

            // Accent-insensitive, as the MySQL/MariaDB default and a SQL Server _AI collation are; a
            // legacy latin1 column on MySQL/MariaDB. PostgreSQL and SQLite compare exactly anyway.
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $name->collation('utf8mb4_unicode_ci');
                $legacy->charset('latin1')->collation('latin1_swedish_ci');
            } elseif (DB::getDriverName() === 'sqlsrv') {
                $name->collation('Latin1_General_100_CI_AI');
            }
        });
        SearchableObserver::resetColumnCache();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('guarded_shadow_people');
        Schema::dropIfExists('accent_shadow_people');
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

    /**
     * TB-2. The guard compared the text under the column's collation: on MySQL/MariaDB's
     * accent-insensitive default (and a SQL Server _AI one) 'munoz ozturk' = 'muñoz öztürk', while
     * metaphone() drops the accented letters (MNSSTRK, MSSTRK). A save racing an accent-only edit
     * passed the guard and wrote its code beside the other text. The guard now compares exactly.
     */
    public function test_an_accent_only_edit_between_a_save_and_its_shadow_write_keeps_its_code(): void
    {
        $id = AccentShadowPerson::create(['name' => 'alpha'])->id;
        $a  = AccentShadowPerson::find($id);
        $b  = AccentShadowPerson::find($id);

        AccentShadowPerson::updated(function (AccentShadowPerson $model) use ($a, $b) {
            if ($model === $a) {
                $b->update(['name' => 'muñoz öztürk']);
            }
        });
        $a->update(['name' => 'munoz ozturk']);

        $this->assertSame('muñoz öztürk', DB::table('accent_shadow_people')->where('id', $id)->value('name'));
        $this->assertSame(metaphone('muñoz öztürk'), DB::table('accent_shadow_people')->where('id', $id)->value('name_metaphone'));
        $this->assertSame(['muñoz öztürk'], AccentShadowPerson::search('mus sturk')->searchIn(['name'])->using('metaphone')->get()->pluck('name')->all());
        $this->assertSame([], AccentShadowPerson::search('mns strk')->searchIn(['name'])->using('metaphone')->get()->pluck('name')->all());
    }

    public function test_the_backfill_keeps_the_code_of_an_accent_only_edit_made_after_its_chunk_was_read(): void
    {
        foreach (['alpha one', 'bravo two', 'munoz ozturk'] as $name) {
            DB::table('accent_shadow_people')->insert(['name' => $name]); // no shadow yet
        }

        $chunk = AccentShadowPerson::query()->orderBy('id')->get();
        AccentShadowPerson::find($chunk[2]->id)->update(['name' => 'muñoz öztürk']); // a save in between

        app(SearchableObserver::class)->backfillShadowColumns($chunk);

        $shadows = DB::table('accent_shadow_people')->orderBy('id')->pluck('name_metaphone')->all();
        $this->assertSame([metaphone('alpha one'), metaphone('bravo two'), metaphone('muñoz öztürk')], $shadows);
    }

    /**
     * The guard's NULL branch: a text cleared to NULL and a save of text racing it. Each write's code
     * lands only while the column still holds its own text (NULL or the text), on the observer's
     * write and the backfill's.
     */
    public function test_a_null_text_racing_a_save_never_leaves_its_code_beside_the_other_text(): void
    {
        $race = function (?string $first, ?string $then): int {
            $id = GuardedShadowPerson::create(['name' => 'alpha'])->id;
            $a  = GuardedShadowPerson::find($id);
            $b  = GuardedShadowPerson::find($id);
            $on = true;
            GuardedShadowPerson::updated(function (GuardedShadowPerson $model) use ($a, $b, $then, &$on) {
                if ($on && $model === $a) {
                    $on = false;
                    $b->update(['name' => $then]);
                }
            });
            $a->update(['name' => $first]);

            return $id;
        };

        $id = $race(null, 'zebra crossing'); // A clears the text; B writes text before A's shadow write
        $this->assertSame(metaphone('zebra crossing'), $this->shadow($id));

        $id = $race('stephen smith', null); // A writes text; B clears it before A's shadow write
        $this->assertNull($this->shadow($id));

        // The backfill read a row whose text was cleared without events (its shadow is stale); a save
        // writes text and its code before the backfill runs.
        $id    = DB::table('guarded_shadow_people')->insertGetId(['name' => null, 'name_metaphone' => 'STL']);
        $chunk = GuardedShadowPerson::query()->whereKey($id)->get();
        GuardedShadowPerson::find($id)->update(['name' => 'zebra crossing']);
        app(SearchableObserver::class)->backfillShadowColumns($chunk);
        $this->assertSame(metaphone('zebra crossing'), $this->shadow($id));
    }

    /**
     * The exact comparison reads the column's characters, not its bytes: a latin1 column on
     * MySQL/MariaDB holds 'Müller' in other bytes than the UTF-8 the text is bound in, and still
     * matches it, so its save writes the code.
     */
    public function test_the_guard_matches_a_column_stored_in_another_character_set(): void
    {
        $id = AccentShadowPerson::create(['name' => 'Ann', 'legacy' => 'Müller Straße'])->id;

        $this->assertSame(metaphone('Müller Straße'), DB::table('accent_shadow_people')->where('id', $id)->value('legacy_metaphone'));

        DB::table('accent_shadow_people')->update(['legacy_metaphone' => null]);
        app(SearchableObserver::class)->backfillShadowColumns(AccentShadowPerson::query()->get());
        $this->assertSame(metaphone('Müller Straße'), DB::table('accent_shadow_people')->where('id', $id)->value('legacy_metaphone'));
    }
}
