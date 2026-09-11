# Voltage UI Verification

Date: 2026-09-11. Scope: Phase 3 work queues and supplementary data-backed pages.

All source access, builds, tests and Chromium execution took place on Ubuntu in
`/srv/itpms-dev/repo`. The user's cloud-only constraint supersedes the plan's
local-server rehearsal instruction. No local project environment was installed.

## Verified

- Backend: 335 tests, 2897 assertions passed.
- Frontend: 24 files, 165 tests passed; Vite production build passed.
- `ops/qa-demo.cjs`: PASS, eight main pages at 1440x900, 1024x768 and 390x844.
- Seven seeded roles log in; non-superadmins are redirected away from organizations.
- Brand PNGs load; no page-level horizontal overflow or uncaught browser exceptions.
- Login, expanded shell, lists and empty states captured with real server responses.
- `ops/qa-actions.cjs`: PASS, requester submits a two-project requirement, IT PM
  approves, creates separate project versions and plans each scope, supplier PM
  assigns a task using database users, and the assigned developer starts it.
- Requirement detail, version list and version detail captured at both desktop sizes.
- Documents, organizations and audit screens query persisted data; file operations,
  API-document operations, organization membership, scoped audits and assignment
  permissions also have focused backend/frontend regression tests.
- No mock record arrays or commented-out API calls remain in frontend runtime code.
- Fixed notification count removed. Notification command is explicitly disabled
  until the Phase 4 notification UI is implemented.

## Corrections During Rehearsal

- Bootstrap creates private session/cache/view storage directories.
- API-only backend skips Blade caching when no views directory exists.
- Demo preparation supports an already-seeded database without duplicate roles.
- Authentication health check added alongside framework health.
- First-time requesters receive only project ID/name submission options, not project details.
- Legacy supplier user-management branches cannot bypass the approved superadmin-only directory.
- Mobile shell uses dismissible navigation and a compact Voltage mark.
- Duplicate empty-state captions removed.

Evidence remains on the cloud host under `/srv/itpms-dev/tools/qa-demo`;
test/build logs are `/tmp/ipms-*-full.log` and `/tmp/ipms-build.log`.
Screenshots contain demo data only; credentials are not captured.

## Remaining Phase 4 Acceptance

This is not the final full-system acceptance. Outlook delivery, workflow-triggered
notifications, reminders, production deployment hardening/rollback/restore rehearsal,
and the complete seven-role release/defect/notification E2E are tracked by the Phase 4 plan.
The present browser action test ends at task start, not formal release.
IP-only HTTP is demo-only; domain/TLS and high-privilege credential rotation remain
prerequisites for production acceptance.
