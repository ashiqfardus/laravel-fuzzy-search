<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Unit;

require_once __DIR__ . '/../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Support\DbDialect;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The deprecated v1 `fuzzy-search:index` creates its search_index table with a FULLTEXT index,
 * which Laravel only builds on MySQL, MariaDB and PostgreSQL. Elsewhere it must stop with an
 * explanation instead of a schema-grammar exception.
 */
class IndexCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('search_index');

        parent::tearDown();
    }

    public function test_it_creates_the_legacy_table_only_where_fulltext_exists(): void
    {
        $driver = DB::connection()->getDriverName();

        if (DbDialect::isMySqlFamily($driver) || $driver === DbDialect::PGSQL) {
            $this->artisan('fuzzy-search:index', ['model' => User::class])->assertExitCode(0);
            $this->assertSame(User::count(), DB::table('search_index')->count());

            return;
        }

        $this->artisan('fuzzy-search:index', ['model' => User::class])
            ->expectsOutputToContain('fuzzy-search:rebuild')
            ->assertExitCode(1);
        $this->assertFalse(Schema::hasTable('search_index'));
    }

    public function test_an_existing_table_is_used_on_every_driver(): void
    {
        Schema::create('search_index', function ($table) {
            $table->id();
            $table->string('model');
            $table->unsignedBigInteger('model_id');
            $table->text('content');
            $table->timestamps();
        });

        $this->artisan('fuzzy-search:index', ['model' => User::class])->assertExitCode(0);

        $this->assertSame(User::count(), DB::table('search_index')->count());
    }
}
