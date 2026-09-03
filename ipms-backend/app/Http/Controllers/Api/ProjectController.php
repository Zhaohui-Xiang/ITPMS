<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Scopes\ProjectScope;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProjectScope::apply(
            $this->resourceQuery($request->user()),
            $request->user(),
        );

        if ($request->has('status')) {
            $query->where('status', $request->integer('status'));
        }

        if ($request->has('keyword')) {
            $query->where('name', 'like', '%'.$request->input('keyword').'%');
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query->orderByDesc('updated_at')->paginate($pageSize);

        return ApiResponse::paginated(
            $paginator,
            fn (Project $project): array => (new ProjectResource($project))->resolve($request),
        );
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $actor = $request->user();

        $project = DB::transaction(function () use ($validated, $actor): Project {
            $project = Project::query()->create([
                ...$validated,
                'status' => $validated['status'] ?? ProjectStatus::ACTIVE->value,
                'created_by_id' => $actor->id,
            ]);

            $project->members()->create([
                'user_id' => $actor->id,
                'role_in_project' => 'pm',
                'assigned_by_id' => $actor->id,
                'assigned_at' => now(),
            ]);

            $this->audit($actor, $project, 1);

            return $this->present($project, $actor);
        });

        return ApiResponse::success(
            (new ProjectResource($project))->resolve($request),
            'Project created.',
            201,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = $this->resourceQuery($request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $project);

        return ApiResponse::success(
            (new ProjectResource($project))->resolve($request),
        );
    }

    public function update(UpdateProjectRequest $request, int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $project);
        $actor = $request->user();

        $project = DB::transaction(function () use ($project, $request, $actor): Project {
            $locked = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->update($request->validated());
            $this->audit($actor, $locked, 2);

            return $this->present($locked, $actor);
        });

        return ApiResponse::success(
            (new ProjectResource($project))->resolve($request),
            'Project updated.',
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('delete', $project);

        $requirementCount = $project->requirements()->count();
        if ($requirementCount > 0) {
            return ApiResponse::error(
                'PROJECT_HAS_REQUIREMENTS',
                'The project still has linked requirements.',
                409,
                ['requirements' => ['count' => $requirementCount]],
            );
        }

        $actor = $request->user();
        DB::transaction(function () use ($project, $actor): void {
            $locked = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->audit($actor, $locked, 3);
            $locked->delete();
        });

        return ApiResponse::success(message: 'Project deleted.');
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        Gate::forUser($request->user())->authorize('archive', $project);
        $actor = $request->user();

        $project = DB::transaction(function () use ($project, $actor): Project {
            $locked = Project::query()
                ->whereKey($project->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->update(['status' => ProjectStatus::ARCHIVED->value]);
            $this->audit($actor, $locked, 4);

            return $this->present($locked, $actor);
        });

        return ApiResponse::success(
            (new ProjectResource($project))->resolve($request),
            'Project archived.',
        );
    }

    private function resourceQuery(User $actor): Builder
    {
        $query = Project::query()
            ->with([
                'manager:id,display_name',
                'supplierOrg:id,name',
            ]);

        if ($actor->user_type === UserType::SYSTEM_USER->value) {
            $requesterId = $actor->id;
            $query->withCount([
                'requirements' => fn (Builder $requirementQuery): Builder => $requirementQuery
                    ->where('submitter_id', $requesterId),
                'tasks' => fn (Builder $taskQuery): Builder => $taskQuery
                    ->whereHas('requirement', fn (Builder $requirementQuery): Builder => $requirementQuery
                        ->where('submitter_id', $requesterId)),
                'defects' => fn (Builder $defectQuery): Builder => $defectQuery
                    ->whereHas('requirement', fn (Builder $requirementQuery): Builder => $requirementQuery
                        ->where('submitter_id', $requesterId)),
                'versions' => fn (Builder $versionQuery): Builder => $versionQuery
                    ->whereHas('requirementLinks.requirement', fn (Builder $requirementQuery): Builder => $requirementQuery
                        ->where('submitter_id', $requesterId)),
            ]);
        } else {
            $query->withCount([
                'requirements',
                'tasks',
                'defects',
                'versions',
            ]);
        }

        foreach (ProjectVersionStatus::cases() as $status) {
            $alias = strtolower($status->name).'_versions_count';
            $query->withCount([
                "versions as {$alias}" => function (Builder $versionQuery) use ($actor, $status): Builder {
                    $versionQuery->where('status', $status->value);

                    if ($actor->user_type === UserType::SYSTEM_USER->value) {
                        $versionQuery->whereHas(
                            'requirementLinks.requirement',
                            fn (Builder $requirementQuery): Builder => $requirementQuery
                                ->where('submitter_id', $actor->id),
                        );
                    }

                    return $versionQuery;
                },
            ]);
        }

        return $query;
    }

    private function present(Project $project, User $actor): Project
    {
        return $this->resourceQuery($actor)->findOrFail($project->id);
    }

    private function audit(mixed $actor, Project $project, int $actionType): void
    {
        AuditLogger::log($actor->id, [
            'user_name' => $actor->username,
            'user_display_name' => $actor->display_name,
            'user_type' => $actor->user_type,
            'module' => 1,
            'action_type' => $actionType,
            'target_type' => 'project',
            'target_id' => $project->id,
            'target_name' => $project->name,
        ]);
    }
}
