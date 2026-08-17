# IPMS Notifications, Ubuntu Deployment, and End-to-End Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the operational loop with recipient-safe in-app notifications, queued Outlook email, deterministic reminders, a repeatable Ubuntu 22.04 release process, and automated multi-role end-to-end acceptance.

**Architecture:** Business transactions dispatch domain events only after commit. A notification service resolves recipients, writes in-app notifications, records channel delivery attempts, and queues Microsoft Graph email separately so delivery failures cannot roll back business state. Production uses Nginx, PHP-FPM, PostgreSQL, Redis, Supervisor, and timestamped release directories with a `current` symlink.

**Tech Stack:** Laravel 11 events, queues, mail, scheduler, PostgreSQL 15, Redis 7, Vue 3, Pinia, Vitest, Playwright, Ubuntu 22.04, Nginx, PHP 8.3-FPM, Supervisor, Node.js 20

## Global Constraints

- Complete Phases 1 through 3 before starting this plan.
- Read the approved design and all earlier phase plans before editing.
- Create in-app notifications inside or immediately after successful business transactions; dispatch email only after commit.
- Never place Microsoft Entra client secrets, database credentials, SSH credentials, or account passwords in tracked files, process arguments, logs, fixtures, screenshots, or documentation.
- Do not copy the server password supplied in chat into any command or file. Rotate it before production use.
- Use a non-root deployment account and SSH keys. Root login is not part of the finished deployment path.
- Cloud reset requires an explicit `--confirm-reset` argument and an environment check.
- Public-IP HTTP is for acceptance only. Keep domain and HTTPS settings parameterized for later activation.
- Database-backed feature tests use `RefreshDatabase`; tests that assert after-commit listeners use `DatabaseMigrations`. Mail and queue tests use fakes and make no external network calls.
- Do not initialize Git without approval. Commit steps assume the repository prerequisite in the plan index has been satisfied.

---

### Task 1: In-App Notification Persistence and API

**Files:**
- Create: `ipms-backend/database/migrations/2026_08_17_000030_create_in_app_notifications_table.php`
- Create: `ipms-backend/app/Models/InAppNotification.php`
- Create: `ipms-backend/database/factories/InAppNotificationFactory.php`
- Create: `ipms-backend/app/Http/Resources/InAppNotificationResource.php`
- Create: `ipms-backend/app/Http/Controllers/Api/InAppNotificationController.php`
- Create: `ipms-backend/tests/Feature/Api/InAppNotificationApiTest.php`
- Modify: `ipms-backend/app/Models/User.php`
- Modify: `ipms-backend/routes/api.php`

**Interfaces:**
- Produces: `GET /api/notifications`, `GET /api/notifications/unread-count`, `POST /api/notifications/{id}/read`, and `POST /api/notifications/read-all`.
- Notification fields: `id`, `user_id`, `event_code`, `dedup_key`, `title`, `body`, `target_type`, `target_id`, `target_url`, `payload`, `read_at`, `created_at`.

- [ ] **Step 1: Write recipient isolation and read-state tests**

```php
public function test_user_only_lists_their_notifications(): void
{
    $user = User::factory()->create();
    $other = User::factory()->create();
    InAppNotification::factory()->for($user)->count(2)->create();
    InAppNotification::factory()->for($other)->create();

    $this->actingAs($user)
        ->getJson('/api/notifications?page=1&page_size=20')
        ->assertOk()
        ->assertJsonCount(2, 'data.items');
}

public function test_user_cannot_mark_another_users_notification_read(): void
{
    $user = User::factory()->create();
    $notification = InAppNotification::factory()->create();

    $this->actingAs($user)
        ->postJson("/api/notifications/{$notification->id}/read")
        ->assertNotFound();
}
```

Also test unread count, one-item read, idempotent repeated read, read-all, pagination, and unauthenticated access.

- [ ] **Step 2: Run the notification API tests red**

