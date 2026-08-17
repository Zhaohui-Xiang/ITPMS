<?php

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requirement_id' => Requirement::factory(),
            'project_id' => Project::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'assignee_id' => null,
            'priority' => fake()->randomElement(array_column(Priority::cases(), 'value')),
            'status' => TaskStatus::TODO->value,
            'due_date' => fake()->dateTimeBetween('now', '+3 months'),
            'remind_days_before' => 1,
            'estimated_hours' => fake()->optional()->randomFloat(2, 1, 80),
            'actual_hours' => null,
            'suspend_reason' => null,
            'completed_at' => null,
            'created_by_id' => User::factory()->internal(),
            'last_reminded_at' => null,
        ];
    }

    public function forVersionScope(ProjectVersion $version): static
    {
        return $this->state(function () use ($version): array {
            $link = RequirementProject::query()
                ->where('project_version_id', $version->id)
                ->first();

            if ($link === null) {
                $link = RequirementProject::factory()->forVersion($version)->create();
            }

            return [
                'requirement_id' => $link->requirement_id,
                'project_id' => $version->project_id,
            ];
        });
    }
}
