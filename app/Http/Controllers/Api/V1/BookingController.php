<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\TimeSlot;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Bookings
 *
 * Reserving and cancelling a seat on a time slot.
 */
class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    /**
     * List my bookings
     *
     * @response 200 {
     *   "data": [
     *     {"id": 1, "user_id": 1, "time_slot_id": 3, "status": "confirmed", "payment_status": "paid", "payment_url": null, "created_at": "2026-09-14T00:00:00.000000Z", "updated_at": "2026-09-14T00:00:00.000000Z"}
     *   ],
     *   "links": {"first": "/api/v1/bookings?page=1", "last": "/api/v1/bookings?page=1", "prev": null, "next": null},
     *   "meta": {"current_page": 1, "last_page": 1, "per_page": 15, "total": 1}
     * }
     */
    public function index(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->with('timeSlot')
            ->latest()
            ->paginate();

        return BookingResource::collection($bookings)->response();
    }

    /**
     * Create a booking
     *
     * Reserves a seat on the given time slot. Protected against overselling
     * by a database row lock, so this is safe to call concurrently for the
     * same time slot. Rate limited to 5 requests/minute per user.
     *
     * @response 201 {
     *   "data": {
     *     "id": 1, "user_id": 1, "time_slot_id": 3,
     *     "status": "confirmed", "payment_status": "paid", "payment_url": null,
     *     "created_at": "2026-09-14T00:00:00.000000Z", "updated_at": "2026-09-14T00:00:00.000000Z"
     *   }
     * }
     * @response 409 {"message": "This time slot is fully booked."}
     * @response 429 scenario="Rate limit exceeded" {"message": "Too Many Attempts."}
     */
    public function store(StoreBookingRequest $request): JsonResponse
    {
        $timeSlot = TimeSlot::findOrFail($request->validated('time_slot_id'));

        $booking = $this->bookings->book(
            $request->user(),
            $timeSlot,
            $request->validated('idempotency_key'),
        );

        return (new BookingResource($booking->load('timeSlot')))->response()->setStatusCode(201);
    }

    /**
     * Cancel a booking
     *
     * Frees the seat back to the time slot. Only the booking's owner or an
     * admin may cancel it; cancelling an already-cancelled booking is a no-op.
     *
     * @response 403 {"message": "This action requires an administrator account."}
     */
    public function destroy(Request $request, Booking $booking): BookingResource
    {
        abort_unless($request->user()->isAdmin() || $request->user()->id === $booking->user_id, 403);

        $this->bookings->cancel($booking);

        return new BookingResource($booking->fresh('timeSlot'));
    }
}
