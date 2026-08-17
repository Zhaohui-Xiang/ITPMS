# IPMS Core Workflow Backend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement project-side requirement delivery states, project release versions, release gates, atomic release, permissions, history, snapshots, and tested Laravel APIs.

**Architecture:** Model the requirement-project association as an explicit Eloquent pivot entity. Put state aggregation, version planning, gate checks, and release transactions in focused domain services; controllers validate and authorize before delegating.

**Tech Stack:** PHP 8.2, Laravel 11, Sanctum 4, PHPUnit, PostgreSQL 15, Redis

## Global Constraints

- Phase 1 tests and production build must pass before starting this plan.
- Preserve existing numeric requirement, task, and defect statuses.
- A project version belongs to one project and has a project-local unique `code`.
- A requirement-project association has zero or one target project version.
- Only the internal IT manager of that project can create, edit, transition, or release a version; only a super administrator can force release.
- Use database transactions and row locks for planning and release writes.
- Database-backed feature tests use `RefreshDatabase` and deterministic factories from Task 1. Tests that must observe `DB::afterCommit()` use `DatabaseMigrations` so the outer test transaction does not defer callbacks past the assertion.
- A released or archived version is immutable.
- Do not send email inside a database transaction; emit domain events after commit.
- Do not initialize Git without approval. Commit steps assume the repository prerequisite has been satisfied.

---

### Task 1: Project Version Schema, Enums, and Models

**Files:**
- Create: `ipms-backend/app/Enums/ProjectVersionStatus.php`
- Create: `ipms-backend/app/Enums/ProjectDeliveryStatus.php`
- Create: `ipms-backend/app/Models/ProjectVersion.php`
- Create: `ipms-backend/app/Models/RequirementProject.php`
- Create: `ipms-backend/app/Models/ProjectVersionHistory.php`
- Create: `ipms-backend/app/Models/ProjectVersionReleaseSnapshot.php`
- Create: `ipms-backend/database/migrations/2026_08_17_000026_create_project_versions_table.php`
- Create: `ipms-backend/database/migrations/2026_08_17_000027_add_delivery_fields_to_requirement_project_table.php`
- Create: `ipms-backend/database/migrations/2026_08_17_000028_create_project_version_histories_table.php`
- Create: `ipms-backend/database/migrations/2026_08_17_000029_create_project_version_release_snapshots_table.php`
- Modify: `ipms-backend/database/factories/ProjectFactory.php`
- Create: `ipms-backend/database/factories/RequirementFactory.php`
- Create: `ipms-backend/database/factories/RequirementProjectFactory.php`
- Create: `ipms-backend/database/factories/ProjectVersionFactory.php`
- Create: `ipms-backend/database/factories/TaskFactory.php`
- Create: `ipms-backend/database/factories/DefectFactory.php`
- Create: `ipms-backend/tests/Feature/Domain/ProjectVersionSchemaTest.php`
- Modify: `ipms-backend/app/Models/Project.php`
- Modify: `ipms-backend/app/Models/Requirement.php`
- Modify: `ipms-backend/app/Models/Task.php`
- Modify: `ipms-backend/app/Models/Defect.php`

**Interfaces:**
- Consumes: existing `projects`, `requirements`, `requirement_project`, and `users` tables.
- Produces: exact model relationships and enum values consumed by every later task in this plan.

- [ ] **Step 1: Write schema and relationship tests**

```php
public function test_project_version_code_is_unique_within_project_only(): void
{
    $first = Project::factory()->create();
    $second = Project::factory()->create();

    ProjectVersion::factory()->for($first)->create(['code' => 'v2.3']);
    ProjectVersion::factory()->for($second)->create(['code' => 'v2.3']);

    $this->expectException(QueryException::class);
    ProjectVersion::factory()->for($first)->create(['code' => 'v2.3']);
}

public function test_requirement_project_has_one_optional_target_version(): void
{
    $version = ProjectVersion::factory()->create();
    $requirement = Requirement::factory()->create();
    $link = RequirementProject::create([
        'requirement_id' => $requirement->id,
        'project_id' => $version->project_id,
        'project_version_id' => $version->id,
        'delivery_status' => ProjectDeliveryStatus::ASSIGNED,
    ]);

    $this->assertTrue($link->projectVersion->is($version));
}
```

- [ ] **Step 2: Run schema tests red**

Run: `cd ipms-backend && php artisan test --filter ProjectVersionSchemaTest`

Expected: FAIL because project version classes and tables do not exist.

- [ ] **Step 3: Define exact enum values**

```php
enum ProjectVersionStatus: int
{
    case DRAFT = 1;
    case PLANNED = 2;
    case IN_DEVELOPMENT = 3;
    case IN_TESTING = 4;
    case READY_TO_RELEASE = 5;
    case RELEASED = 6;
    case ARCHIVED = 7;
}
```

```php
enum ProjectDeliveryStatus: int
{
    case ASSIGNED = 2;
    case IN_DEVELOPMENT = 3;
    case IN_TESTING = 4;
    case PENDING_DEPLOY = 5;
    case DEPLOYED = 6;
    case ACCEPTED = 7;
}
```

