<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration\Filament;

require_once __DIR__ . '/../../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Integrations\Filament\HasFuzzyGlobalSearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\User;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    use HasFuzzyGlobalSearch;

    protected static ?string $model = User::class;

    protected static ?int $fuzzyTypoTolerance = 2;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->name;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Email' => $record->email];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return '/admin/users/' . $record->getKey(); // static: the test panel registers no pages
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->where('email', 'like', '%@example.com');
    }

    public static function getPages(): array
    {
        return [];
    }
}
