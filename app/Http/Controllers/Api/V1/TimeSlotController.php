<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTimeSlotRequest;
use App\Http\Requests\UpdateTimeSlotRequest;
use App\Http\Resources\TimeSlotResource;
use App\Models\Resource;
use App\Models\TimeSlot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Time Slots
 *
 * Bookable time slots for a resource. Reading requires any authenticated
 * user; creating, updating, and deleting require an admin.
 */
class TimeSlotController extends Controller
{
    /**
     * List time slots for a resource
     *
     * @queryParam available boolean Only return slots that are not fully booked. Example: true
     *
     * @response 200 {
     *   "data": [
     *     {"id": 3, "resource_id": 1, "start_at": "2026-09-15T09:00:00.000000Z", "end_at": "2026-09-15T10:00:00.000000Z", "capacity": 10, "booked_count": 2, "is_full": false}
     *   ],
     *   "links": {"first": "/api/v1/resources/1/time-slots?page=1", "last": "/api/v1/resources/1/time-slots?page=1", "prev": null, "next": null},
     *   "meta": {"current_page": 1, "last_page": 1, "per_page": 15, "total": 1}
     * }
     */
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $query = $resource->timeSlots()->orderBy('start_at');

        if ($request->boolean('available')) {
            $query->available();
        }

        return TimeSlotResource::collection($query->paginate())->response();
    }

    /**
     * Create a time slot
     *
     * Requires an admin account.
     *
     * @response 201 {
     *   "data": {"id": 3, "resource_id": 1, "start_at": "2026-09-15T09:00:00.000000Z", "end_at": "2026-09-15T10:00:00.000000Z", "capacity": 10, "booked_count": 0, "is_full": false}
     * }
     */
    public function store(StoreTimeSlotRequest $request, Resource $resource): JsonResponse
    {
        $timeSlot = $resource->timeSlots()->create($request->validated());

        return (new TimeSlotResource($timeSlot))->response()->setStatusCode(201);
    }

    /**
     * Show a time slot
     *
     * @response 200 {
     *   "data": {"id": 3, "resource_id": 1, "start_at": "2026-09-15T09:00:00.000000Z", "end_at": "2026-09-15T10:00:00.000000Z", "capacity": 10, "booked_count": 2, "is_full": false}
     * }
     */
    public function show(TimeSlot $timeSlot): TimeSlotResource
    {
        return new TimeSlotResource($timeSlot);
    }

    /**
     * Update a time slot
     *
     * Requires an admin account.
     */
    public function update(UpdateTimeSlotRequest $request, TimeSlot $timeSlot): TimeSlotResource
    {
        $timeSlot->update($request->validated());

        return new TimeSlotResource($timeSlot);
    }

    /**
     * Delete a time slot
     *
     * Requires an admin account.
     */
    public function destroy(TimeSlot $timeSlot): JsonResponse
    {
        $timeSlot->delete();

        return response()->json(null, 204);
    }
}
