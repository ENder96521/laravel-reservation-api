<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\ResourceResource;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;

/**
 * @group Resources
 *
 * Bookable resources (e.g. a class or a room). Reading requires any
 * authenticated user; creating, updating, and deleting require an admin.
 */
class ResourceController extends Controller
{
    /**
     * List resources
     *
     * @response 200 {
     *   "data": [
     *     {"id": 1, "name": "Yoga Class", "description": "Morning yoga", "capacity_per_slot": 10, "price": 500, "time_slots": [], "created_at": "2026-09-14T00:00:00.000000Z", "updated_at": "2026-09-14T00:00:00.000000Z"}
     *   ],
     *   "links": {"first": "/api/v1/resources?page=1", "last": "/api/v1/resources?page=1", "prev": null, "next": null},
     *   "meta": {"current_page": 1, "last_page": 1, "per_page": 15, "total": 1}
     * }
     */
    public function index(): JsonResponse
    {
        $resources = Resource::query()->paginate();

        return ResourceResource::collection($resources)->response();
    }

    /**
     * Show a resource
     *
     * Includes its time slots.
     *
     * @response 200 {
     *   "data": {"id": 1, "name": "Yoga Class", "description": "Morning yoga", "capacity_per_slot": 10, "price": 500, "time_slots": [], "created_at": "2026-09-14T00:00:00.000000Z", "updated_at": "2026-09-14T00:00:00.000000Z"}
     * }
     * @response 404 {"message": "No query results for model [App\\Models\\Resource] 999"}
     */
    public function show(Resource $resource): ResourceResource
    {
        return new ResourceResource($resource->load('timeSlots'));
    }

    /**
     * Create a resource
     *
     * Requires an admin account.
     *
     * @response 201 {
     *   "data": {"id": 1, "name": "Yoga Class", "description": "Morning yoga", "capacity_per_slot": 10, "price": 500, "time_slots": [], "created_at": "2026-09-14T00:00:00.000000Z", "updated_at": "2026-09-14T00:00:00.000000Z"}
     * }
     * @response 403 {"message": "This action requires an administrator account."}
     */
    public function store(StoreResourceRequest $request): JsonResponse
    {
        $resource = Resource::create($request->validated());

        return (new ResourceResource($resource))->response()->setStatusCode(201);
    }

    /**
     * Update a resource
     *
     * Requires an admin account.
     */
    public function update(UpdateResourceRequest $request, Resource $resource): ResourceResource
    {
        $resource->update($request->validated());

        return new ResourceResource($resource);
    }

    /**
     * Delete a resource
     *
     * Requires an admin account.
     */
    public function destroy(Resource $resource): JsonResponse
    {
        $resource->delete();

        return response()->json(null, 204);
    }
}
