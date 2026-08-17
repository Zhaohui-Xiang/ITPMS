<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->bothify('Project ####-????'),
            'system_type' => fake()->numberBetween(1, 2),
            'description' => fake()->optional()->sentence(),
            'status' => fake()->randomElement(array_map(
                static fn (ProjectStatus $status): int => $status->value,
                ProjectStatus::cases(),
            )),
            'manager_id' => User::factory()->internal(),
            'supplier_org_id' => null,
            'created_by_id' => User::factory()->internal(),
        ];
    }

    public function withManager(?User $manager = null): static
    {
        if ($manager !== null) {
            return $this->state(fn (): array => [
                'manager_id' => $manager->getKey(),
                'created_by_id' => $manager->getKey(),
            ]);
        }

        return $this->state(function (): array {
            $manager = User::factory()->internal()->create();

            return [
                'manager_id' => $manager->getKey(),
                'created_by_id' => $manager->getKey(),
            ];
        });
    }
}
