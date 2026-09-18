<?php

/**
 * Two Searchable models sharing the class basename "User" in different namespaces, both on the
 * `users` table. FederatedSearch tags results with a basename (`_model_type`, `getGrouped()`),
 * so these fixtures pin the places where a basename must NOT be the key: the per-model counts
 * behind paginate()->total(), which used to overwrite each other.
 *
 * Braced namespaces: two classes with the same short name cannot share a file otherwise.
 */

namespace Ashiqfardus\LaravelFuzzySearch\Tests\SameNameA {

    class User extends \Illuminate\Database\Eloquent\Model
    {
        use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

        protected $table = 'users';
        protected $guarded = [];
        protected array $searchable = ['columns' => ['name' => 10], 'algorithm' => 'like'];
    }
}

namespace Ashiqfardus\LaravelFuzzySearch\Tests\SameNameB {

    class User extends \Illuminate\Database\Eloquent\Model
    {
        use \Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

        protected $table = 'users';
        protected $guarded = [];
        protected array $searchable = ['columns' => ['name' => 10], 'algorithm' => 'like'];
    }
}
