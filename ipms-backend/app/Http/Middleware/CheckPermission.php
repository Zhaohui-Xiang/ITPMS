<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
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
            return ApiResponse::error('UNAUTHENTICATED', 'Unauthenticated.', 401);
        }

        if (!$user->hasPermission($permission)) {
            return ApiResponse::error(
                'FORBIDDEN',
                '权限不足，无法执行此操作。',
                403,
                ['required_permission' => $permission],
            );
        }

        return $next($request);
    }
}
