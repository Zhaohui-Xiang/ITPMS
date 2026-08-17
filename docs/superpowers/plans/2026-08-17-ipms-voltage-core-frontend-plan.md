# IPMS Voltage Core Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the mock-driven frontend with a branded Voltage operations console that exposes the approved multi-role workflow and project release process through tested, permission-aware pages.

**Architecture:** Keep Vue 3, Element Plus, Pinia, Axios, Vue Router, and SCSS. Pages own route-level loading and compose focused components; API modules mirror Laravel routes; reusable composables normalize pagination, permissions, and conflict handling. The backend remains the authority for permissions and allowed transitions, while the frontend hides unavailable commands and renders server-provided actions.

**Tech Stack:** Vue 3.4, Vue Router 4, Pinia 2, Element Plus 2, Axios 1, SCSS, Vite 5, Vitest 1, Vue Test Utils 2

## Global Constraints

- Complete Phase 1 and Phase 2 before starting this plan.
- Read `docs/superpowers/specs/2026-08-17-ipms-core-workflow-redesign-design.md` and the plan index before editing.
- Do not introduce a second component library or hand-drawn SVG icons. Use Element Plus icons.
- Use the supplied Voltage raster assets without modifying the originals.
- Keep cards at 8px radius or less and reserve cards for bounded tools or repeated records.
- Optimize for desktop widths of 1440 and 1024 pixels; every control and label must remain readable at both widths.
- Remove all mock records, fixed counters, fake success messages, and dead routes from core pages.
- Treat server-provided `allowed_actions` and Policy responses as authoritative; frontend checks are usability controls only.
- Do not initialize Git without approval. Commit steps assume the repository prerequisite in the plan index has been satisfied.

---

### Task 1: Voltage Brand Assets and Design Tokens

**Files:**
- Create: `ipms-frontend/src/assets/brand/voltage-wordmark.png`
- Create: `ipms-frontend/src/assets/brand/voltage-v.png`
- Create: `ipms-frontend/public/favicon.png`
- Create: `ipms-frontend/src/components/layout/BrandMark.vue`
- Create: `ipms-frontend/src/components/layout/BrandMark.test.js`
- Modify: `ipms-frontend/src/assets/styles/variables.scss`
- Modify: `ipms-frontend/src/assets/styles/global.scss`
- Modify: `ipms-frontend/index.html`

**Interfaces:**
- Consumes: `C:\Synology\Drive\img\logo-None R.png` and `C:\Synology\Drive\img\Logo V.png`.
- Produces: `<BrandMark compact />`, a stable brand asset location, and shared Voltage design tokens.

- [ ] **Step 1: Write the compact and expanded brand component tests**

```js
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BrandMark from './BrandMark.vue'

describe('BrandMark', () => {
  it('renders the wordmark in expanded mode', () => {
    const wrapper = mount(BrandMark)
    expect(wrapper.get('img').attributes('src')).toContain('voltage-wordmark.png')
    expect(wrapper.get('img').attributes('alt')).toBe('Voltage')
  })

  it('renders the V mark in compact mode', () => {
    const wrapper = mount(BrandMark, { props: { compact: true } })
    expect(wrapper.get('img').attributes('src')).toContain('voltage-v.png')
  })
})
```

- [ ] **Step 2: Run the component test red**

Run: `cd ipms-frontend && npm test -- src/components/layout/BrandMark.test.js`

Expected: FAIL because the component and project-owned assets do not exist.

- [ ] **Step 3: Copy the approved assets and implement the component**

Copy the two supplied PNG files byte-for-byte into the named frontend asset paths. Copy `voltage-v.png` to `public/favicon.png`. `BrandMark.vue` must use fixed-size wrappers and `object-fit: contain`; expanded mode uses a 132 by 28 pixel content box and compact mode uses a 30 by 30 pixel box.

- [ ] **Step 4: Replace the legacy color tokens**

Use this palette as the single source of truth in `variables.scss` and matching Element Plus CSS variables in `global.scss`:

```scss
$color-primary: #00467f;
$color-primary-hover: #075b9a;
$color-primary-soft: #e9f3fb;
$color-ink: #17212b;
$color-muted: #667482;
$color-canvas: #f4f7fa;
$color-border: #d9e1e8;
$color-success: #2f7d4a;
$color-warning: #a86408;
$color-danger: #c43d3d;
$sidebar-width: 208px;
$sidebar-collapsed-width: 64px;
$header-height: 56px;
$border-radius-md: 8px;
```