Run: `cd ipms-backend && php artisan test --filter InAppNotificationApiTest`

Expected: FAIL because the table, model, and routes do not exist.

- [ ] **Step 3: Create the notification table and model**

Use a bigint primary key, indexed `user_id`, indexed nullable `read_at`, string `event_code`, globally unique string `dedup_key`, nullable morph-style target fields, relative `target_url`, JSONB `payload`, and timestamps. Add a composite index on `user_id`, `read_at`, and `created_at`. The model exposes `recipient()` and casts `payload` and `read_at`.

- [ ] **Step 4: Implement recipient-scoped API methods**

Every query begins from `$request->user()->inAppNotifications()`. Clamp `page_size` to 1 through 100. Return the standard API envelope. A notification missing from the current user's relation returns 404, not 403, to avoid disclosing another user's data.

- [ ] **Step 5: Run notification API tests green**

Run: `cd ipms-backend && php artisan test --filter InAppNotificationApiTest`

Expected: PASS.

- [ ] **Step 6: Commit in-app notification storage**

```bash
git add ipms-backend/database/migrations ipms-backend/database/factories/InAppNotificationFactory.php ipms-backend/app/Models ipms-backend/app/Http ipms-backend/routes/api.php ipms-backend/tests/Feature/Api/InAppNotificationApiTest.php
git commit -m "feat: add recipient-scoped in-app notifications"
```

### Task 2: Domain Notification Service and Event Catalog

**Files:**
- Create: `ipms-backend/app/Enums/NotificationEventCode.php`
- Create: `ipms-backend/app/Data/NotificationMessage.php`
- Create: `ipms-backend/app/Events/WorkflowNotificationRequested.php`
- Create: `ipms-backend/app/Services/NotificationRecipientResolver.php`
- Create: `ipms-backend/app/Services/NotificationService.php`
- Create: `ipms-backend/app/Listeners/CreateWorkflowNotifications.php`
- Create: `ipms-backend/tests/Feature/Notifications/WorkflowNotificationTest.php`
- Modify: `ipms-backend/app/Providers/AppServiceProvider.php`
- Modify: `ipms-backend/app/Services/RequirementWorkflowService.php`
- Modify: `ipms-backend/app/Services/TaskWorkflowService.php`
- Modify: `ipms-backend/app/Services/DefectWorkflowService.php`
- Modify: `ipms-backend/app/Services/ProjectVersionService.php`
- Modify: `ipms-backend/app/Services/ProjectReleaseService.php`

**Interfaces:**
- Consumes: `WorkflowNotificationRequested` events emitted by requirement, task, defect, and project-version services.
- Produces: deduplicated `InAppNotification` records with permission-safe relative target URLs.

- [ ] **Step 1: Write event-to-recipient tests**

Use event fakes only to assert the originating business event, then run listener integration tests without event fakes. Cover this catalog:

```text
REQUIREMENT_SUBMITTED
REQUIREMENT_REVIEWED
REQUIREMENT_ASSIGNED
TASK_ASSIGNED
TASK_DUE_SOON
TASK_OVERDUE
DEFECT_ASSIGNED
DEFECT_RESOLVED
DEFECT_RETESTED
VERSION_STATUS_CHANGED
VERSION_SCOPE_CHANGED_IN_TESTING
VERSION_GATE_FAILED
VERSION_RELEASED
VERSION_FORCE_RELEASED
```

Assert that requesters receive their review result, assignees receive task or defect work, IT PMs receive release risk, and unrelated project users receive nothing.

- [ ] **Step 2: Run workflow notification tests red**

Run: `cd ipms-backend && php artisan test --filter WorkflowNotificationTest`

Expected: FAIL because the service and catalog do not exist.

- [ ] **Step 3: Implement exact recipient rules**

`NotificationRecipientResolver` returns distinct active user IDs. Resolve users from project manager, project memberships, explicit assignee, requirement creator, and superadmin only where the event requires them. Exclude disabled users and the acting user unless the event is a release confirmation they initiated.

