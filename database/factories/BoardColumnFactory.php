<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BoardColumn>
 */
class BoardColumnFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['To Do', 'In Progress', 'Done']),
            'position' => fake()->numberBetween(1, 10),
            'board_id' => \App\Models\Board::factory(),
        ];
    }
}