`ProjectVersionStatus::allowedTransitions()` must return this exact matrix: DRAFT to PLANNED; PLANNED to DRAFT or IN_DEVELOPMENT; IN_DEVELOPMENT to PLANNED or IN_TESTING; IN_TESTING to IN_DEVELOPMENT or READY_TO_RELEASE; READY_TO_RELEASE to IN_TESTING or RELEASED; RELEASED to ARCHIVED; ARCHIVED to none. `ProjectDeliveryStatus::allowedForwardTransitions()` permits only the next numeric state. Both enums provide a Chinese `label()` method.

- [ ] **Step 4: Create PostgreSQL migrations**

`project_versions` must contain `project_id`, `code`, `name`, nullable `description`, `status`, `owner_id`, nullable `planned_start_date`, `planned_release_date`, nullable `released_at`, nullable `released_by_id`, nullable `release_notes`, `lock_version` default 1, `created_by_id`, and timestamps. Add unique indexes on `['project_id', 'code']` and `['id', 'project_id']`. Use `smallInteger` for status and PostgreSQL check constraint `status IN (1,2,3,4,5,6,7)`.

Add nullable `project_version_id`, `delivery_status` default 2, nullable `version_assigned_by_id`, nullable `version_assigned_at`, and indexes to `requirement_project`. Enforce the project match in PostgreSQL with composite foreign key `['project_version_id', 'project_id']` referencing `project_versions['id', 'project_id']`; service validation returns a domain error before that database constraint is reached.

History rows must contain `event_type`, nullable from/to statuses, actor, reason, JSONB metadata, and timestamp. Snapshot rows must have one unique `project_version_id`, JSONB requirement scope, task/defect counts, JSONB gate result, release notes, override fields, actor, and timestamp.

- [ ] **Step 5: Implement model relations and casts**

`Project::versions()` returns `HasMany`. `Requirement::projectLinks()` returns `HasMany`. Both `Project::requirements()` and `Requirement::projects()` use `RequirementProject::class` via `->using()` and expose `project_version_id`, `delivery_status`, assignment actor/time. Do not call `withTimestamps()` on these relations because the pivot table has `created_at` but no `updated_at`.

`ProjectVersion` casts `status` to `ProjectVersionStatus`, dates to date/datetime, and owns `requirements` through `RequirementProject`, `histories`, and `releaseSnapshot`.

`RequirementProject` extends `Illuminate\Database\Eloquent\Relations\Pivot`, sets `$table = 'requirement_project'`, `$incrementing = true`, and `$timestamps = false`, and casts `delivery_status` plus assignment time. `ProjectVersionHistory` and `ProjectVersionReleaseSnapshot` also set `$timestamps = false` because their tables use explicit event/release timestamps rather than Laravel's updated timestamp.

Add `HasFactory` to `Requirement`, `Task`, and `Defect`; all newly created factory-backed models also use `HasFactory`.

Implement these exact factory helpers used by later tests:

```text
RequirementFactory::withProjects(int $count)
RequirementProjectFactory::forVersion(ProjectVersion $version)
ProjectVersionFactory::inTesting()
ProjectVersionFactory::ready()
ProjectVersionFactory::withPassingScope()
TaskFactory::forVersionScope(ProjectVersion $version)
DefectFactory::forVersionScope(ProjectVersion $version)
```

`withProjects()` creates distinct project links after the requirement is created. `forVersion()` binds both foreign keys to the version's project. Task and defect scope helpers create or reuse a requirement link for that version and copy its requirement and project IDs. `withPassingScope()` creates one PENDING_DEPLOY link, one completed task, and one closed serious defect. Extend the Phase 1 `ProjectFactory::withManager()` helper rather than replacing it.

- [ ] **Step 6: Run migrations and schema tests green**

Run: `cd ipms-backend && php artisan migrate:fresh --env=testing && php artisan test --filter ProjectVersionSchemaTest`

Expected: PASS.

- [ ] **Step 7: Commit the domain schema**

```bash
git add ipms-backend/app/Enums ipms-backend/app/Models ipms-backend/database/migrations ipms-backend/database/factories ipms-backend/tests/Feature/Domain/ProjectVersionSchemaTest.php
git commit -m "feat: add project release version domain model"
```

### Task 2: Project Version Permissions and Policy

**Files:**
- Create: `ipms-backend/app/Policies/ProjectVersionPolicy.php`
- Create: `ipms-backend/tests/Feature/Policies/ProjectVersionPolicyTest.php`
- Modify: `ipms-backend/app/Providers/AuthServiceProvider.php`
- Modify: `ipms-backend/database/seeders/PermissionSeeder.php`
- Modify: `ipms-backend/database/seeders/RolePermissionSeeder.php`

**Interfaces:**
- Consumes: `ProjectVersion`, `Project::manager_id`, `User::isSuperAdmin()`, and role permission codes.
- Produces: policy methods `view`, `create`, `update`, `transition`, `release`, `forceRelease`, and `delete`.

- [ ] **Step 1: Write policy matrix tests**

