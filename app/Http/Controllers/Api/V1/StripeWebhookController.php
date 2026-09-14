<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentWebhookLog;
use App\Services\BookingService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (UnexpectedValueException|SignatureVerificationException $exception) {
            Log::warning('Rejected Stripe webhook: invalid payload or signature.', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Invalid payload or signature.'], 400);
        }

        try {
            DB::transaction(function () use ($event) {
                $this->process($event);

                // Claims this event id for this provider. If a concurrent duplicate
                // delivery already committed the same row, this throws and the
                // whole transaction (including any state change above) rolls back.
                PaymentWebhookLog::create([
                    'provider' => 'stripe',
                    'event_id' => $event->id,
                    'payload' => $event->toArray(),
                    'processed_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json(['received' => true]);
        }

        return response()->json(['received' => true]);
    }

    private function process(Event $event): void
    {
        $object = $event->data->object;
        $bookingId = $object->metadata->booking_id ?? null;
        $booking = $bookingId ? Booking::find($bookingId) : null;

        if (! $booking) {
            Log::warning('Stripe webhook event has no matching booking.', [
                'event_id' => $event->id,
                'type' => $event->type,
            ]);

            return;
        }

        match ($event->type) {
            'checkout.session.completed' => $this->bookings->confirmPayment($booking),
            'checkout.session.async_payment_failed', 'checkout.session.expired' => $this->bookings->failPayment($booking),
            default => null,
        };
    }
}
