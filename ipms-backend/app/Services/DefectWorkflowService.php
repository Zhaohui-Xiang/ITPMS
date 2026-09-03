<?php

namespace App\Services;

use App\Enums\DefectStatus;
use App\Enums\UserType;
use App\Exceptions\DomainConflictException;
use App\Http\Middleware\AuditLogger;
use App\Models\Defect;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DefectWorkflowService
{
    private const WRITABLE_FIELDS = [
        'requirement_id',
        'project_id',
        'title',
        'description',
        'severity',
        'defect_type',
        'discovery_phase',
        'screenshot',
    ];

    public function create(array $data, User $actor): Defect
    {
        return DB::transaction(function () use ($data, $actor): Defect {
            $requirementId = (int) $data['requirement_id'];
            $projectId = (int) $data['project_id'];
            $this->assertRequirementProjectRelation($requirementId, $projectId);
            $this->assertReporterOwnsRequirement($requirementId, $actor);

            $defect = Defect::query()->create([
                ...Arr::only($data, self::WRITABLE_FIELDS),
                'reporter_id' => $actor->id,
                'discovered_at' => now(),
                'assignee_id' => null,
                'status' => DefectStatus::PENDING_CONFIRM->value,
                'fix_description' => null,
                'closed_at' => null,
                'created_by_id' => $actor->id,
            ]);

            $this->audit($actor, $defect, 1);

            return $this->fresh($defect);
        });
    }

    public function confirm(Defect $defect, User $actor): Defect
    {
        return DB::transaction(function () use ($defect, $actor): Defect {
            $locked = $this->lock($defect);
            $current = DefectStatus::from($locked->status);
            $this->assertAction($current->canConfirm(), $current, 'confirm');

            $locked->update(['status' => DefectStatus::CONFIRMED->value]);
            $this->audit($actor, $locked, 5, [
                'from_status' => $current->value,
                'to_status' => DefectStatus::CONFIRMED->value,
            ]);

            return $this->fresh($locked);
        });
    }

    public function assign(
        Defect $defect,
        User $assignee,
        User $actor,
    ): Defect {
        return DB::transaction(function () use ($defect, $assignee, $actor): Defect {
            $locked = $this->lock($defect);
            $current = DefectStatus::from($locked->status);
            $this->assertAction($current->canAssign(), $current, 'assign');
            $this->assertAssigneeEligible($locked->project_id, $assignee);

            $locked->update([
                'assignee_id' => $assignee->id,
                'status' => DefectStatus::FIXING->value,
                'closed_at' => null,
            ]);
            $this->audit($actor, $locked, 6, [
                'from_status' => $current->value,
                'to_status' => DefectStatus::FIXING->value,
                'assignee_id' => $assignee->id,
            ]);

            return $this->fresh($locked);
        });
    }

    public function resolve(
        Defect $defect,
        User $actor,
        string $fixDescription,
    ): Defect {
        if (blank($fixDescription)) {
            throw ValidationException::withMessages([
                'fix_description' => 'A fix description is required.',
            ]);
        }

        return DB::transaction(function () use ($defect, $actor, $fixDescription): Defect {
            $locked = $this->lock($defect);
            $current = DefectStatus::from($locked->status);
            $this->assertAction($current->canResolve(), $current, 'resolve');

            if ($locked->assignee_id !== $actor->id) {
                throw new DomainConflictException(
                    'DEFECT_ASSIGNEE_MISMATCH',
                    errors: [
                        'assignee_id' => [
                            'current' => $locked->assignee_id,
                            'actor' => $actor->id,
                        ],
                    ],
                    message: 'Only the currently assigned developer may resolve this defect.',
                );
            }

            $locked->update([
                'status' => DefectStatus::PENDING_RETEST->value,
                'fix_description' => trim($fixDescription),
                'closed_at' => null,
            ]);
            $this->audit($actor, $locked, 4, [
                'from_status' => $current->value,
                'to_status' => DefectStatus::PENDING_RETEST->value,
            ]);

            return $this->fresh($locked);
        });
    }

    public function verify(
        Defect $defect,
        User $actor,
        string $result,
        ?string $comment = null,
    ): Defect {
        if (! in_array($result, ['pass', 'fail'], true)) {
            throw ValidationException::withMessages([
                'result' => 'The verification result must be pass or fail.',
            ]);
        }

        return DB::transaction(function () use ($defect, $actor, $result, $comment): Defect {
            $locked = $this->lock($defect);
            $current = DefectStatus::from($locked->status);
            $this->assertAction($current->canVerify(), $current, 'verify');
            $target = $result === 'pass'
                ? DefectStatus::CLOSED
                : DefectStatus::REOPENED;

            $locked->update([
                'status' => $target->value,
                'closed_at' => $target === DefectStatus::CLOSED ? now() : null,
            ]);
            $this->audit($actor, $locked, 4, [
                'from_status' => $current->value,
                'to_status' => $target->value,
                'result' => $result,
                'comment' => $comment,
            ]);

            return $this->fresh($locked);
        });
    }

    public function reopen(
        Defect $defect,
        User $actor,
        string $reason,
    ): Defect {
        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A reopen reason is required.',
            ]);
        }

        return DB::transaction(function () use ($defect, $actor, $reason): Defect {
            $locked = $this->lock($defect);
            $current = DefectStatus::from($locked->status);
            $this->assertAction($current->canReopen(), $current, 'reopen');

            $locked->update([
                'status' => DefectStatus::REOPENED->value,
                'closed_at' => null,
            ]);
            $this->audit($actor, $locked, 4, [
                'from_status' => $current->value,
                'to_status' => DefectStatus::REOPENED->value,
                'reason' => trim($reason),
            ]);

            return $this->fresh($locked);
        });
    }

    private function assertRequirementProjectRelation(
        int $requirementId,
        int $projectId,
    ): void {
        $exists = RequirementProject::query()
            ->where('requirement_id', $requirementId)
            ->where('project_id', $projectId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'project_id' => 'The project is not linked to this requirement.',
            ]);
        }
    }

    private function assertReporterOwnsRequirement(
        int $requirementId,
        User $actor,
    ): void {
        if ($actor->user_type !== UserType::SYSTEM_USER->value) {
            return;
        }

        $ownsRequirement = Requirement::query()
            ->whereKey($requirementId)
            ->where('submitter_id', $actor->id)
            ->exists();

        if (! $ownsRequirement) {
            throw new AuthorizationException('Requesters may only report defects for their own requirements.');
        }
    }

    private function assertAssigneeEligible(int $projectId, User $assignee): void
    {
        $project = Project::query()->findOrFail($projectId);
        $isSupplierDeveloper = $assignee->roles()
            ->where('code', 'supplier_dev')
            ->exists();
        $isProjectMember = $project->members()
            ->where('user_id', $assignee->id)
            ->exists();
        $isSupplierMember = $project->supplier_org_id !== null
            && in_array(
                $project->supplier_org_id,
                $assignee->getSupplierDescendantOrgIds(),
                true,
            );

        if (! $isSupplierDeveloper || (! $isProjectMember && ! $isSupplierMember)) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The assignee must be a supplier developer assigned to this project.',
            ]);
        }
    }

    private function assertAction(
        bool $allowed,
        DefectStatus $current,
        string $action,
    ): void {
        if (! $allowed) {
            throw new DomainConflictException(
                'INVALID_DEFECT_TRANSITION',
                errors: [
                    'status' => ['current' => $current->value],
                    'action' => [$action],
                ],
                message: "Defect action {$action} is invalid from {$current->name}.",
            );
        }
    }

    private function lock(Defect $defect): Defect
    {
        return Defect::query()
            ->whereKey($defect->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function audit(
        User $actor,
        Defect $defect,
        int $actionType,
        ?array $detail = null,
    ): void {
        AuditLogger::log($actor->id, [
            'user_name' => $actor->username,
            'user_display_name' => $actor->display_name,
            'user_type' => $actor->user_type,
            'module' => 4,
            'action_type' => $actionType,
            'target_type' => 'defect',
            'target_id' => $defect->id,
            'target_name' => $defect->title,
            'detail' => $detail,
        ]);
    }

    private function fresh(Defect $defect): Defect
    {
        return $defect->fresh([
            'reporter:id,display_name',
            'assignee:id,display_name',
            'requirement:id,title,status,submitter_id',
            'project:id,name,manager_id,supplier_org_id',
        ]);
    }
}
