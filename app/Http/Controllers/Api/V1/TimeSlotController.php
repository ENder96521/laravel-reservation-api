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

class TimeSlotController extends Controller
{
    public function index(Request $request, Resource $resource): JsonResponse
    {
        $query = $resource->timeSlots()->orderBy('start_at');

        if ($request->boolean('available')) {
            $query->available();
        }

        return TimeSlotResource::collection($query->paginate())->response();
    }

    public function store(StoreTimeSlotRequest $request, Resource $resource): JsonResponse
    {
        $timeSlot = $resource->timeSlots()->create($request->validated());

        return (new TimeSlotResource($timeSlot))->response()->setStatusCode(201);
    }

    public function show(TimeSlot $timeSlot): TimeSlotResource
    {
        return new TimeSlotResource($timeSlot);
    }

    public function update(UpdateTimeSlotRequest $request, TimeSlot $timeSlot): TimeSlotResource
    {
        $timeSlot->update($request->validated());

        return new TimeSlotResource($timeSlot);
    }

    public function destroy(TimeSlot $timeSlot): JsonResponse
    {
        $timeSlot->delete();

        return response()->json(null, 204);
    }
}
