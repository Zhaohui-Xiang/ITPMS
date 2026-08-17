# IPMS Foundation, Authentication, and API Contract Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish repeatable tests, same-origin Sanctum login, consistent API envelopes, corrected frontend action contracts, and deterministic multi-role seed data.

**Architecture:** Add small response and pagination helpers instead of duplicating response shapes in controllers. Keep session-cookie authentication, make the frontend request the Sanctum CSRF cookie from the correct root path, and use seeders that read demo credentials from environment variables.

**Tech Stack:** PHP 8.2, Laravel 11, Sanctum 4, PHPUnit, PostgreSQL 15, Vue 3, Pinia, Axios, Vite 5, Vitest, Vue Test Utils

## Global Constraints

- Read `docs/superpowers/specs/2026-08-17-ipms-core-workflow-redesign-design.md` and the plan index before editing.
- Use PostgreSQL database `ipms_test` for backend tests; do not use SQLite because existing migrations contain PostgreSQL constraints.
- Keep `code`, `message`, and `data` in successful responses; add `error_code`, `errors`, and `trace_id` to failures.
- Standardize pagination on `page`, `page_size`, `items`, `total`, and `total_pages`.
- Use Sanctum SPA cookies; remove frontend `localStorage` token persistence.
- Every database-backed feature test uses `RefreshDatabase` and creates only the roles or records it needs.
- Do not hardcode demo, Microsoft Entra, database, SSH, or production credentials.
- Do not initialize Git without approval. Commit steps assume the repository prerequisite in the plan index has been satisfied.

---

### Task 1: Backend Test Harness

**Files:**
- Create: `ipms-backend/phpunit.xml`
- Create: `ipms-backend/tests/TestCase.php`
- Create: `ipms-backend/tests/Feature/HealthTest.php`
- Create: `ipms-backend/database/factories/UserFactory.php`
- Create: `ipms-backend/database/factories/ProjectFactory.php`
- Modify: `ipms-backend/app/Models/User.php`
- Modify: `ipms-backend/app/Models/Project.php`
- Modify: `ipms-backend/.env.example`

**Interfaces:**
- Consumes: Laravel application bootstrap at `ipms-backend/bootstrap/app.php`.
- Produces: `Tests\TestCase` and a PostgreSQL-backed `php artisan test` command used by all later backend tasks.

- [ ] **Step 1: Write the health test before creating the test base class**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->get('/up')->assertOk();
    }
}
```

- [ ] **Step 2: Run the test and verify the harness is missing**

Run: `cd ipms-backend && php artisan test --filter HealthTest`

Expected: FAIL because `Tests\TestCase` or `phpunit.xml` is missing.

- [ ] **Step 3: Add the test base, foundational factories, and PostgreSQL PHPUnit configuration**

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
}
```

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
  <testsuites>
    <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
  </testsuites>
  <php>
    <env name="APP_ENV" value="testing"/>
    <env name="APP_MAINTENANCE_DRIVER" value="file"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
    <env name="CACHE_STORE" value="array"/>
    <env name="DB_CONNECTION" value="pgsql"/>
    <env name="DB_HOST" value="127.0.0.1"/>
    <env name="DB_PORT" value="5432"/>
    <env name="DB_DATABASE" value="ipms_test"/>
    <env name="DB_USERNAME" value="ipms_test"/>
    <env name="MAIL_MAILER" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="SESSION_DRIVER" value="array"/>
  </php>
