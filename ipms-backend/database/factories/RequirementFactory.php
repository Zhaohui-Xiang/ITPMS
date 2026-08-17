<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\RequirementStatus;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requirement>
 */
class RequirementFactory extends Factory
{
    protected $model = Requirement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'priority' => fake()->randomElement(array_column(Priority::cases(), 'value')),
            'requirement_type' => fake()->numberBetween(1, 5),
            'submitter_id' => User::factory()->systemUser(),
            'submitted_at' => now(),
            'expected_completion_date' => fake()->optional()->dateTimeBetween('now', '+6 months'),
            'status' => RequirementStatus::ASSIGNED->value,
            'reviewer_id' => null,
            'review_comment' => null,
            'reviewed_at' => null,
            'dev_lead_id' => null,
            'version' => 1,
            'created_by_id' => User::factory()->internal(),
            'updated_by_id' => null,
        ];
    }

    public function withProjects(int $count): static
    {
        return $this->afterCreating(function (Requirement $requirement) use ($count): void {
            Project::factory()->count($count)->create()->each(
                fn (Project $project) => RequirementProject::factory()
                    ->for($requirement)
                    ->for($project)
                    ->create(),
            );
        });
    }
}
