<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Booking;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

class StripeCheckoutGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClient $client) {}

    public function createCheckoutSession(Booking $booking): string
    {
        $resource = $booking->timeSlot->resource;

        /** @var Session $session */
        $session = $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    // Stripe test mode only; the resource price is stored in the
                    // smallest unit of a 2-decimal currency (see the resources
                    // migration), so we deliberately avoid zero-decimal currencies
                    // like TWD/JPY where unit_amount means whole units instead.
                    'currency' => config('services.stripe.currency', 'usd'),
                    'product_data' => [
                        'name' => $resource->name,
                    ],
                    'unit_amount' => $resource->price,
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'booking_id' => (string) $booking->id,
            ],
            'success_url' => config('app.url').'/payments/success?booking_id='.$booking->id,
            'cancel_url' => config('app.url').'/payments/cancel?booking_id='.$booking->id,
        ]);

        return $session->url;
    }
}
