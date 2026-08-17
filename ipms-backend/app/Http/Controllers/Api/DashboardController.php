<?php

namespace App\Http\Controllers\Api;

use App\Enums\DefectStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\Defect;
use App\Models\Requirement;
use App\Models\Task;
use App\Scopes\DefectScope;
use App\Scopes\RequirementScope;
use App\Scopes\TaskScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * 首页统计
     * GET /api/dashboard/stats
     *
     * 返回：我的需求数、待处理任务数、未关闭缺陷数
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        // 我的需求数（可见范围内的需求总数）
        $reqQuery = Requirement::query();
        $reqQuery = RequirementScope::apply($reqQuery, $user);
        $requirementCount = $reqQuery->count();

        // 待处理任务数（状态为待开始或进行中，且我是负责人）
        $taskQuery = Task::query();
        $taskQuery = TaskScope::apply($taskQuery, $user);
        $pendingTaskCount = (clone $taskQuery)->whereIn('status', [
            TaskStatus::TODO->value,
            TaskStatus::IN_PROGRESS->value,
        ])->where('assignee_id', $user->id)->count();

        // 未关闭缺陷数（状态不是已关闭的）
        $defectQuery = Defect::query();
        $defectQuery = DefectScope::apply($defectQuery, $user);
        $openDefectCount = (clone $defectQuery)->where('status', '!=', DefectStatus::CLOSED->value)->count();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'requirement_count' => $requirementCount,
                'pending_task_count' => $pendingTaskCount,
                'open_defect_count' => $openDefectCount,
            ],
        ]);
    }

    /**
     * 近期需求
     * GET /api/dashboard/recent-requirements
     */
    public function recentRequirements(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Requirement::with([
            'submitter:id,display_name',
            'reviewer:id,display_name',
        ]);
        $query = RequirementScope::apply($query, $user);

        $requirements = $query->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $requirements,
        ]);
    }

    /**
     * 近期任务
     * GET /api/dashboard/recent-tasks
     */
    public function recentTasks(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Task::with([
            'assignee:id,display_name',
            'requirement:id,title',
            'project:id,name',
        ]);
        $query = TaskScope::apply($query, $user);

        $tasks = $query->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $tasks,
        ]);
    }
}
