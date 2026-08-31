<?php

namespace Tests;

use App\Models\RequirementProject;
use App\Services\VersionGateLock;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * Perform privileged fixture setup through the same lock order as production services.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function updateRequirementProjectForTest(
        RequirementProject $link,
        array $attributes,
    ): RequirementProject {
        return DB::transaction(function () use ($link, $attributes): RequirementProject {
            $initial = RequirementProject::query()->findOrFail($link->getKey());
            $requirementId = (int) ($attributes['requirement_id'] ?? $initial->requirement_id);
            $projectId = (int) ($attributes['project_id'] ?? $initial->project_id);
            $targetVersionId = array_key_exists('project_version_id', $attributes)
                ? $attributes['project_version_id']
                : $initial->project_version_id;
            $lock = app(VersionGateLock::class);

            $lock->acquireScopes([
                [
                    'requirement_id' => (int) $initial->requirement_id,
                    'project_id' => (int) $initial->project_id,
                ],
                [
                    'requirement_id' => $requirementId,
                    'project_id' => $projectId,
                ],
            ]);
            $lock->acquireVersions([
                $initial->project_version_id,
                $targetVersionId,
            ]);

            $locked = RequirementProject::query()
                ->whereKey($initial->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lock->runAuthorizedRequirementProjectMutations(
                [$locked->id],
                fn (): bool => $locked->update($attributes),
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  iterable<int>  $ids
     */
    protected function deleteRequirementProjectsForTest(iterable $ids): int
    {
        return DB::transaction(function () use ($ids): int {
            $ids = collect($ids)
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->sort()
                ->values();
            $rows = RequirementProject::query()
                ->whereKey($ids->all())
                ->orderBy('id')
                ->get();
            $lock = app(VersionGateLock::class);

            $lock->acquireScopes($rows->map(static fn (RequirementProject $row): array => [
                'requirement_id' => (int) $row->requirement_id,
                'project_id' => (int) $row->project_id,
            ]));
            $lock->acquireVersions($rows->pluck('project_version_id'));

            $lockedIds = RequirementProject::query()
                ->whereKey($ids->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            return $lock->runAuthorizedRequirementProjectMutations(
                $lockedIds,
                fn (): int => RequirementProject::query()->whereKey($lockedIds)->delete(),
            );
        });
    }
}
