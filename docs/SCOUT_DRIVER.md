# Scout Driver

> Available from v2.0.0-alpha.2

The Scout engine adapter is bundled in this package and registers automatically when `laravel/scout` is installed. No separate driver package is required.

## Setup

```bash
composer require laravel/scout
php artisan vendor:publish --provider="Laravel\Scout\ScoutServiceProvider"
```

In `.env`:
```
SCOUT_DRIVER=fuzzy-search
```

Build the index:
```bash
php artisan fuzzy-search:rebuild "App\Models\User"
```

## Usage

Add Scout's `Searchable` trait to your model:

```php
use Laravel\Scout\Searchable;
use Ashiqfardus\LaravelFuzzySearch\Traits\Searchable as FuzzySearchable;

class User extends Model
{
    use Searchable, FuzzySearchable;

    public function toSearchableArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }
}

// Scout search
$users = User::search('john')->get();
```

## Authorization

Scout's default behavior bypasses Eloquent global scopes. Apply them explicitly:

```php
User::search('john')
    ->query(fn($q) => $q->withoutTrashed()->where('tenant_id', auth()->user()->tenant_id))
    ->get();
```

## Relevance scores

Results include `_score` (BM25 relevance, higher = more relevant):

```php
foreach (User::search('laravel')->get() as $user) {
    echo $user->name . ': ' . $user->_score;
}
```

## How it works

The Scout engine wraps the same `IndexManager` + `Bm25Scorer` used by `Model::search()->useInvertedIndex()`. There is no separate index — it reads from the same `fuzzy_index_*` tables.