- [ ] **Step 4: Implement permission-safe target URLs and deduplication**

Build URLs from known entity types only:

```text
/requirements/{id}
/tasks?focus={id}
/defects?focus={id}
/project-versions/{id}
```

Before writing, verify the target record remains visible to the recipient's model scope. Use the table's deterministic `dedup_key` column for event retries.

- [ ] **Step 5: Dispatch only after commit**

`WorkflowNotificationRequested` carries only `NotificationEventCode`, target type/ID, actor ID, and a serializable context array; listeners reload current models and never serialize an open database transaction. Use `DB::afterCommit()` in workflow services after the business transaction returns successfully. Failed or rolled-back requirement, task, defect, version transition, or release operations must not create notifications.

- [ ] **Step 6: Run notification integration tests green**

Run: `cd ipms-backend && php artisan test --filter WorkflowNotificationTest`

Expected: PASS, including rollback and duplicate-event cases.

- [ ] **Step 7: Commit workflow notifications**

```bash
git add ipms-backend/app/Enums/NotificationEventCode.php ipms-backend/app/Data/NotificationMessage.php ipms-backend/app/Services ipms-backend/app/Listeners ipms-backend/app/Providers/AppServiceProvider.php ipms-backend/app/Services ipms-backend/tests/Feature/Notifications ipms-backend/database/migrations
git commit -m "feat: notify workflow participants after commit"
```

### Task 3: Queued Outlook Email Through Microsoft Graph

**Files:**
- Create: `ipms-backend/database/migrations/2026_08_17_000031_extend_notification_logs_for_delivery.php`
- Create: `ipms-backend/app/Enums/NotificationChannel.php`
- Create: `ipms-backend/app/Enums/NotificationDeliveryStatus.php`
- Create: `ipms-backend/app/Contracts/WorkflowMailGateway.php`
- Create: `ipms-backend/app/Services/MicrosoftGraphMailGateway.php`
- Create: `ipms-backend/app/Services/LogWorkflowMailGateway.php`
- Create: `ipms-backend/resources/views/mail/workflow.blade.php`
- Create: `ipms-backend/app/Jobs/SendWorkflowEmail.php`
- Create: `ipms-backend/app/Listeners/QueueWorkflowEmail.php`
- Create: `ipms-backend/tests/Feature/Notifications/WorkflowEmailTest.php`
- Modify: `ipms-backend/app/Models/NotificationLog.php`
- Modify: `ipms-backend/app/Providers/AppServiceProvider.php`
- Modify: `ipms-backend/config/ipms.php`
- Create: `ipms-backend/config/services.php`
- Modify: `ipms-backend/.env.example`

**Interfaces:**
- Consumes: critical events from the event catalog.
- Produces: queued Microsoft Graph `sendMail` requests and pending/sent/failed delivery records.

- [ ] **Step 1: Write queue, content, and failure-isolation tests**

Use `Queue::fake()` to assert critical events queue `SendWorkflowEmail` and non-critical events do not. Use `Http::fake()` to assert the Entra token request, Graph recipient, subject, HTML body, and action URL. Force the gateway to throw in a job test and assert the original business state remains committed while the log becomes `FAILED` with a sanitized error. No test performs an external request.

- [ ] **Step 2: Run email tests red**

Run: `cd ipms-backend && php artisan test --filter WorkflowEmailTest`

Expected: FAIL because the job, gateway, and delivery fields do not exist.

- [ ] **Step 3: Extend delivery logging**

Add `event_code`, `channel`, `dedup_key`, `queued_at`, `delivered_at`, `failed_at`, and `attempt_count`. Make `dedup_key + channel + recipient_id` unique. Change legacy `sent_at` to nullable and backfill `delivered_at` from it for existing successful rows. Keep existing relation columns for backward compatibility. Never store access tokens, Entra secrets, or full exception traces.

- [ ] **Step 4: Implement the gateway contract and Graph adapter**

