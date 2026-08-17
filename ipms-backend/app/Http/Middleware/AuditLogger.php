<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditLogger
{
    /**
     * Handle an incoming request.
     *
     * Logs API operations to the audit_logs table.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only log state-changing requests
        if (!$this->shouldLog($request)) {
            return $response;
        }

        // Defer logging to after response is sent (terminate)
        // The actual logging happens in a terminate callback or via Observer

        return $response;
    }

    /**
     * Determine if this request should be logged.
     */
    private function shouldLog(Request $request): bool
    {
        $method = $request->method();
        return in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    /**
     * Log the operation. Called from controllers or services directly.
     */
    public static function log(int $userId, array $data): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'user_name' => $data['user_name'] ?? '',
            'user_display_name' => $data['user_display_name'] ?? '',
            'user_type' => $data['user_type'] ?? 1,
            'module' => $data['module'],
            'action_type' => $data['action_type'],
            'target_type' => $data['target_type'],
            'target_id' => (string) ($data['target_id'] ?? ''),
            'target_name' => $data['target_name'] ?? null,
            'detail' => $data['detail'] ?? null,
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }
}
