# ITPMS System Audit - 2026-09-17

## Acceptance Decision

**BLOCKED: the system has not passed complete multi-role, UI-only acceptance.**

The three reported administration issues are fixed and deployed to the public demo.
Backend workflow checks pass with preconfigured project membership and two API-only
setup steps. Those results do not establish that a new project can complete the
workflow entirely through the UI.

Three independent QA agents were requested, but every agent failed with workspace
credits unavailable before producing findings. This report is main-agent fallback
testing and review, not an independent-agent certification.

## Open Findings

### P1-01: No requirement execution-owner editor

- Reproduction: submit and approve a requirement, assign it to a project version,
  and attempt to move the version into development using only the UI.
- The backend rejects the transition with HTTP 409 because the requirement has no
  execution owner. The gate exposes missing_execution_owner_requirement_project_ids.
- Evidence: ipms-backend/app/Services/ReleaseGateService.php:226-235 checks
  requirement.dev_lead_id. The update API supports the field, but no corresponding
  field exists under ipms-frontend/src.
- Impact: the normal version lifecycle cannot start entirely from the UI.
- The control test explicitly sets dev_lead_id through the authorized API. This is
  a test setup step, not a product fix.
- Required remediation: an authorized execution-owner selector with role/project
  validation and error handling. Keep the release gate enforced.

### P1-02: No project membership management

- Reproduction: create a new external project in the UI, assign its IT PM and
  supplier organization, create and resolve a defect, then retest as supplier_tester.
- Actual result: POST /api/defects/2/verify returned HTTP 403 in QA project 5.
- Project creation only inserts the creating administrator into project membership.
  No project-member management route or UI is available.
- Evidence: ProjectController.php:61 and DefectPolicy.php:105-115. Retest correctly
  requires the tester to be a project member; supplier affiliation alone is insufficient.
- Impact: newly created projects cannot complete the supplier retest workflow.
- Seeded project 1 has existing membership and passes the lifecycle control test.
  Its existing membership was not modified for this audit.
- Required remediation: authorized project membership management. Do not weaken
  the defect verification authorization to make the test pass.

### P1-03: Release notes cannot be edited before the ready gate

- Reproduction: create and complete a version without API-provided release notes,
  then attempt READY_TO_RELEASE.
- Actual result: HTTP 409, release_notes_present gate fails.
- Evidence: ReleaseGateService.php:385-400 requires notes for ready targets.
  The version creation UI has no release_notes input; the detail page only displays
  notes. The release dialog is reached after the ready transition.
- Impact: a normally created version cannot reach the release action through the UI.
- The passing control creates the version with release_notes through the API.
- Required remediation: editable pre-release version metadata with optimistic
  concurrency handling. Preserve the mandatory release-note gate.

## Fixed and Deployed

1. Project list now exposes a superadmin-only creation dialog backed by real user,
   organization and project APIs. IT PM selection filters active internal IT PMs.
   Creation persists after reload; nonadministrators still receive API 403.
2. Each project list row links directly to its project-scoped release versions.
   The existing project detail entry remains available. A separate top-level
   version menu was not added because versions are project-scoped by design.
3. Organization seeding no longer inserts explicit identity values. A forward-only
   migration repairs legacy PostgreSQL sequence drift under a table lock, without
   deleting rows or rewinding an already advanced sequence.
4. Document endpoints now enforce both module permissions and project visibility.
   Requesters cannot upload, edit or read project documents through direct API calls
   merely because their submitted requirement gives them project visibility.
5. API document creation uses the seeded document.edit_api permission instead of
   the nonexistent document.create permission. Null parameter arrays are normalized
   to empty arrays; cross-project folder and requirement associations are rejected.
6. Document buttons now follow the same permission names and optional server actions.

Regression tests for these fixes were observed failing before implementation and
passing afterward. No demo database reset was performed.

## Verification Evidence

| Layer | Environment and date | Result |
| --- | --- | --- |
| Backend full suite | Cloud ipms_test, 2026-09-17 | 355 passed, 2978 assertions |
| Frontend full suite | Cloud Vitest, 2026-09-17 | 171 passed in 24 files |
| Admin UI regressions | Public demo, 2026-09-17 | 3 groups passed; actual create/reload operations |
| Responsive page/role smoke | Public demo, 2026-09-17 | 24 page checks, 7 roles, errors=[] |
| Notification API smoke | Public demo, 2026-09-17 | 5 checks passed |
| New-project lifecycle baseline | Candidate, 2026-09-15 | Failed at tester retest, HTTP 403; 93 requests |
| Seeded-member lifecycle control | Candidate, 2026-09-17 | 9 API groups passed, 108 requests; UI acceptance remains blocked |
| Source/runtime comparison | Candidate, 2026-09-17 | Backend app/database and frontend dist match source/build |
| Whitespace check | Cloud git diff --check | Passed |

The 24 page checks are a smoke matrix across viewports, not a claim that every
possible interaction in every module has been tested. Public and candidate now
serve the same promoted runtime release.

