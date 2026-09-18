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

    /** Local scope used by BuilderPassthroughTest. */
    public function scopeEmailDomain($query, string $domain)
    {
        return $query->where('email', 'like', '%@' . $domain);
    }
}

/**
 * User model with a SoftDeletes global scope, used to pin that paginate()/count() totals
 * honour Eloquent global scopes (Ruling P30 / C1, I4) — same "users" table/data as User,
 * just with the SoftDeletes trait added.
 */
class SoftDeletedUser extends Model
{
    use Searchable, \Illuminate\Database\Eloquent\SoftDeletes;

    protected $table = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns' => ['name' => 10],
        'algorithm' => 'fuzzy',
    ];
}

/**
 * Same "users" table as User, but $searchable['algorithm'] is the exact-substring 'like'
 * driver. Used by the Filament global-search tests to prove the trait applies the model's
 * own $searchable configuration (a typo must NOT match) instead of the global default.
 */
class LikeUser extends Model
{
    use Searchable;

    protected $table = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns'   => ['name' => 10, 'email' => 5],
        'algorithm' => 'like',
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

/**
 * A genuinely zero-config model: `use Searchable;` and nothing else, on the same "users"
 * table. Every other fixture declares $searchable['columns'] — which is why the
 * getSearchableColumns() gap (no indexing, no shadow columns, no tableSearch() predicate
 * for a model straight out of the README Quick Start) went unnoticed.
 */
class ZeroConfigUser extends Model
{
    use Searchable;

    protected $table = 'users';
    protected $guarded = [];
}

/**
 * $searchable['columns'] in list form. searchIn() accepts it — so search() has always
 * worked — and getSearchableColumns() must read the names off the values, not return [0, 1].
 */
class ListColumnsUser extends Model
{
    use Searchable;

    protected $table = 'users';
    protected $guarded = [];

    protected array $searchable = [
        'columns' => ['name', 'email'],
    ];
}

/** Backed enum cast used by ZeroConfigTicket — see the auto-detection cast tests. */
enum TicketStatus: string
{
    case Open   = 'open';
    case Closed = 'closed';
}

/**
 * Zero-config model whose table carries none of the auto-detector's priority columns, so
 * detection falls through to $fillable — where an enum cast and an array cast sit next to
 * the one real text column. Neither can be indexed as text.
 */
class ZeroConfigTicket extends Model
{
    use Searchable;

    protected $table = 'tickets';
    protected $fillable = ['status', 'payload', 'subject_line'];

    protected $casts = [
        'status'  => TicketStatus::class,
        'payload' => 'array',
    ];
}

/** Same table and casts, but the non-text column is declared: that must still throw, naming the column. */
class DeclaredCastTicket extends ZeroConfigTicket
{
    protected array $searchable = [
        'columns' => ['status' => 1],
    ];
}

/** A value object an accessor can return — not text, and nothing the indexer can stringify. */
class AccessorValue
{
    public function __construct(public string $value)
    {
    }
}

/**
 * Zero-config model whose auto-detected "name" column has an accessor returning an object. The
 * indexer reads an auto-detected column as stored, so that accessor never reaches the index.
 */
class ZeroConfigAccessorUser extends \Illuminate\Database\Eloquent\Model
{
    use Searchable;

    protected $table = 'users';
    protected $guarded = [];

    protected function name(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => new AccessorValue((string) $value)
        );
    }
}
