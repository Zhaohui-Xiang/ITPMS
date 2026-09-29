<?php

namespace Tests\Feature;

use App\Support\AuthorizationRegistry;
use Tests\TestCase;

/**
 * 授权注册表完整性（v1.8 §2.8）：
 * - 每条 auth:sanctum 的 api 路由必须在注册表有条目
 * - 注册表每条目必须指向真实存在的受保护路由
 * - deferred 条目必须声明责任 Policy 方法与覆盖测试
 */
final class AuthorizationRegistryTest extends TestCase
{
    public function test_every_protected_route_is_registered(): void
    {
        $this->assertSame([], AuthorizationRegistry::missingEntries(), '注册表缺少路由条目');
    }

    public function test_every_registry_entry_points_to_a_real_protected_route(): void
    {
        $this->assertSame([], AuthorizationRegistry::orphanEntries(), '注册表条目指向不存在的路由');
    }

    public function test_deferred_entries_declare_policy_method_and_covering_test(): void
    {
        $failures = [];
        foreach (config('authorization.routes') as $key => $entry) {
            if (($entry['phase'] ?? null) !== 'deferred') {
                continue;
            }
            $policy = $entry['policy'] ?? null;
            $test = $entry['policy_test'] ?? null;
            if (! is_string($policy) || ! str_contains($policy, '@')) {
                $failures[] = "{$key}: 缺 policy（Class@method）";
                continue;
            }
            [$class, $method] = explode('@', $policy, 2);
            if (! class_exists($class) || ! method_exists($class, $method)) {
                $failures[] = "{$key}: policy 方法不存在 {$policy}";
            }
            if (! is_string($test) || $test === '') {
                $failures[] = "{$key}: 缺 policy_test";
                continue;
            }
            $candidates = [
                'Tests\\Feature\\Api\\'.$test,
                'Tests\\Feature\\Services\\'.$test,
                'Tests\\Feature\\'.$test,
            ];
            if (str_contains($test, '::')) {
                [$testClass, $testMethod] = explode('::', $test, 2);
                $fqcn = null;
                foreach (['Tests\\Feature\\Api\\', 'Tests\\Feature\\Services\\', 'Tests\\Feature\\'] as $ns) {
                    if (class_exists($ns.$testClass)) {
                        $fqcn = $ns.$testClass;
                        break;
                    }
                }
                if ($fqcn === null || ! method_exists($fqcn, $testMethod)) {
                    $failures[] = "{$key}: 测试不存在 {$test}";
                }
            } else {
                $found = false;
                foreach ($candidates as $fqcn) {
                    if (class_exists($fqcn)) {
                        $found = true;
                        break;
                    }
                }
                if (! $found) {
                    $failures[] = "{$key}: 测试类不存在 {$test}";
                }
            }
        }
        $this->assertSame([], $failures, "deferred 条目声明不完整：\n".implode("\n", $failures));
    }

    public function test_non_overridable_entries_cover_the_frozen_list(): void
    {
        $flagged = collect(config('authorization.routes'))
            ->filter(fn ($entry) => $entry['non_overridable'] ?? false)
            ->keys()
            ->all();

        // 2.3 不可配置覆盖清单：发布门禁/强制发布、缺陷复测、最后超管保护
        foreach ([
            'POST /api/project-versions/{id}/status',
            'POST /api/project-versions/{id}/release',
            'GET /api/project-versions/{id}/gate-check',
            'POST /api/defects/{id}/verify',
            'POST /api/defects/{id}/reopen',
            'POST /api/users/{id}/disable',
        ] as $required) {
            $this->assertContains($required, $flagged, "non_overridable 缺少 {$required}");
        }
    }

    public function test_entry_shape_contract(): void
    {
        $required = ['ability', 'phase', 'route_models', 'body_models', 'ability_args', 'outputs', 'channel'];
        foreach (config('authorization.routes') as $key => $entry) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $entry, "{$key} 缺字段 {$field}");
            }
            $this->assertSame('http', $entry['channel'], "{$key} channel 必须为 http");
            $this->assertContains($entry['phase'], ['A', 'deferred'], "{$key} phase 非法");
        }
    }
}
