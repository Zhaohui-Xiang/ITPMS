<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  $permission  Permission code to check
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => '未登录，请先登录。',
            ], 401);
        }

        if (!$user->hasPermission($permission)) {
            return response()->json([
                'message' => '权限不足，无法执行此操作。',
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
