<?php

namespace Database\Factories;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Defect>
 */
class DefectFactory extends Factory
{
    protected $model = Defect::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Defect $defect): void {
            RequirementProject::query()->firstOrCreate([
                'requirement_id' => $defect->requirement_id,
                'project_id' => $defect->project_id,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requirement_id' => Requirement::factory(),
            'project_id' => Project::factory(),
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'severity' => fake()->randomElement(array_column(DefectSeverity::cases(), 'value')),
            'defect_type' => fake()->numberBetween(1, 5),
            'reporter_id' => User::factory()->internal(),
            'discovered_at' => now(),
            'discovery_phase' => fake()->numberBetween(1, 2),
            'assignee_id' => null,
            'status' => DefectStatus::PENDING_CONFIRM->value,
            'screenshot' => null,
            'fix_description' => null,
            'closed_at' => null,
            'created_by_id' => User::factory()->internal(),
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
