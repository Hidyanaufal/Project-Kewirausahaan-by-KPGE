<?php

namespace App\Providers;

use App\Http\Responses\LoginRedirectResponse;
use App\Jobs\ExportCompletion;
use App\Models\Export;
use App\Models\Import;
use App\Models\Notification;
use App\Models\Permission;
use App\Models\Role;
use App\Observers\ExportObserver;
use App\Services\DateRangeService;
use Filament\Actions\Exports\Jobs\ExportCompletion as FilamentExportCompletion;
use Filament\Actions\Exports\Models\Export as BaseExport;
use Filament\Actions\Imports\Models\Import as BaseImport;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Notifications\Livewire\Notifications;
use Filament\Support\Assets\Js;
use Filament\Support\Enums\Alignment;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DateRangeService::class);
        $this->app->singleton(LoginResponse::class, LoginRedirectResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        URL::forceHttps(env('APP_HTTPS'));

        app(\Spatie\Permission\PermissionRegistrar::class)
            ->setPermissionClass(Permission::class)
            ->setRoleClass(Role::class);

        // Bind custom Import and Export models
        $this->app->bind(BaseImport::class, Import::class);
        $this->app->bind(BaseExport::class, Export::class);

        // Bind custom ExportCompletion job (injects Export company_id into notification).
        // Closure ensures our job is always instantiated when the chain is built.
        $this->app->bind(FilamentExportCompletion::class, function ($app, array $parameters) {
            return new ExportCompletion(
                $parameters['export'],
                $parameters['columnMap'],
                $parameters['formats'] ?? [],
                $parameters['options'] ?? []
            );
        });

        Export::observe(ExportObserver::class);

        // Bind custom Notification model
        $this->app->bind(DatabaseNotification::class, Notification::class);

        Notifications::alignment(Alignment::Center);

        FilamentAsset::register([
            Js::make('top-navigation', __DIR__ . '/../../resources/js/top-navigation.js'),
            Js::make('history-fix', __DIR__ . '/../../resources/js/history-fix.js'),
            Js::make('custom-print', __DIR__ . '/../../resources/js/custom-print.js'),
        ]);
    }
}
