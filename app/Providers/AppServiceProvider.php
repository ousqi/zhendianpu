<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
        Passport::authorizationView('auth.oauth.authorize');
        Passport::tokensExpireIn(now()->addHours(8));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($request->path().'|'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('oauth-register', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->ip().'|'.mb_strtolower((string) $request->input('username'))));
    }
}
