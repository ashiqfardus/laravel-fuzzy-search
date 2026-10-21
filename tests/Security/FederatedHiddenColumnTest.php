<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Security;

use Ashiqfardus\LaravelFuzzySearch\FederatedSearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** No Searchable trait: the federated search reaches the model through the Eloquent macros. */
class FederatedPlainPerson extends Model
{
    protected $table   = 'federated_people';
    protected $guarded = [];
    public $timestamps = false;
    protected $hidden  = ['ssn'];
}

class FederatedHiddenColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('federated_people');
        Schema::create('federated_people', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ssn');
        });
        DB::table('federated_people')->insert([['name' => 'Ann Lee', 'ssn' => '123-45-6789']]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('federated_people');
        parent::tearDown();
    }

    public function test_a_hidden_column_in_search_in_is_refused_on_a_model_without_searchable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Searchable');

        FederatedSearch::across([FederatedPlainPerson::class])->search('123-4')->searchIn(['ssn'])->get();
    }

    public function test_an_ordinary_column_in_search_in_still_works(): void
    {
        $found = FederatedSearch::across([FederatedPlainPerson::class])->search('Ann')->searchIn(['name'])->get();

        $this->assertCount(1, $found);
    }
}
