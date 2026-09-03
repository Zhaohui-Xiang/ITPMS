<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuditLogger;
use App\Http\Requests\AssignDefectRequest;
use App\Http\Requests\ReopenDefectRequest;
use App\Http\Requests\ResolveDefectRequest;
use App\Http\Requests\StoreDefectRequest;
use App\Http\Requests\VerifyDefectRequest;
use App\Http\Resources\DefectResource;
use App\Models\Defect;
use App\Models\Project;
use App\Models\User;
use App\Scopes\DefectScope;
use App\Services\DefectWorkflowService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DefectController extends Controller
{
    public function __construct(
        private readonly DefectWorkflowService $workflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = DefectScope::apply($this->resourceQuery(), $request->user());

        foreach (['status', 'severity', 'defect_type', 'project_id', 'requirement_id', 'assignee_id'] as $filter) {
            if ($request->has($filter)) {
                $query->where($filter, $request->integer($filter));
            }
        }

        if ($request->has('keyword')) {
            $query->where('title', 'like', '%'.$request->input('keyword').'%');
        }

        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $query
            ->orderBy('severity')
            ->orderByDesc('created_at')
            ->paginate($pageSize);

        return ApiResponse::paginated(
            $paginator,
            fn (Defect $defect): array => (new DefectResource($defect))->resolve($request),
        );
    }

    public function store(StoreDefectRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $project = Project::query()->findOrFail((int) $validated['project_id']);
        Gate::forUser($request->user())->authorize(
            'createForProject',
            [Defect::class, $project],
        );

        $storedScreenshot = null;

        try {
            if ($request->hasFile('screenshot')) {
                $storedScreenshot = $request
                    ->file('screenshot')
                    ->store('defects/screenshots', 'public');
                $validated['screenshot'] = $storedScreenshot;
            } else {
                unset($validated['screenshot']);
            }

            $defect = $this->workflow->create($validated, $request->user());
        } catch (Throwable $exception) {
            if (is_string($storedScreenshot)) {
                Storage::disk('public')->delete($storedScreenshot);
            }

            throw $exception;
        }

        return ApiResponse::success(
            (new DefectResource($defect))->resolve($request),
            'Defect created.',
            201,
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $defect = $this->resourceQuery()->findOrFail($id);
        Gate::forUser($request->user())->authorize('view', $defect);

        return ApiResponse::success(
            (new DefectResource($defect))->resolve($request),
        );
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $defect = $this->resourceQuery()->findOrFail($id);
        Gate::forUser($request->user())->authorize('update', $defect);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'severity' => ['sometimes', 'integer:strict', 'in:1,2,3,4'],
            'defect_type' => ['sometimes', 'integer:strict', 'in:1,2,3,4,5'],
        ]);

        $updated = DB::transaction(function () use ($defect, $validated, $request): Defect {
            $locked = Defect::query()
                ->whereKey($defect->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->update($validated);
            $actor = $request->user();

            AuditLogger::log($actor->id, [
                'user_name' => $actor->username,
                'user_display_name' => $actor->display_name,
                'user_type' => $actor->user_type,
                'module' => 4,
                'action_type' => 2,
                'target_type' => 'defect',
                'target_id' => $locked->id,
                'target_name' => $locked->title,
            ]);

            return $this->present($locked);
        });

        return ApiResponse::success(
            (new DefectResource($updated))->resolve($request),
            'Defect updated.',
        );
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $defect = $this->resourceQuery()->findOrFail($id);
        Gate::forUser($request->user())->authorize('confirm', $defect);

        $defect = $this->workflow->confirm($defect, $request->user());

        return ApiResponse::success(
            (new DefectResource($defect))->resolve($request),
            'Defect confirmed.',
        );
    }

    public function assign(AssignDefectRequest $request, int $id): JsonResponse
    {
        $defect = Defect::query()->findOrFail($id);
        $assignee = User::query()->findOrFail((int) $request->validated('assignee_id'));
        $defect = $this->workflow->assign($defect, $assignee, $request->user());

        return ApiResponse::success(
            (new DefectResource($defect))->resolve($request),
            'Defect assigned.',
        );
    }

    public function resolve(ResolveDefectRequest $request, int $id): JsonResponse
    {
        $defect = Defect::query()->findOrFail($id);
        $defect = $this->workflow->resolve(
            $defect,
            $request->user(),
            $request->validated('fix_description'),
        );

        return ApiResponse::success(
            (new DefectResource($defect))->resolve($request),
            'Defect resolution submitted.',
        );
    }

    public function verify(VerifyDefectRequest $request, int $id): JsonResponse
    {
        $defect = Defect::query()->findOrFail($id);
        $validated = $request->validated();
        $defect = $this->workflow->verify(
            $defect,
            $request->user(),
            $validated['result'],
            $validated['comment'] ?? null,
        );

        return ApiResponse::success(
            (new DefectResource($defect))->resolve($request),
            'Defect verification recorded.',
        );
    }

    public function reopen(ReopenDefectRequest $request, int $id): JsonResponse
    {
        $defect = Defect::query()->findOrFail($id);
        $defect = $this->workflow->reopen(
            $defect,
            $request->user(),
            $request->validated('reason'),
        );

        return ApiResponse::success(
            (new DefectResource($defect))->resolve($request),
            'Defect reopened.',
        );
    }

    private function resourceQuery(): Builder
    {
        return Defect::query()->with([
            'reporter:id,display_name',
            'assignee:id,display_name',
            'requirement:id,title,status,submitter_id',
            'project:id,name,manager_id,supplier_org_id',
        ]);
    }

    private function present(Defect $defect): Defect
    {
        return $defect->fresh([
            'reporter:id,display_name',
            'assignee:id,display_name',
            'requirement:id,title,status,submitter_id',
            'project:id,name,manager_id,supplier_org_id',
        ]);
    }
}
