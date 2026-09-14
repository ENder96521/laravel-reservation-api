<?php

use App\Models\Resource;
use App\Models\TimeSlot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The other concurrency tests (see BookingTest) simulate a race by issuing
 * two sequential requests and checking the end state — sqlite has no real
 * row-level locking to race against. This test proves the lock itself is
 * real: it opens a transaction on one MySQL connection, holds the row lock
 * open, and shows a second, independent connection genuinely blocks (and
 * times out) trying to acquire the same lock — the mechanism BookingService
 * relies on to serialize concurrent bookings. Run it against the Docker
 * Compose MySQL service: `DB_CONNECTION=mysql php artisan test --filter=BookingRowLockTest`.
 */
test('a locked time slot row blocks a second connection until the holder releases it', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Row-level lock-wait behavior requires MySQL; run this against the Docker Compose MySQL service.');
    }

    $resource = Resource::factory()->create();
    $timeSlot = TimeSlot::factory()->for($resource)->create(['capacity' => 1, 'booked_count' => 0]);

    config(['database.connections.mysql_second' => config('database.connections.mysql')]);

    DB::connection('mysql')->beginTransaction();
    DB::connection('mysql')->table('time_slots')->where('id', $timeSlot->id)->lockForUpdate()->first();

    DB::connection('mysql_second')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $blocked = false;

    try {
        DB::connection('mysql_second')->table('time_slots')->where('id', $timeSlot->id)->lockForUpdate()->first();
    } catch (QueryException) {
        $blocked = true;
    } finally {
        DB::connection('mysql')->rollBack();
        DB::purge('mysql_second');
    }

    expect($blocked)->toBeTrue();
});
