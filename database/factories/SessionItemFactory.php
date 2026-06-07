<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\SessionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionItem>
 */
class SessionItemFactory extends Factory
{
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 4);
        $unit = fake()->randomFloat(2, 5, 60);

        return [
            'bill_session_id' => Session::factory(),
            'name' => fake()->words(2, true),
            'quantity' => $quantity,
            'unit_price' => $unit,
            'total_price' => round($quantity * $unit, 2),
            'position' => fake()->numberBetween(1, 20),
        ];
    }
}
