<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Booking;

/**
 * Used whenever no Stripe secret key is configured (local dev without test
 * keys, or the automated test suite): returns a deterministic placeholder
 * URL instead of calling out to Stripe, so booking a paid resource still
 * works end-to-end without real credentials.
 */
class NullPaymentGateway implements PaymentGateway
{
    public function createCheckoutSession(Booking $booking): string
    {
        return config('app.url')."/payments/mock-checkout/{$booking->id}";
    }
}