</phpunit>
```

Add `IPMS_DEMO_PASSWORD=` and `IPMS_SEED_DEMO=false` to `.env.example`; leave both values empty or false.

`UserFactory` must generate every required user column and hash a default test password. It must expose `internal()`, `supplier()`, and `systemUser()` states that set `UserType::INTERNAL`, `UserType::SUPPLIER`, and `UserType::SYSTEM_USER`. Add `withRole(string $code)`, which uses `Role::firstOrCreate()` with the canonical role name and user type, then attaches that role in `afterCreating`. Add `superAdmin()` as `internal()->withRole('super_admin')`.

`ProjectFactory` must generate a unique project name and valid numeric `system_type` and status values. Its default `manager_id` and `created_by_id` use `User::factory()->internal()`. Add `withManager(?User $manager = null)`, which assigns the supplied manager to both fields or creates one internal user when omitted.

Add `HasFactory` to `Project`. In `User::roles()`, remove `withTimestamps()` because `role_user` has `assigned_at` rather than Laravel's `created_at` and `updated_at`; retain `withPivot('assigned_by_id', 'assigned_at')`.

- [ ] **Step 4: Run the health test green**

Run: `cd ipms-backend && php artisan test --filter HealthTest`

Expected: PASS, one test and one assertion.

- [ ] **Step 5: Commit the test harness**

```bash
git add ipms-backend/phpunit.xml ipms-backend/tests ipms-backend/database/factories/UserFactory.php ipms-backend/database/factories/ProjectFactory.php ipms-backend/app/Models/User.php ipms-backend/app/Models/Project.php ipms-backend/.env.example
git commit -m "test: add PostgreSQL backend test harness"
```

### Task 2: API Response and Pagination Contract

**Files:**
- Create: `ipms-backend/app/Support/ApiResponse.php`
- Create: `ipms-backend/tests/Unit/Support/ApiResponseTest.php`
- Create: `ipms-backend/tests/Feature/Api/ProjectIndexContractTest.php`
- Create: `ipms-backend/tests/Feature/Api/CorePaginationContractTest.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/ProjectController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/RequirementController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/TaskController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/DefectController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/UserController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/AuditLogController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/NotificationController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/ApiDocumentController.php`
- Modify: `ipms-backend/bootstrap/app.php`

**Interfaces:**
- Consumes: `Illuminate\Pagination\LengthAwarePaginator` and Laravel JSON responses.
- Produces: `ApiResponse::success()`, transformer-aware `ApiResponse::paginated()`, and `ApiResponse::error()` used by every controller plan.

- [ ] **Step 1: Write unit tests for exact response keys**

```php
<?php

namespace Tests\Unit\Support;

use App\Support\ApiResponse;
use Tests\TestCase;

final class ApiResponseTest extends TestCase
{
    public function test_success_wraps_payload(): void
    {
        $response = ApiResponse::success(['id' => 7], 'created', 201);

        $this->assertSame(201, $response->status());
        $this->assertSame([
            'code' => 201,
            'message' => 'created',
            'data' => ['id' => 7],
        ], $response->getData(true));
    }

    public function test_error_includes_machine_code_and_trace_id(): void
    {
        $response = ApiResponse::error('STALE_VERSION', '数据已更新', 409, ['lock_version' => 4], 'trace-1');

        $this->assertSame('STALE_VERSION', $response->getData(true)['error_code']);
        $this->assertSame('trace-1', $response->getData(true)['trace_id']);
    }
}
```

- [ ] **Step 2: Run the unit test red**

Run: `cd ipms-backend && php artisan test --filter ApiResponseTest`

Expected: FAIL with `Class "App\Support\ApiResponse" not found`.

- [ ] **Step 3: Implement the response helper**

```php
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
```

- [ ] **Step 4: Write the project pagination contract test**

```php
public function test_project_index_uses_page_size_contract(): void
{
    $user = User::factory()->superAdmin()->create();
    Project::factory()->count(3)->create();

    $this->actingAs($user)
        ->getJson('/api/projects?page=1&page_size=2')
        ->assertOk()
        ->assertJsonStructure(['code', 'message', 'data' => ['items', 'page', 'page_size', 'total', 'total_pages']])
        ->assertJsonPath('data.page_size', 2);
}
```

Run: `cd ipms-backend && php artisan test --filter ProjectIndexContractTest`

Expected: FAIL because the controller still reads and returns `per_page`.

- [ ] **Step 5: Write the core empty-list pagination contract**

Act as a superadmin and test `/api/projects`, `/api/requirements`, `/api/tasks`, `/api/defects`, `/api/users`, `/api/audit-logs`, and `/api/notification-logs` with `page=1&page_size=2`. Each must return `data.items`, `data.page`, `data.page_size`, `data.total`, and `data.total_pages`, with `data.page_size=2`. Add a separate scoped test for `/api/projects/{projectId}/api-docs`.

Run: `cd ipms-backend && php artisan test --filter CorePaginationContractTest`

Expected: FAIL because the current controllers read and return `per_page`.

- [ ] **Step 6: Update paginated controllers and API exception rendering**

In `ProjectController::index()`, replace `per_page` with:

```php
$pageSize = min(max($request->integer('page_size', 20), 1), 100);
$paginator = $query->orderBy('updated_at', 'desc')->paginate($pageSize);

