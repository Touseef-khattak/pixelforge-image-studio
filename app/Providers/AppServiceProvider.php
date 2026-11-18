<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use App\Pricing\PricingService;
use App\Models\User;
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

        // Operators only: the spend report shows every user's spend and our margin.
        Gate::define('view-reports', fn (User $user) => $user->is_admin);
    }
}
