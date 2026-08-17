<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle login request via Sanctum SPA.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt(['email' => $credentials['username'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'username' => ['用户名或密码错误。'],
            ]);
        }

        $user = Auth::user();

        // Check if account is disabled
        if ($user->is_disabled) {
            Auth::logout();
            return response()->json([
                'message' => '账号已被禁用，请联系管理员。',
            ], 403);
        }

        // Check if account is active
        if (!$user->is_active) {
            Auth::logout();
            return response()->json([
                'message' => '账号未激活，请联系管理员。',
            ], 403);
        }

        $request->session()->regenerate();

        // Update last login time
        $user->last_login = now();
        $user->save();

        return response()->json([
            'message' => '登录成功',
            'user' => $this->formatUser($user),
            'must_change_password' => $user->must_change_password,
        ]);
    }

    /**
     * Handle logout request.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => '已登出',
        ]);
    }

    /**
     * Get current authenticated user info.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->formatUser($user),
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
