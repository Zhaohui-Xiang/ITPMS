<?php

namespace Database\Factories;

use App\Enums\ProjectDeliveryStatus;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RequirementProject>
 */
class RequirementProjectFactory extends Factory
{
    protected $model = RequirementProject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requirement_id' => Requirement::factory(),
            'project_id' => Project::factory(),
            'project_version_id' => null,
            'delivery_status' => ProjectDeliveryStatus::ASSIGNED->value,
            'version_assigned_by_id' => null,
            'version_assigned_at' => null,
        ];
    }

    public function forVersion(ProjectVersion $version): static
    {
        return $this->state(fn (): array => [
            'project_id' => $version->project_id,
            'project_version_id' => $version->id,
        ]);
    }
}