```php
public function test_only_project_it_manager_can_transition_and_release(): void
{
    $manager = User::factory()->internal()->create();
    $otherManager = User::factory()->internal()->create();
    $supplier = User::factory()->supplier()->create();
    $version = ProjectVersion::factory()->for(Project::factory()->state(['manager_id' => $manager->id]))->create();

    $this->assertTrue($manager->can('transition', $version));
    $this->assertTrue($manager->can('release', $version));
    $this->assertFalse($otherManager->can('transition', $version));
    $this->assertFalse($supplier->can('release', $version));
}

public function test_only_super_admin_can_force_release(): void
{
    $version = ProjectVersion::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $this->assertTrue($superAdmin->can('forceRelease', $version));
    $this->assertFalse($superAdmin->can('transition', $version));
    $this->assertFalse($superAdmin->can('release', $version));
    $this->assertFalse(User::factory()->internal()->create()->can('forceRelease', $version));
}
```

- [ ] **Step 2: Run policy tests red**

Run: `cd ipms-backend && php artisan test --filter ProjectVersionPolicyTest`

Expected: FAIL because the policy is not registered.

- [ ] **Step 3: Add permissions and policy**

Add permission codes `project_version.view`, `project_version.create`, `project_version.edit`, `project_version.transition`, `project_version.release`, and `project_version.override`. Assign view to project participants, create/edit/transition/release to `it_pm`, and all codes to `super_admin`. Do not assign transition or release to supplier roles.

Policy rule for create/update/transition/release:

```php
return $user->user_type === UserType::INTERNAL->value
    && $version->project->manager_id === $user->id;
```

For `create(User $user, Project $project)`, apply the same rule against `$project->manager_id`. `view` allows superadmin or users inside the existing project data scope. `forceRelease` returns only `isSuperAdmin()`. Superadmin does not receive normal create, update, transition, release, or delete permission. `delete` requires the assigned internal manager, DRAFT status, zero requirement links, and zero histories.

- [ ] **Step 4: Run policy tests green**

Run: `cd ipms-backend && php artisan test --filter ProjectVersionPolicyTest`

Expected: PASS.

- [ ] **Step 5: Commit permissions**

```bash
git add ipms-backend/app/Policies/ProjectVersionPolicy.php ipms-backend/app/Providers/AuthServiceProvider.php ipms-backend/database/seeders ipms-backend/tests/Feature/Policies
git commit -m "feat: enforce project version permissions"
```

### Task 3: Project-Side Requirement Workflow and Aggregate Status

**Files:**
- Create: `ipms-backend/app/Services/RequirementWorkflowService.php`
- Create: `ipms-backend/tests/Feature/Services/RequirementWorkflowServiceTest.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/RequirementController.php`
- Modify: `ipms-backend/app/Models/Requirement.php`

**Interfaces:**
- Consumes: `Requirement`, `RequirementProject`, `ProjectDeliveryStatus`, and `RequirementStatus`.
- Produces: `submit()`, `review()`, `resubmit()`, `initializeApprovedProjects()`, `transitionProjectDelivery()`, and `recalculateAggregateStatus()`.

- [ ] **Step 1: Write cross-project aggregation tests**

```php
public function test_global_status_is_lowest_project_delivery_progress(): void
{
    $requirement = Requirement::factory()->create(['status' => RequirementStatus::ASSIGNED]);
    $first = RequirementProject::factory()->for($requirement)->create(['delivery_status' => ProjectDeliveryStatus::DEPLOYED]);
    $second = RequirementProject::factory()->for($requirement)->create(['delivery_status' => ProjectDeliveryStatus::IN_DEVELOPMENT]);

    app(RequirementWorkflowService::class)->recalculateAggregateStatus($requirement);

    $this->assertSame(RequirementStatus::IN_DEVELOPMENT->value, $requirement->refresh()->status);
}

public function test_approval_initializes_each_project_side_as_assigned(): void
{
    $requirement = Requirement::factory()->withProjects(2)->create(['status' => RequirementStatus::PENDING_REVIEW]);

    app(RequirementWorkflowService::class)->initializeApprovedProjects($requirement, User::factory()->internal()->create());

    $this->assertSame([2, 2], $requirement->projectLinks()->orderBy('id')->pluck('delivery_status')->all());
}
```

Add tests that rejection keeps the requirement at PENDING_REVIEW while saving reviewer, review time, and comment; only the original requester may edit and resubmit; resubmission increments `requirements.version`, creates one `requirement_versions` snapshot, clears the prior review decision fields, and makes the record reviewable again. Approval and resubmission must roll back completely if any project-side write fails.

- [ ] **Step 2: Run workflow tests red**

Run: `cd ipms-backend && php artisan test --filter RequirementWorkflowServiceTest`

Expected: FAIL because the service does not exist.

- [ ] **Step 3: Implement project-side transitions and aggregation**

```php
public function recalculateAggregateStatus(Requirement $requirement): RequirementStatus
{
    if ($requirement->status === RequirementStatus::PENDING_REVIEW->value) {
        return RequirementStatus::PENDING_REVIEW;
    }

    $minimum = $requirement->projectLinks()->min('delivery_status');
    $status = RequirementStatus::from((int) $minimum);
    $requirement->update(['status' => $status->value]);

    return $status;
}
```

`submit()`, `review()`, and `resubmit()` own requirement writes, project links, reviewer fields, and requirement revision snapshots rather than duplicating these writes in the controller. `transitionProjectDelivery()` must lock the pivot row, validate adjacent delivery transitions, write a version history event when a target version exists, update the pivot, and recalculate the aggregate in one transaction.

