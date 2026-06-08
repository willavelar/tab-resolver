<?php

namespace Database\Factories;

use App\Models\Integration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    protected $model = Integration::class;

    public function definition(): array
    {
        return [
            'provider' => 'openai',
            'api_key' => 'sk-'.$this->faker->bothify('test-####'),
            'receipt_model' => 'gpt-4o-mini',
            'audio_model' => 'whisper-1',
        ];
    }
}