Remove the 12px global card and dialog radius. Set document title to `Voltage IPMS` and use `/favicon.png` in `index.html`.

- [ ] **Step 5: Run tests and build**

Run: `cd ipms-frontend && npm test -- src/components/layout/BrandMark.test.js && npm run build`

Expected: PASS and the production bundle contains both brand assets.

- [ ] **Step 6: Commit the design foundation**

```bash
git add ipms-frontend/src/assets ipms-frontend/src/components/layout/BrandMark.vue ipms-frontend/src/components/layout/BrandMark.test.js ipms-frontend/public/favicon.png ipms-frontend/index.html
git commit -m "feat: apply Voltage brand foundation"
```

### Task 2: Operations Console Shell and Role Navigation

**Files:**
- Create: `ipms-frontend/src/components/common/AsyncState.vue`
- Create: `ipms-frontend/src/components/common/AsyncState.test.js`
- Create: `ipms-frontend/src/components/common/ProfileDialog.vue`
- Create: `ipms-frontend/src/components/common/ProfileDialog.test.js`
- Create: `ipms-frontend/src/components/layout/GlobalSearch.vue`
- Create: `ipms-frontend/src/components/layout/GlobalSearch.test.js`
- Create: `ipms-frontend/src/composables/useApiError.js`
- Create: `ipms-frontend/src/composables/usePagination.js`
- Create: `ipms-frontend/src/composables/usePermission.test.js`
- Modify: `ipms-frontend/src/components/layout/Sidebar.vue`
- Modify: `ipms-frontend/src/components/layout/HeaderBar.vue`
- Modify: `ipms-frontend/src/components/layout/AppLayout.vue`
- Modify: `ipms-frontend/src/components/layout/BreadcrumbNav.vue`
- Modify: `ipms-frontend/src/composables/usePermission.js`
- Modify: `ipms-frontend/src/router/index.js`
- Delete: `ipms-frontend/src/views/settings/SettingsView.vue`

**Interfaces:**
- Consumes: authenticated user fields `roles[]`, `user_type`, and server `allowed_actions[]`.
- Produces: stable shell layout, valid profile editing, standardized loading/empty/error states, and role-specific menus.

- [ ] **Step 1: Write permission matrix tests**

Cover all approved role codes with table-driven cases:

```js
it.each([
  ['requester', ['/dashboard', '/requirements', '/defects']],
  ['supplier_dev', ['/dashboard', '/tasks', '/defects', '/documents']],
  ['supplier_tester', ['/dashboard', '/tasks', '/defects', '/documents']],
  ['supplier_pm', ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents']],
  ['it_member', ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents']],
  ['it_pm', ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents', '/audit-logs']],
])('shows the expected menu for %s', (role, paths) => {
  auth.user = { roles: [role], user_type: role === 'requester' ? 3 : role.startsWith('supplier_') ? 2 : 1 }
  expect(usePermission().visibleMenuItems.value.map((item) => item.index)).toEqual(paths)
})
```

Also assert that `super_admin` sees organization and audit administration and that no role receives links to `/profile` or the unsupported `/settings` page.

- [ ] **Step 2: Run permission and async-state tests red**

Run: `cd ipms-frontend && npm test -- src/composables/usePermission.test.js src/components/common/AsyncState.test.js src/components/common/ProfileDialog.test.js src/components/layout/GlobalSearch.test.js`

Expected: FAIL because the old code compares non-existent generic role names and `AsyncState` is absent.

- [ ] **Step 3: Implement exact role-code navigation**

Replace generic `member`, `developer`, `tester`, and `project_manager` comparisons with the seeded role codes. Remove the unsupported system-settings route and menu item. Add the project version route as a project child surface, not a top-level sidebar item. Keep destructive or state-changing buttons guarded by both a local role check and the resource's `allowed_actions` list.

- [ ] **Step 4: Implement shared states and error behavior**

`AsyncState.vue` must render one of four mutually exclusive states: loading skeleton, API error with retry button, empty state, or content slot. `useApiError()` must map `STALE_VERSION`, `RELEASE_GATE_FAILED`, `VERSION_LOCKED`, `FORBIDDEN`, and `UNAUTHENTICATED` to the behavior defined in the design. `usePagination()` must map `page_size` without translating it back to `pageSize`.