return ApiResponse::paginated($paginator);
```

Apply the same bounded `page_size` input and `ApiResponse::paginated()` output to requirement, task, defect, user, audit-log, notification-log, and project API-document lists. Standardize free-text input on `keyword`; remove controller reads of `search` for these core lists. Keep all existing data scopes and ordering.

In `bootstrap/app.php`, render validation failures with `ApiResponse::error('VALIDATION_FAILED', ..., 422, $exception->errors())` and authentication failures with `UNAUTHENTICATED`. Keep framework 404 and 500 messages free of stack traces outside local/testing environments.

- [ ] **Step 7: Run the helper and contract tests green**

Run: `cd ipms-backend && php artisan test --filter 'ApiResponseTest|ProjectIndexContractTest|CorePaginationContractTest'`

Expected: PASS.

- [ ] **Step 8: Commit the response contract**

```bash
git add ipms-backend/app/Support ipms-backend/app/Http/Controllers/Api ipms-backend/bootstrap/app.php ipms-backend/tests
git commit -m "feat: standardize API response contracts"
```

### Task 3: Same-Origin Sanctum Authentication

**Files:**
- Create: `ipms-backend/tests/Feature/Api/AuthTest.php`
- Create: `ipms-backend/database/migrations/2026_08_17_000025_add_unique_nonempty_user_email.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/AuthController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/UserController.php`
- Modify: `ipms-frontend/src/api/auth.js`
- Modify: `ipms-frontend/src/api/index.js`
- Modify: `ipms-frontend/src/stores/auth.js`

**Interfaces:**
- Consumes: `ApiResponse::success()` and session-cookie Sanctum middleware.
- Produces: login response `data.user`, `data.must_change_password`, and Pinia state with no persisted bearer token.

- [ ] **Step 1: Write backend login and disabled-account tests**

```php
public function test_active_user_can_log_in_with_email(): void
{
    $user = User::factory()->create(['email' => 'pm@example.test', 'password' => 'Secret123']);

    $this->postJson('/api/login', ['username' => 'pm@example.test', 'password' => 'Secret123'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.must_change_password', false);
}

public function test_active_user_can_log_in_with_username(): void
{
    $user = User::factory()->create(['username' => 'it.pm', 'password' => 'Secret123']);

    $this->postJson('/api/login', ['username' => 'it.pm', 'password' => 'Secret123'])
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id);
}

public function test_disabled_user_cannot_log_in(): void
{
    User::factory()->create(['email' => 'disabled@example.test', 'password' => 'Secret123', 'is_disabled' => true]);

    $this->postJson('/api/login', ['username' => 'disabled@example.test', 'password' => 'Secret123'])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'ACCOUNT_DISABLED');
}
```

Add a database test that non-empty email addresses are unique case-insensitively while multiple legacy empty strings remain allowed. Add a test that a user with `must_change_password=true` receives that flag at login, updates their password through `PUT /api/settings/password`, receives `must_change_password=false`, and can authenticate with the new password but not the old one.

- [ ] **Step 2: Run auth tests red**

Run: `cd ipms-backend && php artisan test --filter AuthTest`

Expected: FAIL because the current response is not wrapped in `data` and disabled errors lack `error_code`.

- [ ] **Step 3: Complete the authentication response shape**

Create a PostgreSQL partial unique index on `LOWER(email)` where `email <> ''`. Normalize non-empty email input to lowercase in user creation and profile updates, and return a field validation error before a database collision. Use the foundational `UserFactory` from Task 1. Resolve the submitted login identifier case-insensitively as email when it validates as an email address and as `username` otherwise; verify the password with `Hash::check()` and establish the session with `Auth::login()`. Update `AuthController` to return:

```php
return ApiResponse::success([
    'user' => $this->formatUser($user),
    'must_change_password' => $user->must_change_password,
], '登录成功');
```

Return `ApiResponse::error('ACCOUNT_DISABLED', ...)` and `ACCOUNT_INACTIVE` for blocked accounts. Wrap `/api/user` in `data.user` and logout in the standard success envelope. A successful password update sets `must_change_password=false` in the same write.

- [ ] **Step 4: Run backend auth tests green**

Run: `cd ipms-backend && php artisan test --filter AuthTest`

Expected: PASS.

- [ ] **Step 5: Correct frontend CSRF and Pinia behavior**

Use root-relative Axios for CSRF, then the API client for login:

```js
import axios from 'axios'
import request from './index'

