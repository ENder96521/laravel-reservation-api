<?php

use App\Models\Resource;
use App\Models\TimeSlot;
use App\Models\User;

test('an authenticated user can list time slots for a resource', function () {
    $resource = Resource::factory()->create();
    TimeSlot::factory()->for($resource)->count(2)->create();
    TimeSlot::factory()->for($resource)->full()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson("/api/v1/resources/{$resource->id}/time-slots");

    $response->assertOk()->assertJsonCount(3, 'data');
});

test('the available filter excludes full time slots', function () {
    $resource = Resource::factory()->create();
    TimeSlot::factory()->for($resource)->count(2)->create();
    TimeSlot::factory()->for($resource)->full()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson("/api/v1/resources/{$resource->id}/time-slots?available=1");

    $response->assertOk()->assertJsonCount(2, 'data');
});

test('a regular user cannot create a time slot', function () {
    $resource = Resource::factory()->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson("/api/v1/resources/{$resource->id}/time-slots", [
        'start_at' => now()->addDay(),
        'end_at' => now()->addDay()->addHour(),
        'capacity' => 5,
    ]);

    $response->assertForbidden();
});

test('an admin can create a time slot for a resource', function () {
    $resource = Resource::factory()->create();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson("/api/v1/resources/{$resource->id}/time-slots", [
        'start_at' => now()->addDay()->toDateTimeString(),
        'end_at' => now()->addDay()->addHour()->toDateTimeString(),
        'capacity' => 5,
    ]);

    $response->assertCreated()->assertJsonPath('data.capacity', 5);
    $this->assertDatabaseHas('time_slots', ['resource_id' => $resource->id, 'capacity' => 5]);
});

test('creating a time slot rejects an end time before the start time', function () {
    $resource = Resource::factory()->create();
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson("/api/v1/resources/{$resource->id}/time-slots", [
        'start_at' => now()->addDay()->toDateTimeString(),
        'end_at' => now()->toDateTimeString(),
        'capacity' => 5,
    ]);

    $response->assertUnprocessable()->assertJsonValidationErrors('end_at');
});