The seeded-member control covers seven logins and authorization boundaries;
requirement submission/review; single target version assignment; stale-write
rejection; task completion; defect confirmation/assignment/fix/failed retest/
successful retest; blocking release gates; normal release and snapshot replay;
acceptance authorization; file upload/download byte equality; API document
create/update/history/export; audit CSV export; notification reads; and logout.

Important limitations:
- Most lifecycle mutations in the broad audit use the real authenticated APIs.
  Login, selected version views and administration CRUD use browser UI.
- API-provided execution owner and pre-release notes are explicitly recorded gaps.
- Seeded membership is a control fixture, not proof that a new project can be set up.
- No direct database mutation bypass was used for business workflow transitions.
- Expected negative HTTP responses are asserted, not counted as unexpected errors.

## Test Records and Artifacts

Public UI regression on 2026-09-17 created project 6
(QA-system-1789607933110) and organization 8, both verified after reload.

The latest seeded lifecycle control created requirement 11, project version 11,
task 7, defect 4, document 6 and API document 8. Earlier QA-prefixed records were
retained in the demo database; no real user data was deleted.

Cloud-only artifacts:
- /tmp/ipms-audit-backend-20260917.log
- /tmp/ipms-audit-frontend-20260917.log
- /tmp/ipms-public-admin-20260917.log
- /tmp/ipms-public-pages-20260917.log
- /tmp/ipms-system-audit-seeded-final.log
- /srv/itpms-dev/tools/qa-admin-regressions/report.json
- /srv/itpms-dev/tools/qa-admin-regressions/project-create-mobile.png
- /srv/itpms-dev/tools/qa-demo/report.json
- /srv/itpms-dev/tools/qa-system-audit/report.json (new-project failing baseline)
- /srv/itpms-dev/tools/qa-system-audit/report-seeded.json (passing API control)
- /srv/itpms-dev/tools/qa-system-audit/version-scope.png
- /srv/itpms-dev/tools/qa-system-audit/release-snapshot.png

The artifact named organization-error.png preserves the original failure screenshot;
it is not the current public result. Reports/screenshots are mutable on rerun.

## Deployment

- Public demo: http://116.62.44.192
- Cloud source: /srv/itpms-dev/repo
- Branch: feature/core-workflow-redesign
- Source baseline: 2516b08 plus the administration/document fixes in this report.
- Promoted on 2026-09-17: /var/www/ipms/releases/demo-20260915035215
- Previous release: /var/www/ipms/releases/demo-20260911081944
- Pre-migration backup: /var/backups/ipms/pre-admin-fix-20260915033624.dump
- Backup archive listing was verified readable; a full restore rehearsal was not run.
- Promotion configuration backup: /var/backups/itpms-demo.6LJhT9GP
- Nginx configuration, application health and CSRF endpoints passed promotion checks.
- All code changes, builds, tests and Playwright execution ran on the cloud server.
  The local workstation was used only as an SSH transport and for reading agent skills.
- GitHub was not synchronized. Earlier TLS connectivity issues and legacy credential
  references in repository history require review before publication.

This remains a demo deployment, not production readiness approval.

## Reproduction Commands

Run on the cloud server as the deployment user:

```bash
cd /srv/itpms-dev/repo/ipms-backend
flock /tmp/itpms-backend-tests.lock php artisan test

cd /srv/itpms-dev/repo/ipms-frontend
npm test -- --run

cd /srv/itpms-dev/repo
IPMS_QA_URL=http://116.62.44.192 node ops/qa-admin-regressions.cjs
IPMS_QA_URL=http://116.62.44.192 node ops/qa-demo.cjs
IPMS_QA_URL=http://116.62.44.192 node ops/qa-notifications.cjs

# These create uniquely named QA records; default target is the candidate.
node ops/qa-system-audit.cjs
IPMS_QA_SEEDED_PROJECT=1 node ops/qa-system-audit.cjs
```

Run admin regressions before the broad audit, which reads its created project ID.
The new-project audit is expected to fail until P1-02 is resolved. The seeded audit
can exit zero for API checks while its report explicitly says BLOCKED_UI_GAPS.
Passwords are read by the scripts from the protected server environment and are not
stored in this report.

## Remaining Coverage and Release Work

- Resolve P1-01, P1-02 and P1-03, then rerun a fresh-project, UI-only multi-role flow.
- Obtain independent QA/code review when agent execution becomes available.
- Exercise the superadmin exception flow end-to-end in the browser; backend tests
  cover it, but this audit did not execute a live full override scenario.
- Complete Phase 4 business notification triggers, reminders and notification-center
  UI. Existing notification API checks do not prove business event delivery.
- Configure and verify actual Outlook/Graph delivery. No external mail was sent.
- Domain/TLS, credential rotation, queue operations, load/security testing and a
  full backup/restore rehearsal remain production-readiness work.
- Prioritize the three P1 workflow gaps before extending Phase 4 functionality.
