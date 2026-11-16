<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for(
            'api-link',
            static fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip())
        );

        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $token, bool $isValid): bool {
                if ($token->name !== 'api-link' || $token->expires_at !== null) {
                    return $isValid;
                }

                $provider = config('auth.guards.sanctum.provider');
                if ($provider === null) {
                    return $token->tokenable !== null;
                }

                $model = config("auth.providers.{$provider}.model");

                return $model !== null && $token->tokenable instanceof $model;
            }
        );
    }
}