- [ ] **Step 4: Integrate approval and status actions**

Delegate submit, approve, reject, and resubmit actions to the service. Replace the global status transition endpoint with a project-scoped action that accepts `project_id` and delegates to `transitionProjectDelivery()`. Keep global status read-only in API payloads.

- [ ] **Step 5: Run requirement tests green**

Run: `cd ipms-backend && php artisan test --filter 'RequirementWorkflowServiceTest|Requirement'`

Expected: PASS.

- [ ] **Step 6: Commit project-side workflow**

```bash
git add ipms-backend/app/Services/RequirementWorkflowService.php ipms-backend/app/Http/Controllers/Api/RequirementController.php ipms-backend/app/Models/Requirement.php ipms-backend/tests/Feature/Services
git commit -m "feat: track project-side requirement delivery"
```

### Task 4: Project Version Planning Service

**Files:**
- Create: `ipms-backend/app/Exceptions/DomainConflictException.php`
- Create: `ipms-backend/app/Services/ProjectVersionService.php`
- Create: `ipms-backend/tests/Feature/Services/ProjectVersionServiceTest.php`
- Modify: `ipms-backend/bootstrap/app.php`

**Interfaces:**
- Consumes: `ProjectVersion`, `RequirementProject`, policy authorization completed by caller, and expected `lock_version`.
- Produces: the exact service methods below.

```php
create(Project $project, array $data, User $actor): ProjectVersion
update(ProjectVersion $version, array $data, int $expectedLock, User $actor): ProjectVersion
transition(ProjectVersion $version, ProjectVersionStatus $target, int $expectedLock, User $actor, ?string $reason = null): ProjectVersion
assignRequirement(ProjectVersion $version, RequirementProject $link, int $expectedLock, User $actor, ?string $reason = null): ProjectVersion
unassignRequirement(ProjectVersion $version, RequirementProject $link, int $expectedLock, User $actor, ?string $reason = null): ProjectVersion
deleteDraft(ProjectVersion $version, int $expectedLock, User $actor): void
```

- [ ] **Step 1: Write planning and lock tests**

```php
public function test_requirement_can_only_be_assigned_to_version_of_same_project(): void
{
    $link = RequirementProject::factory()->create();
    $foreignVersion = ProjectVersion::factory()->create();
    $actor = User::factory()->internal()->create();

    $this->expectExceptionObject(new DomainConflictException('VERSION_PROJECT_MISMATCH', 409));
    app(ProjectVersionService::class)->assignRequirement($foreignVersion, $link, 1, $actor);
}

public function test_stale_lock_version_is_rejected(): void
{
    $version = ProjectVersion::factory()->create(['lock_version' => 3]);
    $actor = User::factory()->internal()->create();

    $this->expectExceptionObject(new DomainConflictException('STALE_VERSION', 409));
    app(ProjectVersionService::class)->update($version, ['name' => 'Changed'], 2, $actor);
}
```

- [ ] **Step 2: Run planning tests red**

Run: `cd ipms-backend && php artisan test --filter ProjectVersionServiceTest`

Expected: FAIL because service and exception classes do not exist.

- [ ] **Step 3: Implement lock-safe CRUD and scope rules**

Use `DB::transaction()` and `lockForUpdate()`. Every successful update increments `lock_version`. `assignRequirement()` validates matching `project_id`, rejects READY_TO_RELEASE/RELEASED/ARCHIVED versions, requires a reason in IN_TESTING, and records `requirement_added`, `requirement_moved`, or `requirement_removed` history metadata with old/new version IDs.

`transition()` validates `ProjectVersionStatus::allowedTransitions()`, requires a reason for rollback, and records one `status_forward` or `status_rollback` history row. It rejects target RELEASED with `RELEASE_ACTION_REQUIRED`; only `ProjectReleaseService` may create a released state and snapshot. Gate checks are added in Task 5.

Implement the shared exception with a stable constructor used by every service and the exception renderer:

```php
final class DomainConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status = 409,
        public readonly array $errors = [],
        ?string $message = null,
    ) {
        parent::__construct($message ?? $errorCode);
    }
}
```

Register one `bootstrap/app.php` renderer that maps this exception through `ApiResponse::error($exception->errorCode, $exception->getMessage(), $exception->status, $exception->errors)`. Do not catch it separately in controllers.

Use these exact domain errors throughout the version and release services:

```text
VERSION_CODE_EXISTS         422 code field error
FORCE_REASON_REQUIRED       422 reason field error
INVALID_VERSION_TRANSITION  409 current and requested statuses
RELEASE_ACTION_REQUIRED     409 requested status
RELEASE_GATE_FAILED         409 complete blocking checks
STALE_VERSION               409 current lock_version
VERSION_LOCKED              409 current immutable status
VERSION_PROJECT_MISMATCH    409 project IDs
```

- [ ] **Step 4: Run planning tests green**

Run: `cd ipms-backend && php artisan test --filter ProjectVersionServiceTest`

Expected: PASS.

- [ ] **Step 5: Commit planning service**

```bash
git add ipms-backend/app/Exceptions ipms-backend/app/Services/ProjectVersionService.php ipms-backend/tests/Feature/Services/ProjectVersionServiceTest.php
git commit -m "feat: add lock-safe release planning"
```