- [ ] **Step 5: Replace the invalid profile route**

Open `ProfileDialog.vue` from `HeaderBar.vue`. Submit profile changes through `PUT /settings/profile` and password changes through `PUT /settings/password`. Close only after a successful server response. When `mustChangePassword` is true, open the password tab as a non-dismissable dialog immediately after login and unlock navigation only after the server returns the updated user state. The header notification button remains present but displays zero until Phase 4 connects notification state.

Test normal profile open/close, validation preservation, failed save, successful save, and the non-dismissable first-login password case before continuing.

- [ ] **Step 6: Apply the Voltage shell**

Use `BrandMark` in the sidebar and keep the light canvas and white work surface. `GlobalSearch` waits for at least two characters, debounces for 250 milliseconds, requests project and requirement lists with `keyword` and `page_size=5`, groups only scoped server results, and navigates to the selected record. Test stale-response suppression and keyboard selection. Keep icon buttons at a stable 36 by 36 pixel size with tooltips. Ensure the collapsed shell shows the V mark.

- [ ] **Step 7: Run the shell test suite and build**

Run: `cd ipms-frontend && npm test && npm run build`

Expected: PASS, no router warning for `/profile`, and no fixed notification count.

- [ ] **Step 8: Commit the application shell**

```bash
git add ipms-frontend/src/components ipms-frontend/src/composables ipms-frontend/src/router ipms-frontend/src/views/settings/SettingsView.vue
git commit -m "feat: build role-aware operations shell"
```

### Task 3: Role-Aware Dashboard with Real Data

**Files:**
- Create: `ipms-backend/tests/Feature/Api/DashboardContractTest.php`
- Create: `ipms-frontend/src/views/dashboard/DashboardView.test.js`
- Modify: `ipms-backend/app/Http/Controllers/Api/DashboardController.php`
- Modify: `ipms-backend/routes/api.php`
- Modify: `ipms-frontend/src/api/dashboard.js`
- Modify: `ipms-frontend/src/views/dashboard/DashboardView.vue`

**Interfaces:**
- Consumes: scoped requirements, tasks, defects, project versions, and current user role.
- Produces: `GET /api/dashboard/summary` with `metrics`, `priority_queue`, and `release_risks`.

- [ ] **Step 1: Write the backend dashboard scope contract**

Create an internal IT PM, supplier developer, and requester with records inside and outside their project scopes. Assert that `/api/dashboard/summary` never includes an inaccessible record and returns these exact keys:

```php
['code', 'message', 'data' => [
    'metrics',
    'priority_queue',
    'release_risks',
]]
```

Each queue item must contain `type`, `id`, `title`, `project`, `due_at`, `severity`, and `target_url`.

- [ ] **Step 2: Run the dashboard contract red**

Run: `cd ipms-backend && php artisan test --filter DashboardContractTest`

Expected: FAIL because `/dashboard/summary` is not registered and the current endpoints do not include release risks.

- [ ] **Step 3: Implement the scoped summary endpoint**

Add `GET /api/dashboard/summary`. Use existing model scopes and resource policies. Build role-specific metrics for pending reviews, due tasks, pending defects, unplanned requirements, and blocked releases. Limit both queues to 10 items ordered by risk and due date. Return empty arrays rather than synthetic records.

- [ ] **Step 4: Write the dashboard component test**

Mock one summary per role and assert metric labels change by role, queue links use `target_url`, a failed request renders retry, and empty queues render an empty state. Assert that no static names or dates from the old mock data are rendered.

- [ ] **Step 5: Implement the dashboard view**

Use a compact metric strip followed by two unframed work bands: `优先处理` and `发布风险`. Do not use a welcome hero. Show the current role next to the page title, and load only `/dashboard/summary`.

- [ ] **Step 6: Run backend and frontend dashboard tests**

Run: `cd ipms-backend && php artisan test --filter DashboardContractTest`

Run: `cd ipms-frontend && npm test -- src/views/dashboard/DashboardView.test.js`

Expected: PASS.

- [ ] **Step 7: Commit the real dashboard**

