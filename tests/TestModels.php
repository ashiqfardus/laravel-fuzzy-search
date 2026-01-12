<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests;

use Illuminate\Database\Eloquent\Model;
use Ashiqfardus\LaravelFuzzySearch\Traits\Fuzzy;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable;

/**
 * Test User Model with Fuzzy and Searchable traits
 */
class User extends Model
{
    use Fuzzy, Searchable;

    protected $table = 'users';
    protected $guarded = [];

    protected array $fuzzySearchable = ['name', 'email'];
    protected string $fuzzyAlgorithm = 'levenshtein';
    protected array $fuzzyOptions = ['max_distance' => 3];

    protected array $searchable = [
        'columns' => [
            'name' => 10,
            'email' => 5,
        ],
        'algorithm' => 'fuzzy',
    ];
}

/**
 * Test Product Model with Fuzzy and Searchable traits
 */
class Product extends Model
{
    use Fuzzy, Searchable;

    protected $table = 'products';
    protected $guarded = [];

    protected array $fuzzySearchable = ['title', 'description'];

    protected array $searchable = [
        'columns' => [
            'title' => 10,
            'description' => 5,
        ],
        'algorithm' => 'fuzzy',
    ];
}
