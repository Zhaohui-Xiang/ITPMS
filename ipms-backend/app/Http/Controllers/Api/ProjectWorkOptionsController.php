<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Defect;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class ProjectWorkOptionsController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $gate = Gate::forUser($request->user());
        $gate->authorize('view', $project);
        $type = $request->validate(['type' => ['required', 'in:task,defect']])['type'];
        if ($type === 'task') {
            $task = (new Task())->setRelation('project', $project);
            abort_unless($gate->allows('createForProject', [Task::class, $project])
                || $gate->allows('assign', $task), 403);
        } else {
            $gate->authorize('assign', (new Defect())->setRelation('project', $project));
        }

        $memberIds = $project->members()->pluck('user_id');
        $query = User::query()->where('is_active', true)->where('is_disabled', false);
        if ($type === 'defect') {
            $query->whereHas('roles', fn ($roles) => $roles->where('code', 'supplier_dev'));
        }

        $options = [];
        // Reuse the existing supplier-tree eligibility rule, including sibling teams.
        foreach ($query->orderBy('display_name')->orderBy('id')->cursor() as $candidate) {
            $member = $memberIds->contains($candidate->id)
                || ($type === 'task' && $project->manager_id === $candidate->id);
            $supplier = $project->supplier_org_id !== null
                && in_array($project->supplier_org_id, $candidate->getSupplierDescendantOrgIds(), true);
            if ($member || $supplier) {
                $options[] = ['id' => $candidate->id, 'display_name' => $candidate->display_name];
            }
        }

        return ApiResponse::success($options);
    }
}