### Task 5: Release Gate Service

**Files:**
- Create: `ipms-backend/app/ValueObjects/ReleaseGateResult.php`
- Create: `ipms-backend/app/Services/ReleaseGateService.php`
- Create: `ipms-backend/tests/Feature/Services/ReleaseGateServiceTest.php`

**Interfaces:**
- Consumes: a loaded `ProjectVersion` and target `ProjectVersionStatus`.
- Produces: `ReleaseGateResult { bool $passed; array $checks; array $blocking; }` serialized by gate-check and release APIs.

- [ ] **Step 1: Write one test for every approved blocking rule**

```php
public function test_ready_gate_reports_all_blockers(): void
{
    $version = ProjectVersion::factory()->inTesting()->create(['release_notes' => null]);
    RequirementProject::factory()->forVersion($version)->create(['delivery_status' => ProjectDeliveryStatus::IN_TESTING]);
    Task::factory()->forVersionScope($version)->create(['status' => TaskStatus::IN_PROGRESS]);
    Defect::factory()->forVersionScope($version)->create(['severity' => DefectSeverity::SERIOUS, 'status' => DefectStatus::FIXING]);

    $result = app(ReleaseGateService::class)->check($version, ProjectVersionStatus::READY_TO_RELEASE);

    $this->assertFalse($result->passed);
    $this->assertSame([
        'requirements_pending_deploy',
        'tasks_completed',
        'severe_defects_closed',
        'release_notes_present',
    ], array_column($result->blocking, 'code'));
}
```

- [ ] **Step 2: Run release gate tests red**

Run: `cd ipms-backend && php artisan test --filter ReleaseGateServiceTest`

Expected: FAIL because the value object and service do not exist.

- [ ] **Step 3: Implement deterministic checks**

Return checks in this stable order: version metadata, non-empty scope, reviewed/assigned scope, project-side delivery status, tasks completed, fatal/serious defects closed, release notes present, acceptance complete. Each check contains `code`, `label`, `passed`, `blocking`, and `details`.

Apply them by target state exactly as follows:

```text
PLANNED: owner and planned release date present; code uniqueness remains a database/request invariant
IN_DEVELOPMENT: non-empty approved scope and an execution owner for every requirement
IN_TESTING: every project-side requirement is at least IN_TESTING
READY_TO_RELEASE: every project-side requirement is PENDING_DEPLOY, every task is COMPLETED, all fatal/serious defects are CLOSED, and release notes are present
RELEASED: rerun every READY_TO_RELEASE check inside the release transaction
ARCHIVED: every project-side requirement is ACCEPTED and every defect in scope is CLOSED
```

Use live database queries scoped by requirement-project IDs and matching project ID. Do not use dashboard counters.

- [ ] **Step 4: Integrate gates into status transitions**

`ProjectVersionService::transition()` calls `ReleaseGateService` for each permitted forward target other than RELEASED. A failed result throws `DomainConflictException('RELEASE_GATE_FAILED', 409, $result->blocking)` without writing status history. READY_TO_RELEASE to RELEASED is handled only by `ProjectReleaseService`; RELEASED to ARCHIVED remains a gated status transition.

- [ ] **Step 5: Run service suites green**

Run: `cd ipms-backend && php artisan test --filter 'ReleaseGateServiceTest|ProjectVersionServiceTest'`

Expected: PASS.

- [ ] **Step 6: Commit release gates**

```bash
git add ipms-backend/app/ValueObjects ipms-backend/app/Services/ReleaseGateService.php ipms-backend/app/Services/ProjectVersionService.php ipms-backend/tests/Feature/Services
git commit -m "feat: enforce project release gates"
```

### Task 6: Atomic and Idempotent Release

**Files:**
- Create: `ipms-backend/app/Events/ProjectVersionReleased.php`
- Create: `ipms-backend/app/Services/ProjectReleaseService.php`
- Create: `ipms-backend/tests/Feature/Services/ProjectReleaseServiceTest.php`
- Modify: `ipms-backend/app/Models/ProjectVersionReleaseSnapshot.php`

**Interfaces:**
- Consumes: READY_TO_RELEASE version for normal release; IN_TESTING or READY_TO_RELEASE version for superadmin force release; actor, lock version, release notes, `force`, and optional force reason.
- Produces: one immutable release snapshot and one after-commit `ProjectVersionReleased` event.

- [ ] **Step 1: Write release, override, rollback, and idempotency tests**

Use `DatabaseMigrations` for this class because it asserts an after-commit event.

In addition to the examples below, assert that a normal release requires the assigned IT PM and READY_TO_RELEASE; superadmin has no normal release permission; force release requires a superadmin, a non-empty reason, and status IN_TESTING or READY_TO_RELEASE; force attempts from DRAFT, PLANNED, IN_DEVELOPMENT, RELEASED, or ARCHIVED fail without writes.

Assert that updating or deleting an existing `ProjectVersionReleaseSnapshot` throws and leaves the stored snapshot unchanged.