```bash
git add ipms-backend/app/Http/Controllers/Api/DashboardController.php ipms-backend/routes/api.php ipms-backend/tests/Feature/Api/DashboardContractTest.php ipms-frontend/src/api/dashboard.js ipms-frontend/src/views/dashboard
git commit -m "feat: add role-aware workflow dashboard"
```

### Task 4: Project Detail and Version List

**Files:**
- Create: `ipms-frontend/src/api/projectVersion.js`
- Create: `ipms-frontend/src/api/projectVersion.test.js`
- Create: `ipms-frontend/src/components/releases/VersionStatusTag.vue`
- Create: `ipms-frontend/src/components/releases/VersionListPanel.vue`
- Create: `ipms-frontend/src/components/releases/VersionListPanel.test.js`
- Create: `ipms-frontend/src/views/projects/ProjectVersionsView.vue`
- Modify: `ipms-frontend/src/views/projects/ProjectDetail.vue`
- Modify: `ipms-frontend/src/router/index.js`

**Interfaces:**
- Consumes: Phase 2 project version endpoints and real project detail statistics.
- Produces: `/projects/:projectId/versions` and a `发布版本` tab within the project workspace.

- [ ] **Step 1: Write exact project-version API tests**

Assert methods and paths for list, create, show, update, delete, status, gate check, release, history, plan, and unplan. Plan uses `PUT /requirements/{requirementId}/projects/{projectId}/version`; unplan uses `DELETE` on the same path with Axios `config.data`. The unplanned pool uses `GET /requirements` with `project_id`, `version_scope=unplanned`, `page`, and `page_size`. Verify version list parameters remain `page`, `page_size`, `status`, `owner_id`, `planned_release_from`, `planned_release_to`, and `keyword`.

- [ ] **Step 2: Run API and panel tests red**

Run: `cd ipms-frontend && npm test -- src/api/projectVersion.test.js src/components/releases/VersionListPanel.test.js`

Expected: FAIL because the module and components do not exist.

- [ ] **Step 3: Implement the API module and version status presentation**

Map the seven server `status_code` values to restrained semantic colors and display the server `status_label`. Keep `RELEASED` and `ARCHIVED` neutral/success rather than bright primary blue. `VersionListPanel` must show code, name, status, owner, planned date, requirement count, gate summary, and a single clear row action.

- [ ] **Step 4: Replace project detail mock data**

Load project details from `GET /projects/{id}` and use response statistics. Use tabs for `项目概览`, `发布版本`, `关联需求`, and `近期缺陷`. Preserve the active tab in `?tab=` and route the version tab to `/projects/:projectId/versions`. Do not retain any hardcoded SAP examples.

- [ ] **Step 5: Implement version filtering and creation**

The version page must support status, owner, planned date range, and keyword filters; reset returns to page 1. The create dialog accepts `code`, `name`, `description`, `owner_id`, `planned_start_date`, and `planned_release_date`. Show field-level `VERSION_CODE_EXISTS` feedback without clearing the form.

- [ ] **Step 6: Run project version tests and build**

Run: `cd ipms-frontend && npm test -- src/api/projectVersion.test.js src/components/releases/VersionListPanel.test.js && npm run build`

Expected: PASS.

- [ ] **Step 7: Commit project version navigation**

```bash
git add ipms-frontend/src/api/projectVersion.js ipms-frontend/src/api/projectVersion.test.js ipms-frontend/src/components/releases ipms-frontend/src/views/projects ipms-frontend/src/router/index.js
git commit -m "feat: add project release version workspace"
```

### Task 5: Version Detail, Gates, Planning, and Release Commands

**Files:**
- Create: `ipms-frontend/src/components/releases/VersionProgress.vue`
- Create: `ipms-frontend/src/components/releases/ReleaseGatePanel.vue`
- Create: `ipms-frontend/src/components/releases/RequirementPlanner.vue`
- Create: `ipms-frontend/src/components/releases/VersionHistory.vue`
- Create: `ipms-frontend/src/components/releases/ReleaseDialog.vue`
- Create: `ipms-frontend/src/components/releases/VersionDetail.test.js`
- Create: `ipms-frontend/src/views/releases/ProjectVersionDetail.vue`
- Modify: `ipms-frontend/src/router/index.js`

**Interfaces:**
- Consumes: version detail `allowed_actions`, `lock_version`, gate results, requirement scope, and history.
- Produces: `/project-versions/:id`, guarded status transitions, single-target planning, and formal/forced release flows.

