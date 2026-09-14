<?php

use App\Models\Resource;
use App\Models\User;

test('guests cannot list resources', function () {
    $this->getJson('/api/v1/resources')->assertUnauthorized();
});

test('an authenticated user can list resources', function () {
    Resource::factory()->count(3)->create();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/resources');

    $response->assertOk()->assertJsonCount(3, 'data');
});

test('a regular user cannot create a resource', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/resources', [
        'name' => 'Yoga Class',
        'capacity_per_slot' => 10,
    ]);

    $response->assertForbidden();
});

test('an admin can create, update, and delete a resource', function () {
    $admin = User::factory()->admin()->create();

    $create = $this->actingAs($admin)->postJson('/api/v1/resources', [
        'name' => 'Yoga Class',
        'description' => 'Morning yoga',
        'capacity_per_slot' => 10,
        'price' => 500,
    ]);
    $create->assertCreated()->assertJsonPath('data.name', 'Yoga Class');

    $resourceId = $create->json('data.id');

    $update = $this->actingAs($admin)->putJson("/api/v1/resources/{$resourceId}", [
        'name' => 'Yoga Class (Updated)',
    ]);
    $update->assertOk()->assertJsonPath('data.name', 'Yoga Class (Updated)');

    $delete = $this->actingAs($admin)->deleteJson("/api/v1/resources/{$resourceId}");
    $delete->assertNoContent();

    $this->assertDatabaseMissing('resources', ['id' => $resourceId]);
});

test('creating a resource validates required fields', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->postJson('/api/v1/resources', []);

    $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'capacity_per_slot']);
});
