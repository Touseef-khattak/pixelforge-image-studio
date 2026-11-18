<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use App\Pricing\PricingService;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            config('pixelforge.base_url'),
            (string) config('pixelforge.api_key'),
        ));

        $this->app->singleton(PricingService::class, fn () => new PricingService(
            config('pixelforge.markup_percent'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