- [ ] **Step 1: Write behavior tests for the release workspace**

Cover these cases:

```text
seven statuses render in order
released data is read-only
failed gates render every blocking item
planning uses requirementId and projectId in the path and sends project_version_id plus lock_version
testing-stage replanning requires a reason
stale updates reload before another confirmation
normal release has no force reason field
superadmin force release requires a non-empty reason
non-superadmins never see the force release command
force release is visible only in IN_TESTING or READY_TO_RELEASE
```

- [ ] **Step 2: Run the detail tests red**

Run: `cd ipms-frontend && npm test -- src/components/releases/VersionDetail.test.js`

Expected: FAIL because the release components are absent.

- [ ] **Step 3: Implement the version progress and gate panels**

`VersionProgress` uses a stable seven-column track at 1440 pixels and a horizontally scrollable track at 1024 pixels. `ReleaseGatePanel` displays the server result for scope, tasks, defects, release notes, and planned date. Every failed item includes its count and target link; do not reduce the result to a single pass/fail badge.

- [ ] **Step 4: Implement requirement planning**

`RequirementPlanner` loads the current version scope and the project's unplanned pool. Plan and unplan actions include `lock_version`. When the version is `IN_TESTING`, show a mandatory reason input; when `READY_TO_RELEASE`, `RELEASED`, or `ARCHIVED`, render read-only data and no edit controls.

- [ ] **Step 5: Implement transition and release dialogs**

Render actions from `allowed_actions`. Confirm state changes with the destination status and impact. For `RELEASE_GATE_FAILED`, keep the dialog open and focus the gate tab. For `STALE_VERSION`, close the dialog, reload all version data, and show a conflict message. Forced release is a separate superadmin command with a required reason and an explicit warning that the override is audited.

- [ ] **Step 6: Implement history and immutable release snapshot display**

Combine status and scope history chronologically using the server's typed entries. Show actor, timestamp, reason, old/new values, and forced-release marker. Use the release snapshot when displaying released scope and gate counts.

- [ ] **Step 7: Run release component tests and build**

Run: `cd ipms-frontend && npm test -- src/components/releases/VersionDetail.test.js && npm run build`

Expected: PASS with no Vue warnings.

- [ ] **Step 8: Commit release operations**

```bash
git add ipms-frontend/src/components/releases ipms-frontend/src/views/releases ipms-frontend/src/router/index.js
git commit -m "feat: implement release gates and version controls"
```

### Task 6: Cross-Project Requirement Detail

**Files:**
- Create: `ipms-frontend/src/components/requirements/ProjectDeliveryTable.vue`
- Create: `ipms-frontend/src/components/requirements/ProjectDeliveryTable.test.js`
- Modify: `ipms-frontend/src/views/requirements/RequirementDetail.vue`
- Modify: `ipms-frontend/src/api/requirement.js`

**Interfaces:**
- Consumes: requirement aggregate status and `project_deliveries[]` from Phase 2.
- Produces: a requirement detail view that distinguishes global progress from each project's independent delivery state and target version.

- [ ] **Step 1: Write the cross-project rendering test**

Provide one requirement associated with two projects, one `IN_DEVELOPMENT` and one `DEPLOYED`, with different target versions. Assert that both project rows and version links render, while the aggregate status appears once in the overview.

- [ ] **Step 2: Run the test red**

Run: `cd ipms-frontend && npm test -- src/components/requirements/ProjectDeliveryTable.test.js`

Expected: FAIL because the component does not exist.

- [ ] **Step 3: Implement project delivery presentation**

Columns are project, delivery status, target version, project owner, task progress, open severe defects, and action. Use the target version link only when the current user can view that project. Do not infer project state from the global requirement status.

- [ ] **Step 4: Recompose requirement detail**

Use sections for overview, project delivery, tasks, defects, attachments, and requirement revision history. Review actions appear only when included in `allowed_actions`; project-side state changes send `project_id` to the Phase 2 requirement status endpoint.

- [ ] **Step 5: Run requirement tests and build**

Run: `cd ipms-frontend && npm test -- src/components/requirements/ProjectDeliveryTable.test.js && npm run build`

Expected: PASS.

- [ ] **Step 6: Commit cross-project requirement UI**

```bash
git add ipms-frontend/src/components/requirements ipms-frontend/src/views/requirements/RequirementDetail.vue ipms-frontend/src/api/requirement.js
git commit -m "feat: show independent project delivery progress"
```

