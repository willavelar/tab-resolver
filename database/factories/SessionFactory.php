<?php

namespace Database\Factories;

use App\Enums\ExtractionStatus;
use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Session>
 */
class SessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'image_path' => 'receipts/'.fake()->uuid().'.jpg',
            'status' => ExtractionStatus::Pending,
        ];
    }
}
