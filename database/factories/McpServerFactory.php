<?php

namespace Database\Factories;

use App\Models\McpServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpServer>
 */
class McpServerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'url' => fake()->url(),
            'token' => null,
        ];
    }
}