export async function login(credentials) {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
  return request.post('/login', credentials)
}
```

Keep `withCredentials: true` on the API client and remove the request interceptor that reads a meta CSRF token or adds a Bearer token. Axios sends Laravel's same-origin `XSRF-TOKEN` cookie as `X-XSRF-TOKEN`. Remove the duplicate `getCsrfCookie()` and password functions from `api/auth.js`; password changes use `api/user.js` at `/settings/password`. The 401 response path must not read or clear `localStorage`.

In `stores/auth.js`, remove `token`, `localStorage`, and Bearer handling. Parse login with:

```js
const { user: userData } = response.data.data
user.value = userData
```

Parse fetch-user with `response.data.data.user`. Logout clears only in-memory user state.

Use these exact getters:

```js
const roles = computed(() => user.value?.roles ?? [])
const currentRole = computed(() => roles.value.includes('super_admin') ? 'super_admin' : roles.value[0] ?? 'guest')
const userName = computed(() => user.value?.display_name || user.value?.username || '')
const userType = computed(() => user.value?.user_type ?? null)
const isSuperAdmin = computed(() => user.value?.is_super_admin === true || roles.value.includes('super_admin'))
const mustChangePassword = computed(() => user.value?.must_change_password === true)
```

Expose `hasRole(code)` and retain permission checks against `permissions[]`.

- [ ] **Step 6: Commit the authentication contract**

```bash
git add ipms-backend/app/Http/Controllers/Api/AuthController.php ipms-backend/app/Http/Controllers/Api/UserController.php ipms-backend/database/migrations/2026_08_17_000025_add_unique_nonempty_user_email.php ipms-backend/tests/Feature/Api/AuthTest.php ipms-frontend/src/api ipms-frontend/src/stores/auth.js
git commit -m "fix: align Sanctum session authentication"
```

### Task 4: Frontend Test Harness and Auth Store Tests

**Files:**
- Modify: `ipms-frontend/package.json`
- Create: `ipms-frontend/package-lock.json`
- Create: `ipms-frontend/vitest.config.js`
- Create: `ipms-frontend/src/test/setup.js`
- Create: `ipms-frontend/src/stores/auth.test.js`

**Interfaces:**
- Consumes: Pinia `useAuthStore()` and mocked functions from `@/api/auth`.
- Produces: `npm test` and a DOM-capable Vitest environment for all frontend phases.

- [ ] **Step 1: Add a failing auth store test**

```js
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from './auth'

vi.mock('@/api/auth', () => ({
  login: vi.fn().mockResolvedValue({ data: { data: { user: { id: 1, display_name: '张三', roles: ['it_pm'] } } } }),
  logout: vi.fn().mockResolvedValue({ data: { data: null } }),
  fetchUser: vi.fn(),
}))

describe('auth store', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('stores the session user without localStorage token data', async () => {
    localStorage.clear()
    const store = useAuthStore()
    await store.login({ username: 'pm@example.test', password: 'Secret123' })
    expect(store.userName).toBe('张三')
    expect(localStorage.getItem('ipms_token')).toBeNull()
  })
})
```

- [ ] **Step 2: Add test dependencies and verify the test fails before configuration**

Add scripts `"test": "vitest run"` and `"test:watch": "vitest"`. Install and lock dev dependencies with:

Run: `cd ipms-frontend && npm install --save-dev vitest@^1.6.0 jsdom@^24.1.3 @vue/test-utils@^2.4.6 @pinia/testing@^0.1.7`

Run: `cd ipms-frontend && npm test -- src/stores/auth.test.js`

Expected: FAIL because alias and DOM setup are not configured.

- [ ] **Step 3: Add Vitest alias and DOM setup**

```js
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig({
  plugins: [vue()],
  resolve: { alias: { '@': path.resolve(__dirname, 'src') } },
  test: { environment: 'jsdom', setupFiles: ['./src/test/setup.js'], globals: true },
})
```

```js
import { afterEach } from 'vitest'