`WorkflowMailGateway::send(string $recipient, string $subject, string $html): void` is the only transport interface used by the job. `MicrosoftGraphMailGateway` requests an application token from `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token` with scope `https://graph.microsoft.com/.default`, caches it for less than its returned lifetime, and posts to `/v1.0/users/{sender}/sendMail`. Use bounded HTTP connect/read timeouts and `throw()` on non-success responses. `LogWorkflowMailGateway` records sanitized metadata for local acceptance without sending mail.

- [ ] **Step 5: Implement the queued job**

`SendWorkflowEmail` implements `ShouldQueue`, uses a Redis queue named `mail`, has `tries = 5`, exponential backoff `[60, 300, 900, 3600]`, and a 30-second timeout. The job atomically increments attempts, marks sent after mail succeeds, and marks failed from `failed(Throwable $exception)` with a message capped at 1000 characters.

- [ ] **Step 6: Configure Microsoft Graph from environment only**

Bind the gateway from `IPMS_MAIL_DRIVER=log|microsoft_graph`. Add empty examples for `MICROSOFT_TENANT_ID`, `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, and `MICROSOFT_MAIL_SENDER`; do not add real values. Production requires an Entra application with Microsoft Graph application permission `Mail.Send`, tenant admin consent, and an Exchange Online application access scope limited to the approved sender mailbox. Use relative application links when `APP_URL` is absent and absolute links when configured.

- [ ] **Step 7: Run email tests green**

Run: `cd ipms-backend && php artisan test --filter WorkflowEmailTest`

Expected: PASS with HTTP fakes and no network calls.

- [ ] **Step 8: Commit queued email delivery**

```bash
git add ipms-backend/app/Enums ipms-backend/app/Contracts ipms-backend/app/Services/MicrosoftGraphMailGateway.php ipms-backend/app/Services/LogWorkflowMailGateway.php ipms-backend/app/Jobs ipms-backend/app/Listeners ipms-backend/app/Models/NotificationLog.php ipms-backend/app/Providers/AppServiceProvider.php ipms-backend/resources/views/mail ipms-backend/database/migrations ipms-backend/config/ipms.php ipms-backend/config/services.php ipms-backend/.env.example ipms-backend/tests/Feature/Notifications/WorkflowEmailTest.php
git commit -m "feat: queue critical Outlook workflow email"
```

### Task 4: Deterministic Due Reminders and Scheduler

**Files:**
- Create: `ipms-backend/app/Services/DueReminderService.php`
- Create: `ipms-backend/tests/Feature/Console/ScanDueRemindersTest.php`
- Modify: `ipms-backend/app/Console/Commands/ScanDueReminders.php`
- Modify: `ipms-backend/routes/console.php`

**Interfaces:**
- Consumes: active user notification configuration, unfinished task due dates, and existing deduplication logs.
- Produces: daily due-soon and overdue events without repeated notifications for the same recipient, task, and date.

- [ ] **Step 1: Write time-frozen reminder tests**

Use `travelTo()` and cover reminders disabled, one-day preference, overdue tasks, completed tasks, inaccessible tasks, rerunning the command on the same day, and a subsequent-day reminder. Assert command exit code 0 and exact queued event count.

- [ ] **Step 2: Run reminder tests red**

Run: `cd ipms-backend && php artisan test --filter ScanDueRemindersTest`

Expected: FAIL because the command currently performs no scan.

- [ ] **Step 3: Implement idempotent scanning**

Select unfinished tasks in bounded chunks, resolve recipients through the same service as interactive events, and use a key shaped like `TASK_DUE_SOON:{task_id}:{recipient_id}:{YYYY-MM-DD}` or `TASK_OVERDUE:{task_id}:{recipient_id}:{YYYY-MM-DD}`. Emit counts for scanned, queued, skipped, and failed records without printing addresses or content.

- [ ] **Step 4: Register the schedule**

In `routes/console.php`, schedule `reminders:scan-due` daily at `08:00` in `Asia/Shanghai`, use `withoutOverlapping()`, and `onOneServer()` when Redis cache is active.

- [ ] **Step 5: Run reminder and notification tests green**

Run: `cd ipms-backend && php artisan test --filter 'ScanDueRemindersTest|WorkflowNotificationTest|WorkflowEmailTest'`

Expected: PASS.

- [ ] **Step 6: Commit due reminders**

```bash
git add ipms-backend/app/Services/DueReminderService.php ipms-backend/app/Console/Commands/ScanDueReminders.php ipms-backend/routes/console.php ipms-backend/tests/Feature/Console/ScanDueRemindersTest.php
git commit -m "feat: schedule deterministic workflow reminders"
```

### Task 5: Frontend Notification Center and Preferences

**Files:**
- Create: `ipms-frontend/src/stores/notifications.js`
- Create: `ipms-frontend/src/stores/notifications.test.js`
- Create: `ipms-frontend/src/components/notifications/NotificationPopover.vue`
- Create: `ipms-frontend/src/components/notifications/NotificationPopover.test.js`
- Create: `ipms-frontend/src/components/notifications/NotificationPreferences.vue`
- Create: `ipms-frontend/src/views/notifications/NotificationListView.vue`
- Modify: `ipms-frontend/src/api/notification.js`
- Modify: `ipms-frontend/src/components/layout/HeaderBar.vue`
- Modify: `ipms-frontend/src/components/common/ProfileDialog.vue`
- Modify: `ipms-frontend/src/router/index.js`

**Interfaces:**
- Consumes: notification API, unread count, notification configs, and `target_url`.
- Produces: real header badge, recent-notification popover, full notification list, single/read-all actions, and persisted reminder settings.

- [ ] **Step 1: Write store and popover tests**

Assert unread count loads after authentication, a zero count hides the badge, opening the popover loads recent items, clicking an item marks it read before navigation, read-all updates local state, 403 target navigation stays on the current page with a message, and logout clears notification state.

- [ ] **Step 2: Run notification frontend tests red**

Run: `cd ipms-frontend && npm test -- src/stores/notifications.test.js src/components/notifications/NotificationPopover.test.js`

Expected: FAIL because the store and component do not exist.

- [ ] **Step 3: Implement API and store methods**

Use exact paths from Task 1 and existing `/notification-configs`. Poll unread count every 60 seconds only while authenticated and the document is visible. Stop timers on logout and component unmount. Do not poll the full notification list.

- [ ] **Step 4: Implement header and list views**

Replace the fixed badge with store state. The popover shows the most recent 8 notifications, loading/error/empty states, single read, read-all, and a link to `/notifications`. The list provides read-state and event filters with standard pagination.

- [ ] **Step 5: Connect settings to real persistence**

Embed `NotificationPreferences` as a tab in `ProfileDialog`. Load and update `remind_enabled` and `remind_days_before`. Disable the days control when reminders are off. Show success only after the API succeeds; preserve edits on validation failure. Do not expose Entra, Microsoft Graph, queue, or deployment credentials in the browser.

- [ ] **Step 6: Run frontend notification tests and build**

Run: `cd ipms-frontend && npm test -- src/stores/notifications.test.js src/components/notifications/NotificationPopover.test.js && npm run build`

Expected: PASS.

- [ ] **Step 7: Commit notification experience**

```bash
git add ipms-frontend/src/stores/notifications.js ipms-frontend/src/stores/notifications.test.js ipms-frontend/src/components/notifications ipms-frontend/src/views/notifications ipms-frontend/src/api/notification.js ipms-frontend/src/components/layout/HeaderBar.vue ipms-frontend/src/components/common/ProfileDialog.vue ipms-frontend/src/router/index.js
git commit -m "feat: add in-app notification center"
```

### Task 6: Hardened Ubuntu 22.04 Release Layout

**Files:**
- Create: `deploy/ubuntu/bootstrap.sh`
- Create: `deploy/ubuntu/deploy.sh`
- Create: `deploy/ubuntu/reset-data.sh`
- Create: `deploy/ubuntu/rollback.sh`
- Create: `deploy/ubuntu/nginx/ipms.conf`
- Create: `deploy/ubuntu/supervisor/ipms-worker.conf`
- Create: `deploy/ubuntu/cron/ipms-scheduler`
- Create: `deploy/ubuntu/env/ipms.env.example`
- Create: `deploy/ubuntu/tests/static-check.ps1`
- Modify: `server_init.sh`
- Modify: `server_backend.sh`
- Modify: `server_frontend.sh`
- Modify: `server_service.sh`
- Modify: `server_diag.sh`
- Modify: `fix.sh`
- Modify: `deploy.ps1`

**Interfaces:**
- Consumes: a source archive or checked-out release plus `/var/www/ipms/shared/.env`.
- Produces: `/var/www/ipms/releases/{release_id}`, `/var/www/ipms/current`, shared Laravel storage, and controlled service restarts.

- [ ] **Step 1: Write static deployment safety checks**

The PowerShell test scans runtime deployment scripts and configuration while excluding its own test source. It must fail when those files contain password assignments with non-empty values, `sshpass`, `PermitRootLogin yes`, `php artisan serve`, private Windows user paths, embedded public IP literals, or commands that print account credentials. It must also assert `set -euo pipefail`, required argument validation, and `--confirm-reset` in destructive scripts.

- [ ] **Step 2: Run the static check red**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File deploy/ubuntu/tests/static-check.ps1`

