<?php

namespace App\Services;

use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Exceptions\DomainConflictException;
use App\Http\Middleware\AuditLogger;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskWorkflowService
{
    private const WRITABLE_FIELDS = [
        'requirement_id',
        'project_id',
        'title',
        'description',
        'assignee_id',
        'priority',
        'due_date',
        'remind_days_before',
        'estimated_hours',
    ];

    private const UPDATE_FIELDS = [
        'title',
        'description',
        'assignee_id',
        'priority',
        'due_date',
        'remind_days_before',
        'estimated_hours',
        'actual_hours',
    ];

    public function create(array $data, User $actor): Task
    {
        return DB::transaction(function () use ($data, $actor): Task {
            $requirementId = (int) $data['requirement_id'];
            $projectId = (int) $data['project_id'];
            $this->assertRequirementProjectRelation($requirementId, $projectId);
            $this->assertRequirementApproved($requirementId);

            if (isset($data['assignee_id'])) {
                $this->assertAssigneeEligible($projectId, (int) $data['assignee_id']);
            }

            $task = Task::query()->create([
                ...Arr::only($data, self::WRITABLE_FIELDS),
                'remind_days_before' => $data['remind_days_before'] ?? 1,
                'status' => TaskStatus::TODO->value,
                'suspend_reason' => null,
                'completed_at' => null,
                'created_by_id' => $actor->id,
            ]);

            $this->audit($actor, $task, 1);

            return $this->fresh($task);
        });
    }

    public function update(Task $task, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($task, $data, $actor): Task {
            $locked = $this->lock($task);

            if (array_key_exists('assignee_id', $data)
                && $data['assignee_id'] !== null) {
                $this->assertAssigneeEligible(
                    $locked->project_id,
                    (int) $data['assignee_id'],
                );
            }

            $locked->update(Arr::only($data, self::UPDATE_FIELDS));
            $this->audit($actor, $locked, 2, [
                'assignee_id' => $data['assignee_id'] ?? $locked->assignee_id,
            ]);

            return $this->fresh($locked);
        });
    }

    public function claim(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor): Task {
            $locked = $this->lock($task);

            if ($locked->status !== TaskStatus::TODO->value
                || $locked->assignee_id !== null) {
                throw new DomainConflictException(
                    'TASK_NOT_CLAIMABLE',
                    errors: [
                        'status' => ['current' => $locked->status],
                        'assignee_id' => ['current' => $locked->assignee_id],
                    ],
                    message: 'The task is no longer available to claim.',
                );
            }

            $locked->update([
                'assignee_id' => $actor->id,
                'status' => TaskStatus::IN_PROGRESS->value,
                'suspend_reason' => null,
                'completed_at' => null,
            ]);
            $this->audit($actor, $locked, 6, [
                'to_status' => TaskStatus::IN_PROGRESS->value,
                'assignee_id' => $actor->id,
            ]);

            return $this->fresh($locked);
        });
    }

    public function transition(
        Task $task,
        TaskStatus|int $targetStatus,
        User $actor,
        ?string $reason = null,
    ): Task {
        $target = $targetStatus instanceof TaskStatus
            ? $targetStatus
            : $this->statusFrom($targetStatus);

        if ($target === TaskStatus::SUSPENDED && blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A hold reason is required.',
            ]);
        }

        return DB::transaction(function () use ($task, $target, $actor, $reason): Task {
            $locked = $this->lock($task);
            $current = TaskStatus::from($locked->status);
            $this->assertActorStillAssigned($locked, $actor);

            if (! in_array($target, $current->allowedTransitions(), true)) {
                throw new DomainConflictException(
                    'INVALID_TASK_TRANSITION',
                    errors: [
                        'status' => [
                            'current' => $current->value,
                            'requested' => $target->value,
                        ],
                    ],
                    message: "Task cannot move from {$current->name} to {$target->name}.",
                );
            }

            $locked->update([
                'status' => $target->value,
                'completed_at' => $target === TaskStatus::COMPLETED ? now() : null,
                'suspend_reason' => $target === TaskStatus::SUSPENDED
                    ? trim((string) $reason)
                    : null,
            ]);
            $this->audit($actor, $locked, 4, [
                'from_status' => $current->value,
                'to_status' => $target->value,
                'reason' => $target === TaskStatus::SUSPENDED
                    ? trim((string) $reason)
                    : null,
            ]);

            return $this->fresh($locked);
        });
    }

    public function hold(Task $task, User $actor, string $reason): Task
    {
        if (blank($reason)) {
            throw ValidationException::withMessages([
                'reason' => 'A hold reason is required.',
            ]);
        }

        return $this->transition(
            $task,
            TaskStatus::SUSPENDED,
            $actor,
            trim($reason),
        );
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

    private function assertRequirementApproved(int $requirementId): void
    {
        $requirement = Requirement::query()->findOrFail($requirementId);

        if ($requirement->status === RequirementStatus::PENDING_REVIEW->value) {
            throw new DomainConflictException(
                'REQUIREMENT_NOT_APPROVED',
                errors: [
                    'status' => ['current' => $requirement->status],
                ],
                message: 'Tasks can only be created for an approved requirement.',
            );
        }
    }

    private function assertAssigneeEligible(int $projectId, int $assigneeId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $assignee = User::query()->findOrFail($assigneeId);
        $isInternalMember = $project->manager_id === $assignee->id
            || $project->members()->where('user_id', $assignee->id)->exists();
        $isSupplierMember = $project->supplier_org_id !== null
            && in_array(
                $project->supplier_org_id,
                $assignee->getSupplierDescendantOrgIds(),
                true,
            );

        if (! $isInternalMember && ! $isSupplierMember) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The assignee is not assigned to this project.',
            ]);
        }
    }

    private function assertActorStillAssigned(Task $task, User $actor): void
    {
        $isSupplierDeveloper = $actor->roles()
            ->where('code', 'supplier_dev')
            ->exists();

        if ($isSupplierDeveloper && $task->assignee_id !== $actor->id) {
            throw new DomainConflictException(
                'TASK_ASSIGNEE_MISMATCH',
                errors: [
                    'assignee_id' => [
                        'current' => $task->assignee_id,
                        'actor' => $actor->id,
                    ],
                ],
                message: 'Only the currently assigned developer may transition this task.',
            );
        }
    }

    private function lock(Task $task): Task
    {
        return Task::query()
            ->whereKey($task->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function statusFrom(int $status): TaskStatus
    {
        $target = TaskStatus::tryFrom($status);

        if ($target === null) {
            throw ValidationException::withMessages([
                'status' => 'The task status is invalid.',
            ]);
        }

        return $target;
    }

    private function audit(
        User $actor,
        Task $task,
        int $actionType,
        ?array $detail = null,
    ): void {
        AuditLogger::log($actor->id, [
            'user_name' => $actor->username,
            'user_display_name' => $actor->display_name,
            'user_type' => $actor->user_type,
            'module' => 3,
            'action_type' => $actionType,
            'target_type' => 'task',
            'target_id' => $task->id,
            'target_name' => $task->title,
            'detail' => $detail,
        ]);
    }

    private function fresh(Task $task): Task
    {
        return $task->fresh([
            'assignee:id,display_name',
            'requirement:id,title,status',
            'project:id,name,manager_id,supplier_org_id',
        ]);
    }
}
