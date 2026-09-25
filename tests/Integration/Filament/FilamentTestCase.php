<?php

namespace Ashiqfardus\LaravelFuzzySearch\Tests\Integration\Filament;

use Ashiqfardus\LaravelFuzzySearch\Tests\TestCase;

/**
 * Boots a minimal Filament panel on top of the package's TestCase. Every test in this
 * directory skips when filament/filament is not installed (it is a dev dependency only).
 */
abstract class FilamentTestCase extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Filament\Resources\Resource::class)) {
            $this->markTestSkipped('filament/filament not installed.');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        // The skip above comes before parent::setUp(): there is no application to tear down.
        if ($this->app !== null) {
            parent::tearDown();
        }
    }

    protected function getPackageProviders($app): array
    {
        if (!class_exists(\Filament\Resources\Resource::class)) {
            return parent::getPackageProviders($app);
        }

        return array_merge(parent::getPackageProviders($app), [
            \Livewire\LivewireServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            TestPanelProvider::class,
        ]);
    }
}
