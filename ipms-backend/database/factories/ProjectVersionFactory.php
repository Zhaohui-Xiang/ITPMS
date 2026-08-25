<?php

namespace Database\Factories;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\TaskStatus;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<ProjectVersion>
 */
class ProjectVersionFactory extends Factory
{
    protected $model = ProjectVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'code' => fake()->unique()->bothify('v#.#-###'),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->paragraph(),
            'status' => ProjectVersionStatus::DRAFT->value,
            'owner_id' => User::factory()->internal(),
            'planned_start_date' => fake()->optional()->dateTimeBetween('now', '+1 month'),
            'planned_release_date' => fake()->optional()->dateTimeBetween('+1 month', '+6 months'),
            'released_at' => null,
            'released_by_id' => null,
            'release_notes' => null,
            'lock_version' => 1,
            'created_by_id' => User::factory()->internal(),
        ];
    }

    public function inTesting(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectVersionStatus::IN_TESTING->value,
        ]);
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => ProjectVersionStatus::READY_TO_RELEASE->value,
            'release_notes' => fake()->sentence(),
        ]);
    }

    public function withPassingScope(): static
    {
        return $this->afterCreating(function (ProjectVersion $version): void {
            $originalStatus = $version->status;
            $scopeLocked = in_array($originalStatus, [
                ProjectVersionStatus::READY_TO_RELEASE,
                ProjectVersionStatus::RELEASED,
                ProjectVersionStatus::ARCHIVED,
            ], true);

            if ($scopeLocked) {
                DB::table('project_versions')->where('id', $version->id)->update([
                    'status' => ProjectVersionStatus::IN_TESTING->value,
                ]);
            }

            try {
                RequirementProject::factory()->forVersion($version)->create([
                    'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY->value,
                ]);

                Task::factory()->forVersionScope($version)->create([
                    'status' => TaskStatus::COMPLETED->value,
                    'completed_at' => now(),
                ]);

                Defect::factory()->forVersionScope($version)->create([
                    'severity' => DefectSeverity::SERIOUS->value,
                    'status' => DefectStatus::CLOSED->value,
                    'closed_at' => now(),
                ]);
            } finally {
                if ($scopeLocked) {
                    DB::table('project_versions')->where('id', $version->id)->update([
                        'status' => $originalStatus->value,
                    ]);
                }
            }
        });
    }
}