```php
public function test_release_updates_project_side_states_and_snapshot_atomically(): void
{
    Event::fake([ProjectVersionReleased::class]);
    $version = ProjectVersion::factory()->ready()->withPassingScope()->create(['lock_version' => 5]);

    $released = app(ProjectReleaseService::class)->release($version, $version->project->manager, [
        'lock_version' => 5,
        'release_notes' => '完成财务与采购增强',
        'force' => false,
    ]);

    $this->assertSame(ProjectVersionStatus::RELEASED, $released->status);
    $this->assertDatabaseMissing('requirement_project', ['project_version_id' => $version->id, 'delivery_status' => ProjectDeliveryStatus::PENDING_DEPLOY->value]);
    $this->assertDatabaseCount('project_version_release_snapshots', 1);
    Event::assertDispatched(ProjectVersionReleased::class);
}

public function test_failed_release_writes_no_snapshot_or_status_change(): void
{
    $version = ProjectVersion::factory()->ready()->create();

    try {
        app(ProjectReleaseService::class)->release($version, $version->project->manager, ['lock_version' => 1, 'release_notes' => 'x', 'force' => false]);
        $this->fail('Expected release gate conflict.');
    } catch (DomainConflictException $exception) {
        $this->assertSame('RELEASE_GATE_FAILED', $exception->errorCode);
    }

    $this->assertSame(ProjectVersionStatus::READY_TO_RELEASE, $version->refresh()->status);
    $this->assertDatabaseCount('project_version_release_snapshots', 0);
}
```

- [ ] **Step 2: Run release service tests red**

Run: `cd ipms-backend && php artisan test --filter ProjectReleaseServiceTest`

Expected: FAIL because release service and event do not exist.

- [ ] **Step 3: Implement one transaction with row locks**

Within `DB::transaction()`: lock the version and target pivot rows, validate state and lock version, rerun gates, validate normal or force authorization and force reason, update pivot states to DEPLOYED, recalculate each affected requirement, update version release fields/status/lock, create one snapshot, history, and audit log. A forced IN_TESTING to RELEASED change records event type `force_release`, the original status, every failed gate, and the reason; it is the only permitted release skip.

If the locked version is already RELEASED and has a snapshot, return it without changing data or emitting a second event. Dispatch `ProjectVersionReleased` using `DB::afterCommit()`.

In `ProjectVersionReleaseSnapshot::booted()`, reject `updating` and `deleting` model events with `LogicException`; creation remains available only to the release service.

- [ ] **Step 4: Run release tests green**

Run: `cd ipms-backend && php artisan test --filter ProjectReleaseServiceTest`

Expected: PASS including exactly-once event assertion.

- [ ] **Step 5: Commit atomic release**

```bash
git add ipms-backend/app/Events ipms-backend/app/Services/ProjectReleaseService.php ipms-backend/app/Models/ProjectVersionReleaseSnapshot.php ipms-backend/tests/Feature/Services/ProjectReleaseServiceTest.php
git commit -m "feat: publish project versions atomically"
```

### Task 7: Project Version API

**Files:**
- Create: `ipms-backend/app/Http/Controllers/Api/ProjectVersionController.php`
- Create: `ipms-backend/app/Http/Requests/StoreProjectVersionRequest.php`
- Create: `ipms-backend/app/Http/Requests/UpdateProjectVersionRequest.php`
- Create: `ipms-backend/app/Http/Requests/TransitionProjectVersionRequest.php`
- Create: `ipms-backend/app/Http/Requests/ReleaseProjectVersionRequest.php`
- Create: `ipms-backend/app/Http/Requests/AssignRequirementVersionRequest.php`
- Create: `ipms-backend/app/Http/Resources/ProjectVersionResource.php`
- Create: `ipms-backend/tests/Feature/Api/ProjectVersionApiTest.php`
- Modify: `ipms-backend/routes/api.php`

**Interfaces:**
- Consumes: all services and policy methods from Tasks 2-6.
- Produces: these exact endpoints:

```text
GET    /api/projects/{projectId}/versions
POST   /api/projects/{projectId}/versions
GET    /api/project-versions/{id}
PUT    /api/project-versions/{id}
DELETE /api/project-versions/{id}
POST   /api/project-versions/{id}/status
GET    /api/project-versions/{id}/gate-check
POST   /api/project-versions/{id}/release
GET    /api/project-versions/{id}/history
PUT    /api/requirements/{requirementId}/projects/{projectId}/version
DELETE /api/requirements/{requirementId}/projects/{projectId}/version
GET    /api/requirements?project_id={projectId}&version_scope=unplanned
```

- [ ] **Step 1: Write API happy-path and error contract tests**

