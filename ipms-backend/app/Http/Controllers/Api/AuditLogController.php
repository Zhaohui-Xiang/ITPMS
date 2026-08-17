<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * 操作日志列表（权限过滤，组合条件查询，分页）
     * GET /api/audit-logs
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = AuditLog::with('user:id,display_name,username,user_type');

        // 权限过滤
        if ($user->isSuperAdmin()) {
            // 超管查看全量
        } elseif ($user->user_type === UserType::INTERNAL->value) {
            // 内部 IT 非超管：可以看自己及分配项目范围内的操作
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereIn('target_id', function ($sq) use ($user) {
                        $sq->select('projects.id')
                            ->from('projects')
                            ->whereHas('members', function ($ssq) use ($user) {
                                $ssq->where('user_id', $user->id);
                            });
                    });
            });
        } elseif ($user->user_type === UserType::SUPPLIER->value) {
            // 供应商：看自己及被分配项目范围内的操作
            $orgIds = $user->getSupplierDescendantOrgIds();
            $query->where(function ($q) use ($user, $orgIds) {
                $q->where('user_id', $user->id)
                    ->orWhereIn('target_id', function ($sq) use ($orgIds) {
                        $sq->select('id')
                            ->from('projects')
                            ->whereIn('supplier_org_id', $orgIds);
                    });
            });
        } else {
            // 系统用户：只看自己的操作
            $query->where('user_id', $user->id);
        }

        // 按模块筛选
        if ($request->has('module')) {
            $query->where('module', $request->input('module'));
        }

        // 按操作类型筛选
        if ($request->has('action_type')) {
            $query->where('action_type', $request->input('action_type'));
        }

        // 按操作人筛选
        if ($request->has('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        // 按目标类型筛选
        if ($request->has('target_type')) {
            $query->where('target_type', $request->input('target_type'));
        }

        // 按时间范围筛选
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        // 搜索
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('target_name', 'like', "%{$search}%")
                    ->orWhere('user_name', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'items' => $paginator->items(),
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * 导出 CSV
     * GET /api/audit-logs/export
     */
    public function export(Request $request): mixed
    {
        $user = $request->user();

        $query = AuditLog::with('user:id,display_name,username');

        // 应用与列表相同的权限过滤
        if (!$user->isSuperAdmin()) {
            if ($user->user_type === UserType::INTERNAL->value) {
                $query->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhereIn('target_id', function ($sq) use ($user) {
                            $sq->select('projects.id')
                                ->from('projects')
                                ->whereHas('members', function ($ssq) use ($user) {
                                    $ssq->where('user_id', $user->id);
                                });
                        });
                });
            } elseif ($user->user_type === UserType::SUPPLIER->value) {
                $orgIds = $user->getSupplierDescendantOrgIds();
                $query->where(function ($q) use ($user, $orgIds) {
                    $q->where('user_id', $user->id)
                        ->orWhereIn('target_id', function ($sq) use ($orgIds) {
                            $sq->select('id')
                                ->from('projects')
                                ->whereIn('supplier_org_id', $orgIds);
                        });
                });
            } else {
                $query->where('user_id', $user->id);
            }
        }

        // 应用相同的筛选条件（除分页外）
        if ($request->has('module')) {
            $query->where('module', $request->input('module'));
        }
        if ($request->has('action_type')) {
            $query->where('action_type', $request->input('action_type'));
        }
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }

        $logs = $query->orderBy('created_at', 'desc')->limit(10000)->get();

        // 生成 CSV
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="audit_logs_' . now()->format('YmdHis') . '.csv"',
        ];

        $callback = function () use ($logs) {
            $handle = fopen('php://output', 'w');
            // BOM for Excel UTF-8 compatibility
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['ID', '操作人', '操作时间', '模块', '操作类型', '目标类型', '目标名称', 'IP地址']);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->user_display_name ?: $log->user_name,
                    $log->created_at,
                    $log->module,
                    $log->action_type,
                    $log->target_type,
                    $log->target_name,
                    $log->ip_address,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
