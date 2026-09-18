<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('test')
            ->path('admin')
            ->resources([UserResource::class]);
    }
}
