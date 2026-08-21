<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\LateSchedulesTable;
use App\Filament\Widgets\RunHealthOverview;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('Opsifin Scheduler')
            ->brandLogo(fn (): string => asset('images/brand/opsifin-logo.png'))
            ->darkModeBrandLogo(fn (): string => asset('images/brand/opsifin-logo.png'))
            ->brandLogoHeight('2.15rem')
            ->favicon(fn (): string => asset('images/brand/favicon.png'))
            ->sidebarWidth('18rem')
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(Width::Full)
            ->login()
            ->passwordReset()
            ->themeSwitcher(false)
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.components.appearance-init'),
            )
            ->renderHook(
                PanelsRenderHook::TOPBAR_END,
                fn () => view('filament.components.appearance-switcher'),
            )
            ->renderHook(
                PanelsRenderHook::SIMPLE_LAYOUT_START,
                fn () => view('filament.components.appearance-switcher'),
            )
            ->colors([
                'danger' => Color::hex('#F44336'),
                'gray' => Color::Slate,
                'info' => Color::hex('#00BCD4'),
                'primary' => Color::hex('#2196F3'),
                'success' => Color::hex('#4CAF50'),
                'warning' => Color::hex('#FF9800'),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->navigationItems([
                NavigationItem::make('Telescope')
                    ->group('System')
                    ->icon('heroicon-o-chart-bar')
                    ->sort(60)
                    ->url('/telescope', shouldOpenInNewTab: true)
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),
                NavigationItem::make('Horizon')
                    ->group('System')
                    ->icon('heroicon-o-queue-list')
                    ->sort(61)
                    ->url('/horizon', shouldOpenInNewTab: true)
                    ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                RunHealthOverview::class,
                LateSchedulesTable::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