Expected: FAIL against the current scripts because they contain hardcoded connection details, credentials, and the development server.

- [ ] **Step 3: Implement one-time host bootstrap**

`bootstrap.sh` requires sudo and an explicit deployment username. Install Nginx, PHP 8.3-FPM with Laravel extensions, PostgreSQL 15 client/server, Redis, Supervisor, Composer 2, Node.js 20, and system utilities from signed package sources. Create `/var/www/ipms/{releases,shared}` owned by the deployment user. Create a PostgreSQL role and databases only from values read interactively or from a root-owned environment file; never echo secrets.

- [ ] **Step 4: Implement atomic application deployment**

`deploy.sh` accepts `--source`, `--release-id`, and optional `--seed-demo`. It must:

```text
verify shared/.env exists and has mode 600 or stricter
copy source into a new release directory
run composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
run npm ci and npm run build
copy frontend dist contents into ipms-backend/public without replacing index.php
link shared/.env and shared/storage
run php artisan migrate --force
cache config, routes, and views
switch current symlink atomically
reload PHP-FPM, Nginx, and Supervisor workers
verify /up and the frontend index
retain the current and two previous releases
```

If health validation fails after the symlink switch, restore the previous symlink and reload services.

- [ ] **Step 5: Implement controlled reset and rollback**

