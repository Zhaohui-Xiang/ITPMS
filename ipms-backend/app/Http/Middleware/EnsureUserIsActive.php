<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 请求级账号禁用校验：禁用用户的既有会话立即失效。
 * 必须挂在 auth 之后使用。
 */
final class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->is_disabled) {
            Auth::guard('web')->logout();
            Auth::guard('sanctum')->forgetUser();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return ApiResponse::error(
                'ACCOUNT_DISABLED',
                '账号已被禁用，请联系管理员。',
                403,
            );
        }

        return $next($request);
    }
}
