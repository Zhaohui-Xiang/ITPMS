# P1 Workflow Repair Implementation Plan

**Goal:** Close the three audited UI workflow gaps without weakening existing gates.
**Architecture:** Extend existing Laravel policies/resources and Vue project/requirement/version views; use real database APIs. Keep version metadata on the existing optimistic-lock update API.
**Tech stack:** Laravel, PostgreSQL, Vue 3, Element Plus, Vitest, cloud Playwright.

**Status (2026-09-22):** 全部代码与自动化测试完成 — 后端 382 tests / 3212 assertions 通过，前端 30 files / 263 tests 通过。剩余：真实浏览器多角色 QA（ops/qa-p1-ui.cjs）与部署提升。已提交并推送 feature/core-workflow-redesign。

## Constraints
- All project operations only in /srv/itpms-dev/repo on the cloud server.
- Continue the existing isolated feature/core-workflow-redesign branch from 41a2b96.
- No demo reset, no local installation, no credentials in source, no GitHub push.
- Preserve assigned internal IT PM version ownership and superadmin exception boundaries.
- Existing approved workflow design applies; this repairs missing interfaces, not gate rules.
- Write failing regression tests before implementations; serialize backend suites with flock.

## Task 1: Project Membership
- [x] Add ProjectMembershipTest covering authorization, eligible candidates, persisted add/remove, deduplication, protected manager, audit and tester retest.
- [x] Add ProjectPolicy.manageMembers for superadmin or assigned active internal IT PM; archived projects reject mutation.
- [x] Add ProjectMemberController GET members, GET member-options, POST members, DELETE members/{userId}. Return minimal user summaries only; no role privilege updates.
- [x] Offer active internal IT staff and supplier staff in the project's existing supplier tree; reject disabled, requester and unrelated supplier accounts.
- [x] Expose manage_members action and ProjectMembers panel in project detail; retry/loading/failure states, add/remove confirmations.
- [ ] Run focused backend/frontend tests and real browser add/reload/remove/re-add.

## Task 2: Requirement Execution Owner
- [x] Add RequirementExecutionOwnerTest for scoped options, assignment authorization, invalid candidates, revision persistence and stale writes.
- [x] GET requirements/{id}/execution-owner-options requires assignment permission; candidates must be active IT/delivery staff with access to every linked project.
- [x] Secure existing update API dev_lead_id against unauthorized changes. Optional version token is checked under existing requirement locks; UI always sends it.
- [x] Add assign_owner action and ExecutionOwnerEditor in requirement detail, display current owner and retain errors; stale response requires review/reload.
- [x] Update old requester edit tests to retain project/revision coverage without granting execution-owner assignment.
- [ ] Run focused tests and real browser assign/reload/check development gate.

## Task 3: Pre-release Version Metadata (delegated)
- [x] Add failing frontend tests, then integrate VersionEditDialog into ProjectVersionDetail.
- [x] Edit release notes/name/description/dates with lock_version, only permitted pre-release states.
- [x] Preserve published snapshot and stale409 semantics; no auto-retry overwrite.
- [ ] Run focused frontend tests and browser edit/reload/ready gate.

## Task 4: Verification and Deployment
- [x] Review changes and run full backend/frontend/build suites.
- [ ] Prepare candidate and run fresh-project multi-role workflow with all three configuration steps via UI.
- [ ] Exercise task/defect/release/acceptance UI; assert wrong-role API denials separately.
- [ ] Promote candidate only after passing tests; public smoke, update audit report and commit.
