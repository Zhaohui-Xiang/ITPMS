# IPMS Core Workflow Implementation Plan Index

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement these plans task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver the approved multi-role IPMS core workflow, Voltage UI, project release versions, notifications, and repeatable Ubuntu deployment as four independently reviewable increments.

**Architecture:** Keep the existing Vue 3 and Laravel 11 applications. Stabilize authentication and API contracts first, implement the backend workflow second, replace the frontend workflow third, then add notifications, production deployment, and end-to-end verification.

**Tech Stack:** Vue 3, Vue Router, Pinia, Element Plus, Axios, Vite 5, Vitest, Vue Test Utils, Playwright, PHP 8.2, Laravel 11, Sanctum 4, PHPUnit, PostgreSQL 15, Redis, Nginx, PHP-FPM, Supervisor

## Global Constraints

- The approved specification is `docs/superpowers/specs/2026-08-17-ipms-core-workflow-redesign-design.md`.
- Preserve Vue 3, Element Plus, Laravel 11, Sanctum, PostgreSQL, and Redis; do not introduce a second UI framework or backend framework.
- Use test-first development for every behavior change: run the targeted test red, implement the minimum behavior, then run the targeted and relevant full suites green.
- The cloud database contains no business data that must be preserved; reset and seed are allowed only in the explicit deployment task.
- Use same-origin Sanctum SPA session authentication; do not store a long-lived API token in `localStorage`.
- Use the provided Voltage wordmark and V icon without modifying the source images.
- Project release versions are project-scoped, use project-defined codes, have seven statuses, and enforce one target version per requirement-project association.
- Only internal IT project managers advance or release versions; super administrators may override business gates only with a reason.
- Send in-app notifications for workflow events and Outlook mail only for approved key events.
- Target Ubuntu 22.04; use Nginx with PHP-FPM, PostgreSQL 15, Redis, Supervisor, and Laravel Scheduler. Do not run `php artisan serve` in production.
- Do not store server passwords, Microsoft Entra client secrets, database passwords, or seeded production passwords in tracked files.
- The current workspace root is not a Git repository. Before execution, obtain approval to initialize it or identify the intended repository. Do not run `git init` implicitly.

---

## Execution Order

- [ ] **Phase 1: Foundation, authentication, and API contracts**

  Execute [2026-08-17-ipms-foundation-auth-contract-plan.md](2026-08-17-ipms-foundation-auth-contract-plan.md). Deliverable: repeatable backend/frontend test harnesses, same-origin login, normalized response/pagination contracts, corrected frontend action methods, and deterministic multi-role seed data.

- [ ] **Phase 2: Backend core workflow and project releases**

  Execute [2026-08-17-ipms-core-workflow-backend-plan.md](2026-08-17-ipms-core-workflow-backend-plan.md). Deliverable: project-side requirement states, project versions, release gates, atomic release, permissions, history, snapshots, and tested APIs.

- [ ] **Phase 3: Voltage core frontend**

  Execute [2026-08-17-ipms-voltage-core-frontend-plan.md](2026-08-17-ipms-voltage-core-frontend-plan.md). Deliverable: branded shell, role-aware dashboard, real project/requirement/task/defect pages, and complete project version UI.

- [ ] **Phase 4: Notifications, production deployment, and E2E acceptance**

  Execute [2026-08-17-ipms-notifications-deployment-e2e-plan.md](2026-08-17-ipms-notifications-deployment-e2e-plan.md). Deliverable: in-app notification center, Outlook queue jobs, reminder scheduler, hardened Ubuntu deployment, and Playwright multi-role acceptance.

## Execution Prerequisites

1. Establish the Git repository boundary, then use `superpowers:using-git-worktrees` before implementation.
2. Provide PHP 8.2, Composer, PostgreSQL 15, Redis, Node.js 20, and npm in the execution environment.
3. Create a dedicated PostgreSQL test database named `ipms_test`; never point automated tests at the production database.
4. Copy the two user-provided Voltage assets from their current local source into the repository only in Phase 3.
5. Rotate the disclosed server root password before Phase 4 and configure SSH key access for a non-root deployment account.

## Phase Gates

- Phase 2 may start only after Phase 1 backend and frontend suites pass.
- Phase 3 may start after Phase 2 publishes the documented API contract; backend UI-independent tests must remain green throughout Phase 3.
- Phase 4 may start after the frontend production build and all backend/frontend tests pass.
- Cloud reset and deployment occur only after local or isolated staging verification and an explicit backup command succeeds.