afterEach(() => {
  document.body.innerHTML = ''
})
```

- [ ] **Step 4: Run the auth store test green**

Run: `cd ipms-frontend && npm test -- src/stores/auth.test.js`

Expected: PASS.

- [ ] **Step 5: Commit the frontend harness**

```bash
git add ipms-frontend/package.json ipms-frontend/package-lock.json ipms-frontend/vitest.config.js ipms-frontend/src/test ipms-frontend/src/stores/auth.test.js
git commit -m "test: add Vue frontend test harness"
```

### Task 5: Correct Core Frontend API Action Methods

**Files:**
- Create: `ipms-frontend/src/api/contracts.test.js`
- Modify: `ipms-frontend/src/api/project.js`
- Modify: `ipms-frontend/src/api/requirement.js`
- Modify: `ipms-frontend/src/api/task.js`
- Modify: `ipms-frontend/src/api/defect.js`
- Modify: `ipms-frontend/src/api/notification.js`
- Modify: `ipms-frontend/src/api/user.js`
- Modify: `ipms-frontend/src/api/document.js`
- Modify: `ipms-frontend/src/api/apiDocument.js`
- Modify: `ipms-frontend/src/api/dashboard.js`

**Interfaces:**
- Consumes: routes in `ipms-backend/routes/api.php`.
- Produces: frontend action calls that exactly match backend paths and HTTP methods.

- [ ] **Step 1: Write exact method/path tests**

```js
import { beforeEach, expect, it, vi } from 'vitest'
import request from './index'
import { archiveProject } from './project'
import { reviewRequirement, transitionRequirement } from './requirement'
import { claimTask, transitionTask } from './task'
import { confirmDefect } from './defect'
import { listDocuments, uploadDocument } from './document'
import { listApiDocuments, exportApiDocument } from './apiDocument'
import { getNotificationConfig, listNotificationLogs } from './notification'
import { disableUser, updateProfile, changePassword } from './user'

vi.mock('./index', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() } }))

beforeEach(() => vi.clearAllMocks())

it('uses backend action routes', () => {
  archiveProject(4)
  reviewRequirement(5, { action: 'approve' })
  transitionRequirement(5, { status: 3 })
  claimTask(6)
  transitionTask(6, { status: 2 })
  confirmDefect(7, {})
  listDocuments(8, { page: 1 })
  uploadDocument(8, new FormData())
  listApiDocuments(8, { page: 1 })
  exportApiDocument(9, 'json')
  getNotificationConfig()
  listNotificationLogs({ page: 1 })
  disableUser(10)
  updateProfile({ display_name: '张三' })
  changePassword({ current_password: 'old', new_password: 'new-password', new_password_confirmation: 'new-password' })

  expect(request.post).toHaveBeenCalledWith('/projects/4/archive')
  expect(request.post).toHaveBeenCalledWith('/requirements/5/review', { action: 'approve' })
  expect(request.post).toHaveBeenCalledWith('/requirements/5/status', { status: 3 })
  expect(request.post).toHaveBeenCalledWith('/tasks/6/claim')
  expect(request.post).toHaveBeenCalledWith('/tasks/6/status', { status: 2 })
  expect(request.post).toHaveBeenCalledWith('/defects/7/confirm', {})
  expect(request.get).toHaveBeenCalledWith('/projects/8/documents', { params: { page: 1 } })
  expect(request.post).toHaveBeenCalledWith('/projects/8/documents/upload', expect.any(FormData), expect.any(Object))
  expect(request.get).toHaveBeenCalledWith('/projects/8/api-docs', { params: { page: 1 } })
  expect(request.post).toHaveBeenCalledWith('/api-docs/9/export', { format: 'json' })
  expect(request.get).toHaveBeenCalledWith('/notification-configs')
  expect(request.get).toHaveBeenCalledWith('/notification-logs', { params: { page: 1 } })
  expect(request.post).toHaveBeenCalledWith('/users/10/disable')
  expect(request.put).toHaveBeenCalledWith('/settings/profile', { display_name: '张三' })
  expect(request.put).toHaveBeenCalledWith('/settings/password', expect.any(Object))
})
```

- [ ] **Step 2: Run the contract test red**

Run: `cd ipms-frontend && npm test -- src/api/contracts.test.js`

Expected: FAIL showing current `PUT` calls where backend routes require `POST`.

- [ ] **Step 3: Change action methods and remove unsupported methods**

Use `POST` for project archive, user disable, requirement review/status, task claim/status/hold, defect confirm/assign/resolve/verify/reopen, and API-document export. Use `/notification-configs`, `/notification-logs`, `/settings/profile`, and `/settings/password`.

Document methods require `projectId` and use `/projects/{projectId}/documents`, `/upload`, and `/folder`; retain only standalone download and delete. API-document list/create require `projectId` and use `/projects/{projectId}/api-docs`; detail/update/version/export use `/api-docs/{id}`.

Remove `checkProjectDeletable`, requirement child GET methods that lack routes, document tree/trash/restore/force-delete, `enableUser`, duplicate profile GET, unread notification methods, and test-email methods until a later phase introduces tested routes. Remove dashboard activity and project-overview methods; Phase 3 replaces the remaining dashboard calls with `/dashboard/summary`.

- [ ] **Step 4: Run API contract and full frontend tests green**

Run: `cd ipms-frontend && npm test`

Expected: PASS with no unhandled promise warnings.

- [ ] **Step 5: Commit API contract corrections**

```bash
git add ipms-frontend/src/api
git commit -m "fix: align frontend core API actions"
```

### Task 6: Deterministic Multi-Role Demo Seed

**Files:**
- Create: `ipms-backend/config/ipms.php`
- Create: `ipms-backend/database/seeders/DemoWorkflowSeeder.php`
- Create: `ipms-backend/tests/Feature/Seeders/DemoWorkflowSeederTest.php`
- Modify: `ipms-backend/database/seeders/DatabaseSeeder.php`
- Modify: `ipms-backend/database/seeders/AdminUserSeeder.php`

**Interfaces:**
- Consumes: `IPMS_SEED_DEMO` and `IPMS_DEMO_PASSWORD` environment variables.
- Produces: one account for each approved role, three organizations, two projects, and project membership records without tracked passwords.

- [ ] **Step 1: Write a seeder safety test**

```php
public function test_demo_seeder_requires_an_environment_password(): void
{
    config()->set('ipms.demo_password', null);

    $this->expectException(LogicException::class);
    $this->seed(DemoWorkflowSeeder::class);
}

