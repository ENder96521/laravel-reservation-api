<?php

namespace App\Services;

use App\Exceptions\TimeSlotFullException;
use App\Jobs\SendBookingNotification;
use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class BookingService
{
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

                DB::afterCommit(fn () => SendBookingNotification::dispatch($booking, 'booking_confirmed'));

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

    private function findByIdempotencyKey(User $user, string $idempotencyKey): ?Booking
    {
        return Booking::where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