```php
public function test_manager_can_create_and_read_project_version(): void
{
    $project = Project::factory()->withManager()->create();

    $response = $this->actingAs($project->manager)->postJson("/api/projects/{$project->id}/versions", [
        'code' => '2026.08',
        'name' => '八月发布',
        'planned_release_date' => '2026-08-24',
    ])->assertCreated();

    $this->actingAs($project->manager)
        ->getJson('/api/project-versions/'.$response->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.code', '2026.08');
}

public function test_gate_failure_uses_machine_error_contract(): void
{
    $version = ProjectVersion::factory()->inTesting()->create();

    $this->actingAs($version->project->manager)
        ->postJson("/api/project-versions/{$version->id}/status", ['status' => 5, 'lock_version' => 1])
        ->assertConflict()
        ->assertJsonPath('error_code', 'RELEASE_GATE_FAILED')
        ->assertJsonStructure(['errors']);
}

public function test_manager_can_plan_and_unplan_one_project_requirement(): void
{
    $version = ProjectVersion::factory()->create();
    $link = RequirementProject::factory()->create(['project_id' => $version->project_id]);
    $manager = $version->project->manager;

    $this->actingAs($manager)->putJson(
        "/api/requirements/{$link->requirement_id}/projects/{$link->project_id}/version",
        ['project_version_id' => $version->id, 'lock_version' => 1]
    )->assertOk()->assertJsonPath('data.project_version_id', $version->id);

    $this->actingAs($manager)->deleteJson(
        "/api/requirements/{$link->requirement_id}/projects/{$link->project_id}/version",
        ['lock_version' => 2]
    )->assertOk()->assertJsonPath('data.project_version_id', null);
}
```

- [ ] **Step 2: Run API tests red**

Run: `cd ipms-backend && php artisan test --filter ProjectVersionApiTest`

Expected: FAIL with route not found.

- [ ] **Step 3: Implement requests, resource, controller, and routes**

Requests validate code shape, dates, lock version, status enum, rollback reason type, force flag, optional force-reason shape, and requirement-project membership. The release service enforces non-empty force reason so it can return `FORCE_REASON_REQUIRED`. The version service checks project-local code uniqueness and returns `VERSION_CODE_EXISTS` with `errors.code`; do not expose either case as generic `VALIDATION_FAILED`. Planning accepts `project_version_id`, `lock_version`, and an optional reason; unplanning accepts `lock_version` and an optional reason. Extend requirement listing with `version_scope=unplanned`, which requires `project_id` and filters `requirement_project.project_version_id IS NULL` inside the authenticated user's project scope.

Resource returns project, owner, `status`, `status_code`, `status_label`, counts, gate result, scope, history, release snapshot, `allowed_actions`, and `lock_version` without leaking hidden user fields. `status` is the numeric database value, `status_code` is the enum case name, and `status_label` is Chinese display text. Stable action names are `edit`, `delete`, `transition`, `plan_requirements`, `release`, and `force_release`; each appears only when Policy and current state both permit it. Every successful version write returns the incremented `lock_version`.

Controller catches `DomainConflictException` through centralized exception rendering and returns `ApiResponse`. Register all routes exactly as specified, with static child paths before `/{id}` patterns that could capture them.

- [ ] **Step 4: Run backend full suite green**

Run: `cd ipms-backend && php artisan test`

Expected: PASS.

- [ ] **Step 5: Export the route contract for Phase 3**

Run: `cd ipms-backend && php artisan route:list --path=api --json > storage/app/api-routes.json`

Expected: JSON contains every project-version endpoint and the corrected core action methods. Treat this generated file as a verification artifact; do not commit it.

- [ ] **Step 6: Commit the API**

```bash
git add ipms-backend/app/Http/Controllers/Api/ProjectVersionController.php ipms-backend/app/Http/Requests ipms-backend/app/Http/Resources ipms-backend/routes/api.php ipms-backend/tests/Feature/Api/ProjectVersionApiTest.php
git commit -m "feat: expose project release version API"
```

### Task 8: Core Task and Defect Workflow Hardening

**Files:**
- Create: `ipms-backend/app/Services/TaskWorkflowService.php`
- Create: `ipms-backend/app/Services/DefectWorkflowService.php`
- Create: `ipms-backend/app/Http/Requests/TransitionTaskRequest.php`
- Create: `ipms-backend/app/Http/Requests/HoldTaskRequest.php`
- Create: `ipms-backend/app/Http/Requests/AssignDefectRequest.php`
- Create: `ipms-backend/app/Http/Requests/ResolveDefectRequest.php`
- Create: `ipms-backend/app/Http/Requests/VerifyDefectRequest.php`
- Create: `ipms-backend/app/Http/Requests/ReopenDefectRequest.php`
- Create: `ipms-backend/app/Http/Resources/ProjectResource.php`
- Create: `ipms-backend/app/Http/Resources/RequirementResource.php`
- Create: `ipms-backend/app/Http/Resources/TaskResource.php`
- Create: `ipms-backend/app/Http/Resources/DefectResource.php`
- Create: `ipms-backend/tests/Feature/Services/TaskWorkflowServiceTest.php`
- Create: `ipms-backend/tests/Feature/Services/DefectWorkflowServiceTest.php`
- Create: `ipms-backend/tests/Feature/Api/CoreAllowedActionsTest.php`
- Modify: `ipms-backend/app/Enums/TaskStatus.php`
- Modify: `ipms-backend/app/Enums/DefectStatus.php`
- Modify: `ipms-backend/app/Policies/RequirementPolicy.php`
- Modify: `ipms-backend/app/Policies/ProjectPolicy.php`
- Modify: `ipms-backend/app/Policies/TaskPolicy.php`
- Modify: `ipms-backend/app/Policies/DefectPolicy.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/RequirementController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/ProjectController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/TaskController.php`
- Modify: `ipms-backend/app/Http/Controllers/Api/DefectController.php`

