<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'time_slot_id' => TimeSlot::factory(),
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'idempotency_key' => fake()->uuid(),
        ];
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