`reset-data.sh` must refuse unless `APP_ENV` is not `production` or `--allow-production-reset` is supplied together with `--confirm-reset`. Before reset, create a timestamped `pg_dump` and environment checksum. Then run `migrate:fresh --seed --force` with `IPMS_SEED_DEMO=true`. `rollback.sh` selects an existing release directory, switches the symlink, runs only backward-compatible cache commands, and does not automatically roll back database migrations.

- [ ] **Step 6: Configure Nginx, queue, and scheduler**

Nginx uses the current backend public directory as root, serves copied Vite assets and `index.html`, routes `/api/*` and `/sanctum/csrf-cookie` to Laravel `index.php`, denies dotfiles and `.env`, applies upload limits, and exposes `/up`. Supervisor runs separate `default` and `mail` queues as the deployment user. Cron invokes `php artisan schedule:run` every minute with output sent to the system journal.

- [ ] **Step 7: Replace legacy scripts with safe entry points**

Make old root scripts print a deprecation message and delegate to the new scripts only when all required arguments are supplied. `deploy.ps1` must package and upload with SSH key authentication, accept host and user as parameters, and never default to root or a password.

- [ ] **Step 8: Run static deployment checks green**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File deploy/ubuntu/tests/static-check.ps1`

Expected: PASS with no forbidden literal or development server usage.

- [ ] **Step 9: Commit the deployment layout**

```bash
git add deploy server_init.sh server_backend.sh server_frontend.sh server_service.sh server_diag.sh fix.sh deploy.ps1
git commit -m "ops: add repeatable Ubuntu release deployment"
```

### Task 7: Playwright Multi-Role Closed-Loop Acceptance

**Files:**
- Modify: `ipms-frontend/package.json`
- Create: `ipms-frontend/playwright.config.js`
- Create: `ipms-frontend/e2e/fixtures/auth.js`
- Create: `ipms-frontend/e2e/helpers/api.js`
- Create: `ipms-frontend/e2e/role-access.spec.js`
- Create: `ipms-frontend/e2e/core-workflow.spec.js`
- Create: `ipms-frontend/e2e/visual-layout.spec.js`
- Create: `ipms-backend/database/seeders/E2eWorkflowSeeder.php`
- Create: `ipms-backend/tests/Feature/Seeders/E2eWorkflowSeederTest.php`

**Interfaces:**
- Consumes: deterministic role accounts and the complete local application.
- Produces: repeatable browser acceptance for the approved closed loop at 1440 by 900 and 1024 by 768.

- [ ] **Step 1: Add pinned Playwright dependency and scripts**

Install `@playwright/test@^1.46.1`, add `test:e2e` and `test:e2e:ui`, then install the Chromium browser binary:

Run: `cd ipms-frontend && npm install --save-dev @playwright/test@^1.46.1 && npx playwright install chromium`

Configure Chromium only, one worker for the stateful closed-loop spec, trace on first retry, screenshots on failure, and environment-based `baseURL`.

- [ ] **Step 2: Write the deterministic E2E seed test**

The seeder must create one account per approved role with `must_change_password=false`, two projects, one draft version in each project, and no completed workflow records. Read the shared test password from `IPMS_E2E_PASSWORD`; throw when absent. Assert rerunning the seeder produces the same account and project counts.

- [ ] **Step 3: Run the seeder test red, implement, then run green**

Run: `cd ipms-backend && php artisan test --filter E2eWorkflowSeederTest`

Expected before implementation: FAIL. Expected after implementation: PASS.

- [ ] **Step 4: Write the complete browser workflow**

First add `role-access.spec.js`: log in once as each of the seven seeded roles, assert its exact menu, verify one allowed page, and verify one forbidden direct URL returns the 403 experience without protected data. Keep these cases independent so a failure identifies the affected role.

Then write the complete workflow below.

The test performs these actions through the visible UI, switching authenticated sessions between steps:

```text
requester submits one requirement associated with two projects
IT PM approves it and plans each project relation into a different version
supplier PM creates and assigns a task
supplier developer claims and completes the task
supplier tester creates a serious defect
IT PM sees release gate failure
supplier developer resolves the defect
supplier tester verifies and closes it
IT PM advances and releases the first project version
requester sees that project deployed while the second remains in development
the requirement aggregate status equals the lower project progress
```

Use user-visible labels and test IDs only for ambiguous repeated controls. Do not use arbitrary sleep calls; wait on network responses and visible state.

- [ ] **Step 5: Verify notifications, email queue records, and audit logs**

After release, assert the relevant users see in-app notifications, the notification delivery log contains queued and delivered records under `IPMS_MAIL_DRIVER=log`, and the superadmin audit view shows version status and release entries. Add a separate superadmin force-release scenario that requires and displays the override reason.

- [ ] **Step 6: Add visual layout assertions**

At both viewports, capture login, dashboard, version detail, requirement detail, and notification popover. Assert no body-level horizontal overflow, logo images have non-zero natural dimensions, primary controls are visible, and no header/sidebar/content overlap occurs.

- [ ] **Step 7: Run end-to-end acceptance**

Run: `cd ipms-frontend && npm run test:e2e`

Expected: PASS for closed-loop and visual-layout projects.

- [ ] **Step 8: Commit end-to-end coverage**

```bash
git add ipms-frontend/package.json ipms-frontend/package-lock.json ipms-frontend/playwright.config.js ipms-frontend/e2e ipms-backend/database/seeders/E2eWorkflowSeeder.php ipms-backend/tests/Feature/Seeders/E2eWorkflowSeederTest.php
git commit -m "test: cover multi-role release workflow end to end"
```

### Task 8: Runbook, Local Release Rehearsal, and Cloud Acceptance

**Files:**
- Create: `docs/operations/ubuntu-deployment-runbook.md`
- Create: `docs/operations/release-acceptance-checklist.md`
- Create: `docs/operations/domain-and-https-cutover.md`
- Modify: files identified during rehearsal

**Interfaces:**
- Consumes: all implementation phases and deployment scripts.
- Produces: an operator-ready deployment, reset, rollback, backup, queue, scheduler, email, domain, and HTTPS procedure.

- [ ] **Step 1: Write the runbook with parameterized examples**

Document prerequisites, SSH key setup, deployment user creation, environment file fields, PostgreSQL backup/restore, deploy, health check, reset confirmation, rollback, queue inspection, scheduler inspection, and log locations. Use environment variables such as `$IPMS_HOST` and `$IPMS_DEPLOY_USER`; do not include the supplied password or personal local paths.

- [ ] **Step 2: Document public-IP acceptance and domain cutover separately**

For IP acceptance, set `APP_URL=http://$IPMS_HOST`, same-origin Sanctum stateful domains, and non-secure cookies. For domain cutover, require a DNS record, TLS certificate, HTTPS redirect, `SESSION_SECURE_COOKIE=true`, HSTS after validation, and updated `APP_URL`. State clearly that the IP-only phase is not the final security posture.

