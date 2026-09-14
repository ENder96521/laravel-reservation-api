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

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->with('timeSlot')
            ->latest()
            ->paginate();

        return BookingResource::collection($bookings)->response();
    }

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

    public function destroy(Request $request, Booking $booking): BookingResource
    {
        abort_unless($request->user()->isAdmin() || $request->user()->id === $booking->user_id, 403);

        $this->bookings->cancel($booking);

        return new BookingResource($booking->fresh('timeSlot'));
    }
}