### Task 7: Core Requirement, Task, and Defect Work Queues

**Files:**
- Create: `ipms-frontend/src/components/common/FilterBar.vue`
- Create: `ipms-frontend/src/components/common/PaginatedTable.vue`
- Create: `ipms-frontend/src/views/core-work-queues.test.js`
- Modify: `ipms-frontend/src/views/requirements/RequirementList.vue`
- Modify: `ipms-frontend/src/views/tasks/TaskList.vue`
- Modify: `ipms-frontend/src/views/defects/DefectList.vue`
- Modify: `ipms-frontend/src/components/common/StatusTag.vue`

**Interfaces:**
- Consumes: standardized paginated responses and each record's `allowed_actions`.
- Produces: consistent searchable work queues with real create, review, assign, transition, and defect lifecycle actions.

`StatusTag` consumes `status_code` for semantic styling and `status_label` for text. It does not translate numeric backend statuses with page-local maps.

- [ ] **Step 1: Write shared queue tests**

For each page, verify request parameters use `page_size`, reset clears filters and page, loading and empty states are exclusive, 403 does not leak row actions, and each successful workflow action reloads the current page. Add one full component test for task claim and one for defect verify/reopen.

- [ ] **Step 2: Run queue tests red**

Run: `cd ipms-frontend && npm test -- src/views/core-work-queues.test.js`

Expected: FAIL against the current page-specific pagination and action behavior.

- [ ] **Step 3: Implement shared filtering and pagination**

Use compact filter rows, a visible reset icon button with tooltip, and one pagination footer shape. Keep filters in route query parameters so refresh preserves a work queue. Avoid nested cards around tables.

- [ ] **Step 4: Connect all workflow actions**

Requirements support submit, edit, review, and project-side transitions. Tasks support create, claim, assign, start, complete, and hold according to `allowed_actions`. Defects support create, confirm, assign, resolve, verify, and reopen. Confirmation text must name the record and resulting state.

- [ ] **Step 5: Run queue tests and the complete frontend suite**

Run: `cd ipms-frontend && npm test && npm run build`

Expected: PASS, and source scans find no mock arrays or commented-out API calls in the three work queue views.

- [ ] **Step 6: Commit the core work queues**

```bash
git add ipms-frontend/src/components/common ipms-frontend/src/views ipms-frontend/src/components/common/StatusTag.vue
git commit -m "feat: connect core workflow work queues"
```

### Task 8: Visual and Accessibility Verification

**Files:**
- Create: `docs/qa/voltage-ui-checklist.md`
- Modify: frontend files identified by the visual pass

**Interfaces:**
- Consumes: production frontend build and deterministic multi-role seed accounts.
- Produces: a recorded desktop visual check at both required viewports.

- [ ] **Step 1: Start the local backend and frontend using test-safe environment values**

Run the Laravel server on an unused local port and Vite on another unused port. Do not use the cloud server for this task.

- [ ] **Step 2: Inspect the application at 1440 by 900**

Check login, expanded and collapsed shell, dashboard, project versions, version detail, requirement detail, each work queue, dialogs, errors, and empty states. Record pass/fail for text clipping, overlap, unexpected horizontal page scroll, missing logos, and inaccessible controls.

- [ ] **Step 3: Repeat at 1024 by 768**

The version progress track may scroll inside its own region. The sidebar, header, filters, tables, dialogs, and action controls must not overlap. Fix any issue and repeat the affected screenshot.

- [ ] **Step 4: Run final Phase 3 verification**

Run: `cd ipms-backend && php artisan test`

Run: `cd ipms-frontend && npm test && npm run build`

Expected: all backend and frontend tests pass and the production build exits 0.

- [ ] **Step 5: Commit visual corrections and checklist**

```bash
git add ipms-frontend docs/qa/voltage-ui-checklist.md
git commit -m "test: verify Voltage workflow interface"
```

## Phase 3 Completion Gate

The phase is complete only when:

```text
all core pages use live API data
all seven approved role codes have tested navigation
project versions and release gates are operable from the UI
cross-project requirements show independent delivery state
there are no fixed notification counts, dead profile links, fake saves, or mock core records
frontend tests and production build pass
1440x900 and 1024x768 visual checks pass
```
