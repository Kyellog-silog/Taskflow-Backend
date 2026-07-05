<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => null,
            'name' => fake()->words(2, true),
            'key' => strtoupper(fake()->unique()->lexify('???')),
            'description' => fake()->sentence(),
            'lead_user_id' => \App\Models\User::factory(),
            'issue_counter' => 0,
        ];
    }

    public function forTeam(\App\Models\Team $team): static
    {
        return $this->state(fn () => [
            'team_id' => $team->id,
            'lead_user_id' => $team->owner_id,
        ]);
    }
}
