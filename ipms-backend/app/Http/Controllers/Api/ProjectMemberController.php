<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Exceptions\DomainConflictException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Models\Project;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ProjectMemberController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageMembers', $project);

        return ApiResponse::success($this->members($project));
    }

    public function options(Request $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageMembers', $project);
        $existing = $project->members()->pluck('user_id')->push($project->manager_id);
        $options = User::query()->where('is_active', true)->where('is_disabled', false)
            ->whereNotIn('id', $existing)->orderBy('display_name')->orderBy('id')
            ->get()->filter(fn (User $user): bool => $this->eligible($user, $project))
            ->map(fn (User $user): array => ['id' => $user->id, 'display_name' => $user->display_name])
            ->values()->all();

        return ApiResponse::success($options);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageMembers', $project);
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        return DB::transaction(function () use ($request, $project, $data): JsonResponse {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($request->user())->authorize('manageMembers', $project);
            $user = User::whereKey($data['user_id'])->lockForUpdate()->firstOrFail();
            if (! $this->eligible($user, $project)) {
                throw ValidationException::withMessages(['user_id' => '请选择有效的内部 IT 成员或本项目供应商成员。']);
            }
            $member = $project->members()->firstOrCreate(['user_id' => $user->id], [
                'role_in_project' => $user->id === $project->manager_id ? 'pm' : 'member',
                'assigned_by_id' => $request->user()->id, 'assigned_at' => now(),
            ]);
            if ($member->wasRecentlyCreated) {
                $this->audit($request->user(), $project, 'member_added', $user->id);
            }

            return ApiResponse::success($this->members($project), '项目成员已添加');
        });
    }

    public function destroy(Request $request, int $id, int $userId): JsonResponse
    {
        $project = Project::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageMembers', $project);

        return DB::transaction(function () use ($request, $project, $userId): JsonResponse {
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($request->user())->authorize('manageMembers', $project);
            if ($project->manager_id === $userId) {
                throw new DomainConflictException('PROJECT_MANAGER_REQUIRED', message: '不能移除当前项目经理。');
            }
            $removed = $project->members()->where('user_id', $userId)->delete();
            if ($removed > 0) {
                $this->audit($request->user(), $project, 'member_removed', $userId);
            }

            return ApiResponse::success($this->members($project), '项目成员已移除');
        });
    }

    private function eligible(User $user, Project $project): bool
    {
        if (! $user->is_active || $user->is_disabled) {
            return false;
        }
        if ($user->user_type === UserType::INTERNAL->value) {
            return $user->roles()->whereIn('code', ['it_pm', 'it_member'])->exists();
        }

        return $user->user_type === UserType::SUPPLIER->value
            && $user->roles()->whereIn('code', ['supplier_pm', 'supplier_dev', 'supplier_tester'])->exists()
            && $project->supplier_org_id !== null
            && in_array($project->supplier_org_id, $user->getSupplierDescendantOrgIds(), true);
    }

    private function members(Project $project): array
    {
        $members = $project->members()->with('user:id,display_name,is_active,is_disabled')
            ->orderBy('id')->get()->map(fn ($member): array => [
                'user_id' => $member->user_id,
                'display_name' => $member->user?->display_name,
                'role_in_project' => $member->role_in_project,
                'is_manager' => $member->user_id === $project->manager_id,
                'is_active' => $member->user?->is_active && ! $member->user?->is_disabled,
                'assigned_at' => $member->assigned_at?->toISOString(),
            ]);
        if (! $members->contains('user_id', $project->manager_id) && $project->manager !== null) {
            $members->prepend([
                'user_id' => $project->manager_id, 'display_name' => $project->manager->display_name,
                'role_in_project' => 'pm', 'is_manager' => true,
                'is_active' => $project->manager->is_active && ! $project->manager->is_disabled,
                'assigned_at' => null,
            ]);
        }

        return $members->all();
    }

    private function audit(User $actor, Project $project, string $operation, int $userId): void
    {
        AuditLogger::log($actor->id, [
            'user_name' => $actor->username, 'user_display_name' => $actor->display_name,
            'user_type' => $actor->user_type, 'module' => 'project', 'action_type' => 'update',
            'target_type' => 'project', 'target_id' => $project->id, 'target_name' => $project->name,
            'detail' => ['operation' => $operation, 'user_id' => $userId],
        ]);
    }
}
