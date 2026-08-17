<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle login request via Sanctum SPA.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = $validated['username'];
        $column = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false ? 'email' : 'username';
        $user = User::query()
            ->whereRaw("LOWER({$column}) = LOWER(?)", [$identifier])
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['用户名或密码错误。'],
            ]);
        }

        if ($user->is_disabled) {
            return ApiResponse::error(
                'ACCOUNT_DISABLED',
                '账号已被禁用，请联系管理员。',
                403,
            );
        }

        if (! $user->is_active) {
            return ApiResponse::error(
                'ACCOUNT_INACTIVE',
                '账号未激活，请联系管理员。',
                403,
            );
        }

        Auth::guard('web')->login($user, $request->boolean('remember'));
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $user->last_login = now();
        $user->save();

        return ApiResponse::success([
            'user' => $this->formatUser($user),
            'must_change_password' => $user->must_change_password,
        ], '登录成功');
    }

    /**
     * Handle logout request.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(message: '已登出');
    }

    /**
     * Get current authenticated user info.
     */
    public function user(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'user' => $this->formatUser($request->user()),
        ]);
    }

    /**
     * Format user data for response.
     */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'user_type' => $user->user_type,
            'is_super_admin' => $user->isSuperAdmin(),
            'roles' => $user->roles()->pluck('code'),
            'permissions' => $user->roles()
                ->with('permissions')
                ->get()
                ->pluck('permissions.*.code')
                ->flatten()
                ->unique()
                ->values(),
            'organizations' => $user->organizations()
                ->select('organizations.id', 'organizations.name', 'organizations.org_type')
                ->get(),
            'must_change_password' => $user->must_change_password,
        ];
    }
}
