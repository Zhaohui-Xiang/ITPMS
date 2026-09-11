<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\Task;
use App\Scopes\DefectScope;
use App\Scopes\ProjectScope;
use App\Scopes\RequirementScope;
use App\Scopes\TaskScope;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated($this->query($request)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('page_size', 20), 1), 100)));
    }

    public function export(Request $request): mixed
    {
        $query = $this->query($request);
        if ((clone $query)->count() > 10000) {
            return ApiResponse::error('AUDIT_EXPORT_LIMIT_EXCEEDED', '请缩小筛选范围后导出，最多 10000 条。', 422);
        }
        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->get();
        return response()->stream(function () use ($logs): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['ID', '操作人', '操作时间', '模块', '操作类型', '目标类型', '目标名称', 'IP地址']);
            foreach ($logs as $log) {
                $cells = [$log->id, $log->user_display_name ?: $log->user_name,
                    $log->created_at, $log->module, $log->action_type,
                    $log->target_type, $log->target_name, $log->ip_address];
                fputcsv($stream, array_map(static function ($value): string {
                    $text = (string) ($value ?? '');
                    return preg_match('/^[\\s]*[=+@-]/u', $text) ? "'".$text : $text;
                }, $cells));
            }
            fclose($stream);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit-logs.csv"',
        ]);
    }

    private function query(Request $request): Builder
    {
        $filters = $request->validate([
            'module' => ['nullable', 'integer', 'between:1,7'],
            'action_type' => ['nullable', 'integer', 'between:1,10'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'target_type' => ['nullable', 'string', 'max:100'],
            'keyword' => ['nullable', 'string', 'max:200'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $user = $request->user();
        $query = AuditLog::query();
        if (! $user->isSuperAdmin()) {
            $query->where(function (Builder $scope) use ($request, $user): void {
                $scope->where('user_id', $user->id);
                $this->addTargets($scope, $request);
            });
        }
        if (! empty($filters['project_id'])) {
            $query->where(function (Builder $scope) use ($request, $filters): void {
                $scope->whereRaw('1 = 0');
                $this->addTargets($scope, $request, (int) $filters['project_id']);
            });
        }
        foreach (['module', 'action_type', 'user_id', 'target_type'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['keyword']) && $filters['keyword'] !== '') {
            $query->where(function (Builder $search) use ($filters): void {
                foreach (['target_name', 'user_name', 'user_display_name'] as $field) {
                    $search->orWhere($field, 'like', '%'.$filters['keyword'].'%');
                }
            });
        }
        if (! empty($filters['date_from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        }
        if (! empty($filters['date_to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }
        return $query;
    }

    private function addTargets(Builder $query, Request $request, ?int $projectId = null): void
    {
        $user = $request->user();
        $projects = ProjectScope::apply(Project::query(), $user);
        $requirements = RequirementScope::apply(Requirement::query(), $user);
        $tasks = TaskScope::apply(Task::query(), $user);
        $defects = DefectScope::apply(Defect::query(), $user);
        if ($projectId !== null) {
            $projects->whereKey($projectId);
            $requirements->whereHas('projects', fn ($q) => $q->whereKey($projectId));
            $tasks->where('project_id', $projectId);
            $defects->where('project_id', $projectId);
        }

        $targets = [
            'project' => $projects,
            'requirement' => $requirements,
            'task' => $tasks,
            'defect' => $defects,
        ];
        if ($user->user_type !== UserType::SYSTEM_USER->value) {
            $targets['project_version'] = ProjectVersion::query()->whereIn('project_id', (clone $projects)->select('projects.id'));
        }
        foreach ($targets as $type => $targetQuery) {
            $table = $targetQuery->getModel()->getTable();
            // Compare text to text: audit targets can refer to deleted or non-numeric identifiers.
            $ids = $targetQuery->selectRaw("CAST({$table}.id AS TEXT)");
            $query->orWhere(fn (Builder $target) => $target
                ->where('target_type', $type)->whereIn('target_id', $ids));
        }
    }
}
