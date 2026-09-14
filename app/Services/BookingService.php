<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\TimeSlotFullException;
use App\Jobs\SendBookingNotification;
use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BookingService
{
    public function __construct(private readonly PaymentGateway $paymentGateway) {}

    /**
     * Reserve a seat on a time slot for a user.
     *
     * The seat count is protected against overselling by holding a
     * SELECT ... FOR UPDATE row lock on the time slot for the duration of
     * the transaction: every concurrent request for the same slot is
     * serialized by the database, so `booked_count` can never exceed
     * `capacity` even under a burst of simultaneous requests.
     *
     * @throws TimeSlotFullException
     */
    public function book(User $user, TimeSlot $timeSlot, ?string $idempotencyKey = null): Booking
    {
        if ($idempotencyKey && $existing = $this->findByIdempotencyKey($user, $idempotencyKey)) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $timeSlot, $idempotencyKey) {
                $lockedSlot = TimeSlot::whereKey($timeSlot->id)->lockForUpdate()->firstOrFail();

                if ($lockedSlot->isFull()) {
                    throw new TimeSlotFullException;
                }

                $lockedSlot->increment('booked_count');

                $needsPayment = $lockedSlot->resource->price > 0;

                $booking = Booking::create([
                    'user_id' => $user->id,
                    'time_slot_id' => $lockedSlot->id,
                    'status' => $needsPayment ? 'pending' : 'confirmed',
                    'payment_status' => $needsPayment ? 'unpaid' : 'paid',
                    'idempotency_key' => $idempotencyKey,
                ]);

                DB::afterCommit(function () use ($booking, $needsPayment) {
                    if ($needsPayment) {
                        $this->attachPaymentLink($booking);
                    }

                    SendBookingNotification::dispatch($booking, $needsPayment ? 'booking_pending_payment' : 'booking_confirmed');
                });

                return $booking;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Lost the race on the (user_id, idempotency_key) unique index: another
            // request with the same key already created the booking, return it.
            if (! $idempotencyKey) {
                throw $exception;
            }

            return $this->findByIdempotencyKey($user, $idempotencyKey)
                ?? throw $exception;
        }
    }

    public function cancel(Booking $booking): void
    {
        if ($booking->isCancelled()) {
            return;
        }

        DB::transaction(function () use ($booking) {
            $timeSlot = TimeSlot::whereKey($booking->time_slot_id)->lockForUpdate()->firstOrFail();

            $booking->update(['status' => 'cancelled']);
            $timeSlot->decrement('booked_count');

            DB::afterCommit(fn () => SendBookingNotification::dispatch($booking, 'booking_cancelled'));
        });
    }

    /**
     * Mark a booking's payment as confirmed by a payment-webhook event.
     * Idempotent: a booking already marked paid is left untouched, so a
     * duplicate webhook delivery never re-triggers the confirmation notice.
     */
    public function confirmPayment(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->payment_status === 'paid') {
                return;
            }

            // If the booking was cancelled before payment arrived, record that the
            // funds were received without resurrecting the cancelled reservation;
            // reconciling/refunding that case is a manual, out-of-scope operation.
            $locked->update([
                'payment_status' => 'paid',
                'status' => $locked->status === 'cancelled' ? 'cancelled' : 'confirmed',
            ]);

            DB::afterCommit(fn () => SendBookingNotification::dispatch($locked, 'booking_payment_confirmed'));
        });
    }

    /**
     * Mark a booking's payment as failed by a payment-webhook event.
     * Idempotent: a no-op once the booking is already cancelled or confirmed.
     */
    public function failPayment(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, ['cancelled', 'confirmed'], true)) {
                return;
            }

            $locked->update(['status' => 'payment_failed']);

            DB::afterCommit(fn () => SendBookingNotification::dispatch($locked, 'booking_payment_failed'));
        });
    }

    private function findByIdempotencyKey(User $user, string $idempotencyKey): ?Booking
    {
        return Booking::where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Runs outside the seat-locking transaction so a slow/failed call to the
     * payment gateway never holds the time slot row lock open. A failure here
     * is logged rather than thrown: the reservation itself already succeeded.
     */
    private function attachPaymentLink(Booking $booking): void
    {
        try {
            $paymentUrl = $this->paymentGateway->createCheckoutSession($booking);
            $booking->update(['payment_url' => $paymentUrl]);
        } catch (Throwable $exception) {
            Log::error('Failed to create payment checkout session', [
                'booking_id' => $booking->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