public function test_demo_seeder_creates_all_role_codes(): void
{
    config()->set('ipms.demo_password', 'DemoSecret123');
    $this->seed([PermissionSeeder::class, RoleSeeder::class, RolePermissionSeeder::class, OrganizationSeeder::class, DemoWorkflowSeeder::class]);

    $this->assertDatabaseHas('role_user', ['role_id' => Role::where('code', 'it_pm')->value('id')]);
    $this->assertDatabaseHas('role_user', ['role_id' => Role::where('code', 'supplier_dev')->value('id')]);
    $this->assertDatabaseHas('role_user', ['role_id' => Role::where('code', 'requester')->value('id')]);
}
```

- [ ] **Step 2: Run the seeder tests red**

Run: `cd ipms-backend && php artisan test --filter DemoWorkflowSeederTest`

Expected: FAIL because the config and seeder do not exist.

- [ ] **Step 3: Implement environment-gated seed configuration**

```php
<?php

return [
    'seed_demo' => (bool) env('IPMS_SEED_DEMO', false),
    'demo_password' => env('IPMS_DEMO_PASSWORD'),
];
```

`DemoWorkflowSeeder` must throw `LogicException` when the password is absent, create users with `Hash::make(config('ipms.demo_password'))`, set `must_change_password=false` only for these environment-gated demo users, assign exact role codes, create internal/supplier/system-user organizations, create two projects, and assign internal and supplier project memberships. Use `updateOrInsert` so rerunning the seeder is deterministic.

Call the demo seeder from `DatabaseSeeder` only when `config('ipms.seed_demo')` is true. Change `AdminUserSeeder` to require the same environment password instead of hardcoding `Admin@123456`.

- [ ] **Step 4: Run all Phase 1 tests**

Run: `cd ipms-backend && php artisan test`

Expected: PASS.

Run: `cd ipms-frontend && npm test && npm run build`

Expected: all tests PASS and Vite build exits 0.

- [ ] **Step 5: Commit deterministic seed data**

```bash
git add ipms-backend/config/ipms.php ipms-backend/database/seeders ipms-backend/tests/Feature/Seeders
git commit -m "feat: add safe multi-role demo seed"
```

## Phase 1 Completion Gate

Run:

```bash
cd ipms-backend && php artisan test
cd ../ipms-frontend && npm test && npm run build
```

Expected: backend and frontend tests pass, Vite production build succeeds, login uses session cookies, and no tracked file contains demo or production passwords.
