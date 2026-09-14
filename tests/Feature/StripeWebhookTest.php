<?php

use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\PaymentWebhookLog;
use App\Models\Resource;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Testing\TestResponse;

function stripeSignatureHeader(string $payload, ?string $secret = null, ?int $timestamp = null): string
{
    $secret ??= config('services.stripe.webhook_secret');
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return "t={$timestamp},v1={$signature}";
}

function stripeCheckoutCompletedPayload(string $eventId, int $bookingId): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => [
            'object' => [
                'id' => 'cs_test_'.$bookingId,
                'object' => 'checkout.session',
                'metadata' => ['booking_id' => (string) $bookingId],
            ],
        ],
    ];
}

function postStripeWebhook(string $payload, ?string $signature): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $signature;
    }

    return test()->call('POST', '/api/v1/webhooks/stripe', [], [], [], $server, $payload);
}

function pendingPaidBooking(): Booking
{
    $resource = Resource::factory()->create(['price' => 1500]);
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 5, 'booked_count' => 1]);
    $user = User::factory()->create();

    return Booking::factory()->for($user)->for($timeSlot, 'timeSlot')->create([
        'status' => 'pending',
        'payment_status' => 'unpaid',
    ]);
}

test('a validly signed checkout.session.completed webhook confirms the booking', function () {
    $booking = pendingPaidBooking();
    $payload = json_encode(stripeCheckoutCompletedPayload('evt_1', $booking->id));

    $response = postStripeWebhook($payload, stripeSignatureHeader($payload));

    $response->assertOk()->assertJson(['received' => true]);

    $booking->refresh();
    expect($booking->status)->toBe('confirmed');
    expect($booking->payment_status)->toBe('paid');
    expect(NotificationLog::where('event', 'booking_payment_confirmed')->count())->toBe(1);
    expect(PaymentWebhookLog::where('event_id', 'evt_1')->count())->toBe(1);
});

test('a forged signature is rejected', function () {
    $booking = pendingPaidBooking();
    $payload = json_encode(stripeCheckoutCompletedPayload('evt_2', $booking->id));

    $response = postStripeWebhook($payload, stripeSignatureHeader($payload, 'wrong_secret'));

    $response->assertStatus(400);

    $booking->refresh();
    expect($booking->status)->toBe('pending');
    expect($booking->payment_status)->toBe('unpaid');
});

test('a missing signature header is rejected', function () {
    $booking = pendingPaidBooking();
    $payload = json_encode(stripeCheckoutCompletedPayload('evt_3', $booking->id));

    $response = postStripeWebhook($payload, null);

    $response->assertStatus(400);
});

test('the same webhook event delivered twice is only processed once', function () {
    $booking = pendingPaidBooking();
    $payload = json_encode(stripeCheckoutCompletedPayload('evt_4', $booking->id));
    $signature = stripeSignatureHeader($payload);

    $first = postStripeWebhook($payload, $signature);
    $second = postStripeWebhook($payload, $signature);

    $first->assertOk();
    $second->assertOk();

    expect(PaymentWebhookLog::where('event_id', 'evt_4')->count())->toBe(1);
    expect(NotificationLog::where('event', 'booking_payment_confirmed')->count())->toBe(1);
});

test('an async payment failure marks the booking as payment_failed', function () {
    $booking = pendingPaidBooking();
    $payload = json_encode([
        'id' => 'evt_5',
        'object' => 'event',
        'type' => 'checkout.session.async_payment_failed',
        'data' => [
            'object' => [
                'id' => 'cs_test_'.$booking->id,
                'object' => 'checkout.session',
                'metadata' => ['booking_id' => (string) $booking->id],
            ],
        ],
    ]);

    $response = postStripeWebhook($payload, stripeSignatureHeader($payload));

    $response->assertOk();

    $booking->refresh();
    expect($booking->status)->toBe('payment_failed');
    expect($booking->payment_status)->toBe('unpaid');
});
