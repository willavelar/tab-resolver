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
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-'.$this->faker->bothify('test-####'),
            'model' => 'claude-sonnet-4-5-20250929',
        ];
    }
}
