<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'success', int $status = 200): JsonResponse
    {
        return response()->json(['code' => $status, 'message' => $message, 'data' => $data], $status);
    }

    public static function paginated(LengthAwarePaginator $paginator, ?callable $map = null): JsonResponse
    {
        $items = collect($paginator->items());
        if ($map !== null) {
            $items = $items->map($map);
        }

        return self::success([
            'items' => $items->values()->all(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
        ]);
    }

    public static function error(
        string $errorCode,
        string $message,
        int $status,
        array $errors = [],
        ?string $traceId = null,
    ): JsonResponse {
        return response()->json([
            'code' => $status,
            'error_code' => $errorCode,
            'message' => $message,
            'errors' => $errors,
            'trace_id' => $traceId ?? request()->header('X-Request-ID') ?? (string) str()->uuid(),
        ], $status);
    }
}
