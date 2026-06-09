<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Receipt\PrismReceiptExtractor;
use App\Services\Receipt\ReceiptExtractor;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ReceiptExtractor::class,
            PrismReceiptExtractor::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::define('manage-integrations', fn (User $user): bool => $user->is_admin === true);
        Gate::define('viewPulse', fn (User $user): bool => $user->is_admin === true);
        Gate::define('viewHorizon', fn (User $user): bool => $user->is_admin === true);
        Gate::define('view-logs', fn (User $user): bool => $user->is_admin === true);
    }
}