**Interfaces:**
- Consumes: exact seeded role codes, current workflow state, project membership, assignee identity, and existing model scopes.
- Produces: validated workflow services and `allowed_actions[]` on requirement, task, and defect API resources.

- [ ] **Step 1: Write table-driven task transition tests**

Use this exact transition matrix:

```text
TODO -> IN_PROGRESS, SUSPENDED
IN_PROGRESS -> COMPLETED, SUSPENDED
SUSPENDED -> TODO, IN_PROGRESS
COMPLETED -> no state
```

Claim is allowed only for an unassigned TODO task and sets assignee plus IN_PROGRESS atomically. Hold requires a reason. IT PMs may assign or transition records in managed projects. Internal IT project members may create and edit tasks in projects where they are members. Supplier PMs may create, edit, assign, and transition supplier-side tasks in their projects. Supplier developers may claim an eligible project task and transition only their assigned tasks. Requesters and unrelated project users receive `FORBIDDEN`.

- [ ] **Step 2: Write table-driven defect lifecycle tests**

Use this exact action matrix:

```text
PENDING_CONFIRM --confirm--> CONFIRMED
CONFIRMED --assign--> FIXING
FIXING --resolve--> PENDING_RETEST
PENDING_RETEST --verify pass--> CLOSED
PENDING_RETEST --verify fail--> REOPENED
REOPENED --assign--> FIXING
CLOSED --reopen with reason--> REOPENED
```

IT PMs confirm and assign within managed projects; internal IT members can submit and edit defects in their projects. Supplier PMs may assign supplier-side defects. The assigned supplier developer resolves. Supplier testers assigned to the project verify. Reopen requires `DefectPolicy::reopen`; view permission alone is never sufficient. Invalid state actions return `INVALID_DEFECT_TRANSITION` with HTTP 409.

- [ ] **Step 3: Run service tests red**

Run: `cd ipms-backend && php artisan test --filter 'TaskWorkflowServiceTest|DefectWorkflowServiceTest'`

Expected: FAIL because the services do not exist and current controllers permit invalid direct transitions.

- [ ] **Step 4: Implement enum matrices and transactional services**

Add `TaskStatus::allowedTransitions()` and action predicates on `DefectStatus`. `TaskWorkflowService::create()` and `DefectWorkflowService::create()` own initial writes and relation validation. Each service action locks the target row, rechecks current state and assignee, applies timestamps or reasons, writes one audit entry, and returns the refreshed model. Controllers authorize, validate a Form Request, call the service, and return a resource through `ApiResponse::success()`.

- [ ] **Step 5: Write API resource action tests**

Create the same project record and request it as requester, IT PM, supplier PM, supplier developer, supplier tester, unrelated user, and superadmin. Assert response resources expose only these stable command names when both Policy and state permit them:

```text
project: edit, archive, create_version
requirement: edit, review, transition_project, create_task
task: edit, claim, transition, hold
defect: edit, confirm, assign, resolve, verify, reopen
```

Also send each hidden action directly to its endpoint and assert the backend rejects it. This proves that `allowed_actions` is presentation metadata rather than the security boundary.

- [ ] **Step 6: Implement resources and paginated transformations**

`ProjectResource` includes manager, supplier, core counts, version counts, and `allowed_actions`; `create_version` is present only for the internal IT manager assigned to that project. `RequirementResource` includes aggregate status, `project_deliveries[]`, revision data, and `allowed_actions`. Each project delivery includes project, project-side status, target version, owner, task progress, and open fatal/serious defect count. `TaskResource` and `DefectResource` include related project and requirement summaries plus `allowed_actions`.

For requirement, task, defect, and version states, always return numeric `status`, enum-name `status_code`, and Chinese `status_label`. Nested project delivery rows use `delivery_status`, `delivery_status_code`, and `delivery_status_label`. Requirement priority returns `priority`, `priority_code`, and `priority_label`; defect severity returns `severity`, `severity_code`, and `severity_label`. Frontend business conditions use codes; labels are display-only.

For list endpoints, call transformer-aware `ApiResponse::paginated()` with each resource's resolved array. Show and action endpoints return the same resource shape, so the frontend can replace a row without a second schema.

- [ ] **Step 7: Run all core workflow tests green**

Run: `cd ipms-backend && php artisan test --filter 'TaskWorkflowServiceTest|DefectWorkflowServiceTest|CoreAllowedActionsTest|RequirementWorkflowServiceTest'`

Expected: PASS for every role and state case.

- [ ] **Step 8: Commit hardened core workflows**

```bash
git add ipms-backend/app/Services/TaskWorkflowService.php ipms-backend/app/Services/DefectWorkflowService.php ipms-backend/app/Http/Requests ipms-backend/app/Http/Resources ipms-backend/app/Enums ipms-backend/app/Policies ipms-backend/app/Http/Controllers/Api ipms-backend/tests/Feature/Services ipms-backend/tests/Feature/Api/CoreAllowedActionsTest.php
git commit -m "feat: enforce core workflow actions by role and state"
```

## Phase 2 Completion Gate

Run:

```bash
cd ipms-backend
php artisan migrate:fresh --env=testing
php artisan test
php artisan route:list --path=api
```

Expected: all tests pass; project version endpoints exist; cross-project requirements aggregate correctly; failed gates write no release state; released versions have one immutable snapshot.
