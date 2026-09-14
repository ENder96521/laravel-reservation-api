<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Services\Payments\NullPaymentGateway;
use App\Services\Payments\StripeCheckoutGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function () {
            $secret = config('services.stripe.secret');

            return $secret
                ? new StripeCheckoutGateway(new StripeClient($secret))
                : new NullPaymentGateway;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

        // Booking submission is a sensitive, abuse-prone action: 5 requests/min per authenticated user.
        RateLimiter::for('booking', fn (Request $request) => Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
    }
}
