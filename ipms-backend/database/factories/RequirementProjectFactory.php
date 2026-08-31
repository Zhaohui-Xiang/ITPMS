<?php

namespace Database\Factories;

use App\Enums\ProjectDeliveryStatus;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<RequirementProject>
 */
class RequirementProjectFactory extends Factory
{
    protected $model = RequirementProject::class;

    public function configure(): static
    {
        return $this
            ->afterMaking(function (RequirementProject $link): void {
                DB::select(
                    <<<'SQL'
                        SELECT set_config(
                            'itpms.requirement_project_insert_scope',
                            ?,
                            false
                        )
                        SQL,
                    [json_encode([
                        'requirement_id' => (int) $link->requirement_id,
                        'project_id' => (int) $link->project_id,
                    ], JSON_THROW_ON_ERROR)],
                );
            })
            ->afterCreating(function (): void {
                DB::select(
                    <<<'SQL'
                        SELECT set_config(
                            'itpms.requirement_project_insert_scope',
                            '',
                            false
                        )
                        SQL,
                );
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
