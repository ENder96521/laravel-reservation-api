<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceRequest;
use App\Http\Requests\UpdateResourceRequest;
use App\Http\Resources\ResourceResource;
use App\Models\Resource;
use Illuminate\Http\JsonResponse;

class ResourceController extends Controller
{
    public function index(): JsonResponse
    {
        $resources = Resource::query()->paginate();

        return ResourceResource::collection($resources)->response();
    }

    public function show(Resource $resource): ResourceResource
    {
        return new ResourceResource($resource->load('timeSlots'));
    }

    public function store(StoreResourceRequest $request): JsonResponse
    {
        $resource = Resource::create($request->validated());

        return (new ResourceResource($resource))->response()->setStatusCode(201);
    }

    public function update(UpdateResourceRequest $request, Resource $resource): ResourceResource
    {
        $resource->update($request->validated());

        return new ResourceResource($resource);
    }

    public function destroy(Resource $resource): JsonResponse
    {
        $resource->delete();

        return response()->json(null, 204);
    }
}
