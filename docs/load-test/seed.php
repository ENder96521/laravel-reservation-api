<?php

// Seeds a single load-test scenario: one Resource, one TimeSlot with the given
// capacity, and N users each with a Sanctum token — written to scenario.json
// for booking-race.js to consume. See ../load-test-report.md for context.
//
// Usage: php seed.php <capacity> <user_count>
// Run against a real MySQL database, not sqlite (see the report for why).

use App\Models\Resource;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$capacity = (int) ($argv[1] ?? 20);
$userCount = (int) ($argv[2] ?? 300);

TimeSlot::query()->delete();
Resource::query()->delete();
User::query()->delete();

$resource = Resource::create([
    'name' => 'Load Test Resource',
    'description' => 'seeded for k6 load test',
    'capacity_per_slot' => $capacity,
    'price' => 0,
]);

$timeSlot = TimeSlot::create([
    'resource_id' => $resource->id,
    'start_at' => now()->addDay(),
    'end_at' => now()->addDay()->addHour(),
    'capacity' => $capacity,
    'booked_count' => 0,
]);

$tokens = [];
for ($i = 0; $i < $userCount; $i++) {
    $user = User::create([
        'name' => "Load Test User {$i}",
        'email' => "loadtest{$i}@example.test",
        'password' => 'password',
        'role' => 'user',
    ]);
    $tokens[] = $user->createToken('loadtest')->plainTextToken;
}

file_put_contents(
    __DIR__.'/scenario.json',
    json_encode([
        'time_slot_id' => $timeSlot->id,
        'capacity' => $capacity,
        'tokens' => $tokens,
    ])
);

fwrite(STDOUT, "Seeded: resource_id={$resource->id} time_slot_id={$timeSlot->id} capacity={$capacity} users=".count($tokens)."\n");
