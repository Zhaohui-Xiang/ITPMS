<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * 授权注册表校验器（AuthorizationRegistryTest 与 ipms:perm-preflight 共用）。
 */
final class AuthorizationRegistry
{
    /** @return array<string, true> */
    public static function protectedRouteKeys(): array
    {
        $keys = [];
        foreach (Route::getRoutes() as $route) {
            if (! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $keys[$method.' /'.$route->uri()] = true;
            }
        }
        return $keys;
    }

    /** @return list<string> 缺少注册表条目的受保护路由 */
    public static function missingEntries(): array
    {
        $registry = config('authorization.routes', []);
        return array_values(array_filter(
            array_keys(self::protectedRouteKeys()),
            fn (string $key) => ! isset($registry[$key]),
        ));
    }

    /** @return list<string> 指向不存在路由的注册表条目 */
    public static function orphanEntries(): array
    {
        $real = self::protectedRouteKeys();
        return array_values(array_filter(
            array_keys(config('authorization.routes', [])),
            fn (string $key) => ! isset($real[$key]),
        ));
    }
}
