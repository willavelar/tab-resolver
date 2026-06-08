<?php

namespace App\Providers;

use App\Services\Receipt\PrismReceiptExtractor;
use App\Services\Receipt\ReceiptExtractor;
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
    }
}