- [ ] **Step 3: Rehearse against a disposable Ubuntu 22.04 environment**

Run bootstrap, initial deploy, demo reset, repeated deploy, failed-health rollback, queue restart, scheduler invocation, and database restore. Record commands, timestamps, and pass/fail results without secrets. Fix scripts until every step is repeatable.

- [ ] **Step 4: Run the complete local verification suite**

Run:

```bash
cd ipms-backend && php artisan test
cd ../ipms-frontend && npm test && npm run build && npm run test:e2e
```

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File deploy/ubuntu/tests/static-check.ps1`

Expected: all commands PASS.

- [ ] **Step 5: Perform cloud acceptance only after credential rotation**

Back up the current host, deploy with an SSH key and non-root account, reset the explicitly disposable data, and verify health, login, role isolation, the core release workflow, queue processing, scheduler execution, and static assets. Do not set `IPMS_MAIL_DRIVER=microsoft_graph` until a test recipient, approved sender mailbox, Entra application permission, admin consent, and mailbox access scope are verified.

- [ ] **Step 6: Commit operational documentation and rehearsal fixes**

```bash
git add docs/operations deploy ipms-backend ipms-frontend
git commit -m "docs: add IPMS release operations runbook"
```

## Phase 4 Completion Gate

The project milestone is complete only when:

```text
in-app notifications are recipient-isolated and fully readable
critical Outlook email is queued, logged, retried, and failure-isolated
due reminders are scheduled and idempotent
the Ubuntu deployment has no tracked credentials and uses PHP-FPM rather than a development server
reset, deployment, health failure rollback, and database restore are rehearsed
the complete multi-role Playwright workflow passes at both desktop viewports
all backend, frontend, E2E, build, and deployment static checks pass
the exposed high-privilege server credential has been rotated before cloud acceptance
```
