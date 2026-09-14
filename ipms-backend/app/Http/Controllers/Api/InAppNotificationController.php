<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InAppNotificationResource;
use App\Models\InAppNotification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InAppNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pageSize = min(max($request->integer('page_size', 20), 1), 100);
        $paginator = $request->user()->inAppNotifications()
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($pageSize);

        return ApiResponse::paginated($paginator,
            fn (InAppNotification $item): array => (new InAppNotificationResource($item))->resolve($request));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => $request->user()->inAppNotifications()->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $item = $request->user()->inAppNotifications()->findOrFail($id);
        // Preserve the first read timestamp across concurrent or repeated requests.
        $request->user()->inAppNotifications()->whereKey($item->id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success((new InAppNotificationResource($item->fresh()))->resolve($request));
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = $request->user()->inAppNotifications()->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success([
            'updated_count' => $updated,
            'unread_count' => $request->user()->inAppNotifications()->whereNull('read_at')->count(),
        ]);
    }
}
