<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Availability controls: explicit, named limits make brute-force and
     * accidental traffic spikes predictable instead of relying on defaults.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(($request->input('staff_id') ?: 'guest').'|'.$request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)
            ->by($request->ip()));

        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(20)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
