<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Controllers\Reports\ReportPdfDownloadController;
use App\Http\Controllers\Reports\ReportPreviewController;
use App\Http\Controllers\Reports\ReportRevisionPdfDownloadController;
use App\Http\Controllers\Reports\ReportRevisionPreviewController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            // Non-Livewire report routes (HTML preview, PDF download) live
            // inside the panel so they share its session + auth middleware.
            ->authenticatedRoutes(function (): void {
                Route::get('projects/{project}/reports/{report}/preview', ReportPreviewController::class)
                    ->name('reports.preview');
                Route::get('projects/{project}/reports/{report}/pdf', ReportPdfDownloadController::class)
                    ->name('reports.pdf');
                Route::get('projects/{project}/reports/{report}/revisions/{revision}/preview', ReportRevisionPreviewController::class)
                    ->name('reports.revisions.preview');
                Route::get('projects/{project}/reports/{report}/revisions/{revision}/pdf', ReportRevisionPdfDownloadController::class)
                    ->name('reports.revisions.pdf');
            });
    }
}
