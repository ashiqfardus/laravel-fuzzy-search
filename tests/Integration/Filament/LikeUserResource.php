<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration\Filament;

require_once __DIR__ . '/../../TestModels.php';

use Ashiqfardus\LaravelFuzzySearch\Integrations\Filament\HasFuzzyGlobalSearch;
use Ashiqfardus\LaravelFuzzySearch\Tests\LikeUser;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * A resource over LikeUser, whose $searchable['algorithm'] is 'like' (exact substring) and
 * whose $searchable['columns'] include email — while this resource only lists `name`. Used
 * to pin that the trait applies the model's configuration and that the resource's
 * attributes replace the configured column list instead of accumulating with it.
 */
class LikeUserResource extends Resource
{
    use HasFuzzyGlobalSearch;

    protected static ?string $model = LikeUser::class;

    protected static ?int $fuzzyTypoTolerance = 2;

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->name;
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return '/admin/like-users/' . $record->getKey();
    }

    public static function getPages(): array
    {
        return [];
    }
}
