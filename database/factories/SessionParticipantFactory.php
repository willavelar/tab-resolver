<?php

namespace Database\Factories;

use App\Models\Session;
use App\Models\SessionParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SessionParticipant>
 */
class SessionParticipantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bill_session_id' => Session::factory(),
            'name' => fake()->firstName(),
            'text' => fake()->sentence(4),
            'audio_path' => null,
            'audio_duration' => null,
        ];
    }
}
