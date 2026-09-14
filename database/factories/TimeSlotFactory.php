<?php

namespace Database\Factories;

use App\Models\Resource;
use App\Models\TimeSlot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeSlot>
 */
class TimeSlotFactory extends Factory
{
    protected $model = TimeSlot::class;

    public function definition(): array
    {
        $startAt = fake()->dateTimeBetween('now', '+1 month');
        $capacity = fake()->numberBetween(1, 20);

        return [
            'resource_id' => Resource::factory(),
            'start_at' => $startAt,
            'end_at' => (clone $startAt)->modify('+1 hour'),
            'capacity' => $capacity,
            'booked_count' => 0,
        ];
    }

    public function full(): static
    {
        return $this->state(fn (array $attributes) => [
            'booked_count' => $attributes['capacity'] ?? 1,
        ]);
    }
}
