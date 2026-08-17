<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationConfig;
use App\Models\NotificationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * 获取通知配置
     * GET /api/notification-configs
     */
    public function config(Request $request): JsonResponse
    {
        $user = $request->user();

        $config = NotificationConfig::firstOrCreate(
            ['user_id' => $user->id],
            [
                'remind_enabled' => true,
                'remind_days_before' => 1,
            ]
        );

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $config,
        ]);
    }

    /**
     * 更新通知配置
     * PUT /api/notification-configs
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'remind_enabled' => ['nullable', 'boolean'],
            'remind_days_before' => ['nullable', 'integer', 'min:0', 'max:30'],
        ]);

        $config = NotificationConfig::firstOrCreate(
            ['user_id' => $user->id],
            ['remind_enabled' => true, 'remind_days_before' => 1]
        );

        $config->update($request->only(['remind_enabled', 'remind_days_before']));

        return response()->json([
            'code' => 200,
            'message' => '通知配置更新成功',
            'data' => $config,
        ]);
    }

    /**
     * 通知发送日志
     * GET /api/notification-logs
     */
    public function logs(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = NotificationLog::with([
            'recipient:id,display_name,username',
            'relatedTask:id,title',
            'relatedRequirement:id,title',
        ]);

        // 权限过滤
        if ($user->isSuperAdmin()) {
            // 超管查看所有
        } else {
            // 普通用户只看自己的通知
            $query->where('recipient_id', $user->id);
        }

        // 按通知类型筛选
        if ($request->has('notification_type')) {
            $query->where('notification_type', $request->input('notification_type'));
        }

        // 按发送状态筛选
        if ($request->has('status')) {
            $query->where('status', $request->integer('status'));
        }

        $perPage = min($request->integer('per_page', 20), 100);
        $paginator = $query->orderBy('sent_at', 'desc')->paginate($perPage);

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
}
