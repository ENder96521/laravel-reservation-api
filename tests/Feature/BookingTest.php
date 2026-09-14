<?php

use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Resource;
use App\Models\TimeSlot;
use App\Models\User;

test('a user can book an available time slot on a free resource', function () {
    $resource = Resource::factory()->create(['price' => 0]);
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 5, 'booked_count' => 0]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/bookings', [
        'time_slot_id' => $timeSlot->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.payment_status', 'paid');

    expect($timeSlot->fresh()->booked_count)->toBe(1);
    expect(NotificationLog::where('event', 'booking_confirmed')->count())->toBe(1);
});

test('booking a resource that requires payment starts as pending and unpaid', function () {
    $resource = Resource::factory()->create(['price' => 500]);
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 5, 'booked_count' => 0]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/bookings', [
        'time_slot_id' => $timeSlot->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.payment_status', 'unpaid');

    expect($response->json('data.payment_url'))->not->toBeNull();
    expect(NotificationLog::where('event', 'booking_pending_payment')->count())->toBe(1);
});

test('a full time slot cannot be booked', function () {
    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 1, 'booked_count' => 1]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/bookings', [
        'time_slot_id' => $timeSlot->id,
    ]);

    $response->assertStatus(409);
    expect(Booking::count())->toBe(0);
});

test('the last seat on a time slot is only granted to one of two competing requests', function () {
    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 1, 'booked_count' => 0]);
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $first = $this->actingAs($userA)->postJson('/api/v1/bookings', ['time_slot_id' => $timeSlot->id]);
    $second = $this->actingAs($userB)->postJson('/api/v1/bookings', ['time_slot_id' => $timeSlot->id]);

    $first->assertCreated();
    $second->assertStatus(409);

    expect($timeSlot->fresh()->booked_count)->toBe(1);
    expect(Booking::count())->toBe(1);
});

test('exactly capacity requests succeed out of a larger burst for the same slot', function () {
    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 3, 'booked_count' => 0]);
    $users = User::factory()->count(7)->create();

    $results = $users->map(
        fn (User $user) => $this->actingAs($user)->postJson('/api/v1/bookings', ['time_slot_id' => $timeSlot->id])->status()
    );

    expect($results->filter(fn ($status) => $status === 201)->count())->toBe(3);
    expect($results->filter(fn ($status) => $status === 409)->count())->toBe(4);
    expect($timeSlot->fresh()->booked_count)->toBe(3);
    expect(Booking::count())->toBe(3);
});

test('resubmitting the same idempotency key returns the original booking instead of creating a new one', function () {
    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 5, 'booked_count' => 0]);
    $user = User::factory()->create();

    $payload = ['time_slot_id' => $timeSlot->id, 'idempotency_key' => 'client-key-123'];

    $first = $this->actingAs($user)->postJson('/api/v1/bookings', $payload);
    $second = $this->actingAs($user)->postJson('/api/v1/bookings', $payload);

    $first->assertCreated();
    $second->assertCreated();
    expect($second->json('data.id'))->toBe($first->json('data.id'));

    expect(Booking::count())->toBe(1);
    expect($timeSlot->fresh()->booked_count)->toBe(1);
});

test('booking submission is rate limited', function () {
    $resource = Resource::factory()->create();
    $timeSlots = TimeSlot::factory()->for($resource)->count(6)->create(['capacity' => 5, 'booked_count' => 0]);
    $user = User::factory()->create();

    foreach ($timeSlots->take(5) as $timeSlot) {
        $this->actingAs($user)->postJson('/api/v1/bookings', ['time_slot_id' => $timeSlot->id]);
    }

    $response = $this->actingAs($user)->postJson('/api/v1/bookings', [
        'time_slot_id' => $timeSlots->last()->id,
    ]);

    $response->assertStatus(429);
});

test('a user can cancel their own booking and free the seat', function () {
    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 5, 'booked_count' => 1]);
    $user = User::factory()->create();
    $booking = Booking::factory()->for($user)->for($timeSlot, 'timeSlot')->create();

    $response = $this->actingAs($user)->deleteJson("/api/v1/bookings/{$booking->id}");

    $response->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect($timeSlot->fresh()->booked_count)->toBe(0);
    expect(NotificationLog::where('event', 'booking_cancelled')->count())->toBe(1);
});

test('cancelling an already cancelled booking does not double release the seat', function () {
    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 5, 'booked_count' => 1]);
    $user = User::factory()->create();
    $booking = Booking::factory()->for($user)->for($timeSlot, 'timeSlot')->cancelled()->create();

    $this->actingAs($user)->deleteJson("/api/v1/bookings/{$booking->id}")->assertOk();

    expect($timeSlot->fresh()->booked_count)->toBe(1);
});

test('a user cannot cancel another user\'s booking', function () {
    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 5, 'booked_count' => 1]);
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $booking = Booking::factory()->for($owner)->for($timeSlot, 'timeSlot')->create();

    $this->actingAs($intruder)->deleteJson("/api/v1/bookings/{$booking->id}")->assertForbidden();
});
