<?php

use App\Models\Booking;
use App\Models\Resource;
use App\Models\TimeSlot;
use Illuminate\Support\Facades\Storage;

test('it writes a csv report grouped by resource for the given date', function () {
    Storage::fake('local');

    $yoga = Resource::factory()->create(['name' => 'Yoga Class', 'price' => 500]);
    $meetingRoom = Resource::factory()->create(['name' => 'Meeting Room', 'price' => 0]);

    $yogaSlot = TimeSlot::factory()->for($yoga)->create();
    $roomSlot = TimeSlot::factory()->for($meetingRoom)->create();

    $today = now();

    Booking::factory()->for($yogaSlot, 'timeSlot')->create(['status' => 'confirmed', 'payment_status' => 'paid', 'created_at' => $today]);
    Booking::factory()->for($yogaSlot, 'timeSlot')->create(['status' => 'cancelled', 'payment_status' => 'unpaid', 'created_at' => $today]);
    Booking::factory()->for($roomSlot, 'timeSlot')->create(['status' => 'confirmed', 'payment_status' => 'paid', 'created_at' => $today]);

    // Outside the reporting window: must not be counted.
    Booking::factory()->for($yogaSlot, 'timeSlot')->create(['created_at' => $today->copy()->subDays(2)]);

    $this->artisan('report:daily', ['date' => $today->toDateString()])->assertSuccessful();

    $path = 'reports/daily-'.$today->toDateString().'.csv';
    Storage::disk('local')->assertExists($path);

    $csv = Storage::disk('local')->get($path);
    $lines = array_map('str_getcsv', explode("\n", trim($csv)));

    expect($lines[0])->toBe(['resource_id', 'resource_name', 'total_bookings', 'confirmed', 'pending', 'cancelled', 'payment_failed', 'revenue_paid']);

    $yogaRow = collect($lines)->first(fn ($row) => $row[1] === 'Yoga Class');
    expect($yogaRow)->not->toBeNull();
    expect((int) $yogaRow[2])->toBe(2); // total_bookings
    expect((int) $yogaRow[3])->toBe(1); // confirmed
    expect((int) $yogaRow[5])->toBe(1); // cancelled
    expect((int) $yogaRow[7])->toBe(500); // revenue_paid

    $roomRow = collect($lines)->first(fn ($row) => $row[1] === 'Meeting Room');
    expect((int) $roomRow[2])->toBe(1);
    expect((int) $roomRow[7])->toBe(0);
});

test('it defaults to reporting on yesterday when no date is given', function () {
    Storage::fake('local');

    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create();
    Booking::factory()->for($timeSlot, 'timeSlot')->create(['created_at' => now()->subDay()]);

    $this->artisan('report:daily')->assertSuccessful();

    Storage::disk('local')->assertExists('reports/daily-'.now()->subDay()->toDateString().'.csv');
});
