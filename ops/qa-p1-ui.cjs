'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium } = require('/srv/itpms-dev/tools/browser/node_modules/playwright');

const repo = '/srv/itpms-dev/repo';
const candidate = '/var/www/ipms/candidate';
const baseURL = process.env.IPMS_QA_URL || 'http://127.0.0.1:8082';
const expectedCandidate = process.env.IPMS_QA_EXPECTED_RELEASE;
const runId = new Date().toISOString().replace(/[:.]/g, '-') + '-' + process.pid;
const out = path.join('/srv/itpms-dev/tools/qa-p1-ui', runId);
const preflightOnly = process.argv.includes('--preflight');
const membershipOnly = process.argv.includes('--membership-only');
const report = {
  status: 'RUNNING', scope: membershipOnly ? 'fresh-project-membership' : 'fresh-project-membership-owner-version',
  baseURL, runId, out,
  mode: preflightOnly ? 'preflight' : membershipOnly ? 'membership-only' : 'p1-checkpoints',
  pendingCoverage: ['full-task-lifecycle', 'defect-lifecycle', 'release-gates-and-release', 'business-acceptance'],
  started: new Date().toISOString(), checks: [], diagnostics: [], screenshots: [], layouts: [], uiWrites: [],
};
let password = '';
let browser;
let activePage;
let phase = 'preflight';

const sha = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const digest = file => sha(fs.readFileSync(file));
const safeError = error => {
  const message = String(error.message || error);
  return password ? message.split(password).join('[REDACTED]') : message;
};
function files(root) {
  return fs.readdirSync(root, { withFileTypes: true }).flatMap(entry => {
    const file = path.join(root, entry.name);
    assert(!entry.isSymbolicLink(), 'Unexpected symlink in checksum tree: ' + file);
    return entry.isDirectory() ? files(file) : [file];
  }).sort();
}
function tree(root) {
  return files(root).map(file => [path.relative(root, file), digest(file)]);
}
function persistReport() {
  report.updated = new Date().toISOString();
  fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2) + '\n', { mode: 0o600 });
}
async function check(name, fn) {
  phase = name;
  try {
    const evidence = await fn();
    report.checks.push({ name, status: 'PASS', evidence });
    persistReport();
    return evidence;
  } catch (error) {
    report.checks.push({ name, status: 'FAIL', evidence: safeError(error) });
    persistReport();
    throw error;
  }
}

async function preflight() {
  const url = new URL(baseURL);
  assert(['http:', 'https:'].includes(url.protocol), 'QA URL must be HTTP(S)');
  assert(!url.username && !url.password && !url.search && !url.hash && url.pathname === '/',
    'QA URL must be an origin without credentials, path, query or fragment');
  report.candidate = fs.realpathSync(candidate);
  report.expectedCandidate = expectedCandidate || null;
  assert(preflightOnly || expectedCandidate,
    'EXPECTED_CANDIDATE_REQUIRED: set IPMS_QA_EXPECTED_RELEASE to the authorized release path');
  if (expectedCandidate) {
    assert.equal(report.candidate, expectedCandidate, 'WRONG_CANDIDATE: resolved candidate differs from authorized release');
  }
  const runtimeFiles = [
    'app/Http/Controllers/Api/ProjectMemberController.php',
    'app/Http/Controllers/Api/RequirementController.php',
    'app/Http/Requests/UpdateRequirementRequest.php',
    'app/Http/Resources/ProjectResource.php',
    'app/Http/Resources/RequirementResource.php',
    'app/Policies/ProjectPolicy.php',
    'app/Services/RequirementExecutionOwners.php',
    'app/Services/RequirementWorkflowService.php',
    'routes/api.php',
  ];
  const mismatches = [];
  report.runtimeChecksums = runtimeFiles.map(relative => {
    const source = path.join(repo, 'ipms-backend', relative);
    const deployed = path.join(candidate, 'backend', relative);
    const expected = fs.existsSync(source) ? digest(source) : null;
    const actual = fs.existsSync(deployed) ? digest(deployed) : null;
    if (!expected || expected !== actual) mismatches.push(relative);
    return { path: relative, expected, actual };
  });
  assert.equal(mismatches.length, 0, 'STALE_CANDIDATE: runtime mismatch: ' + mismatches.join(', '));

  const frontend = path.join(repo, 'ipms-frontend');
  const dist = path.join(frontend, 'dist');
  const index = path.join(dist, 'index.html');
  assert(fs.existsSync(index), 'MISSING_BUILD: build repo frontend before acceptance');
  const sources = files(path.join(frontend, 'src')).concat(
    ['package.json', 'package-lock.json', 'vite.config.js', 'index.html']
      .map(file => path.join(frontend, file)).filter(file => fs.existsSync(file)));
  const newerSources = sources.filter(file => fs.statSync(file).mtimeMs > fs.statSync(index).mtimeMs);
  assert.equal(newerSources.length, 0,
    'STALE_BUILD: rebuild frontend after source changes: ' + newerSources.map(file => path.relative(frontend, file)).join(', '));
  report.frontendSourceChecksum = sha(JSON.stringify(sources.map(file => [path.relative(frontend, file), digest(file)])));
  const expected = tree(dist);
  const deployed = tree(path.join(candidate, 'frontend'));
  assert.deepEqual(deployed, expected, 'STALE_CANDIDATE: frontend tree differs from repo dist');
  report.distChecksum = sha(JSON.stringify(expected));
  for (const [relative, checksum] of expected) {
    const asset = relative === 'index.html' ? '/' : '/' + relative.split(path.sep).map(encodeURIComponent).join('/');
    const response = await fetch(new URL(asset, baseURL), { redirect: 'error', signal: AbortSignal.timeout(15000) });
    assert.equal(response.status, 200, 'Candidate asset unavailable: ' + asset);
    assert.equal(sha(Buffer.from(await response.arrayBuffer())), checksum,
      'WRONG_TARGET: served asset differs from candidate: ' + asset);
  }
  return { candidate: report.candidate, runtimeFiles: runtimeFiles.length, distFiles: expected.length,
    distChecksum: report.distChecksum, servedAssetsVerified: true };
}

function readPassword() {
  const line = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8').split(/\r?\n/)
    .find(value => /^IPMS_DEMO_PASSWORD=/.test(value));
  assert(line, 'Missing demo password configuration');
  let value = line.slice(line.indexOf('=') + 1).trim();
  if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
    value = value.slice(1, -1);
  }
  assert(value.length > 0, 'Empty demo password configuration');
  return value;
}
function monitor(page, account) {
  page.on('console', message => {
    if (message.type() !== 'error') return;
    // Do not persist console text, bodies, headers, storage or credentials.
    const denialStatus = message.text().match(/^Failed to load resource: the server responded with a status of (403|404)\b/);
    report.diagnostics.push({ kind: 'console', type: message.type(), account, phase,
      resourceDenialStatus: denialStatus ? Number(denialStatus[1]) : null });
  });
  page.on('pageerror', error => report.diagnostics.push({ kind: 'pageerror', name: error.name, account, phase }));
  page.on('requestfailed', request => report.diagnostics.push({
    kind: 'requestfailed', method: request.method(), path: new URL(request.url()).pathname, account, phase,
  }));
  page.on('response', response => {
    const request = response.request();
    const pathname = new URL(response.url()).pathname;
    if (pathname.startsWith('/api/') && !['GET', 'HEAD', 'OPTIONS'].includes(request.method())) {
      report.uiWrites.push({ method: request.method(), path: pathname, status: response.status(), account, phase });
    }
    if (response.status() >= 400) report.diagnostics.push({
      kind: 'http', method: request.method(), path: pathname, status: response.status(), account, phase,
    });
  });
}
async function login(account) {
  const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 },
    extraHTTPHeaders: { Referer: baseURL + '/', Accept: 'application/json' } });
  context.setDefaultTimeout(15000);
  const page = await context.newPage();
  activePage = page;
  monitor(page, account);
  await page.goto('/login');
  await page.getByPlaceholder('\u8bf7\u8f93\u5165\u8d26\u53f7').fill(account);
  await page.getByPlaceholder('\u8bf7\u8f93\u5165\u5bc6\u7801').fill(password);
  await page.locator('.login-btn').click();
  await page.waitForURL('**/dashboard');
  return page;
}
async function get(page, pathname, allowed = [200]) {
  assert(pathname.startsWith('/api/'), 'Persistence reads must be same-origin API GETs');
  const response = await page.context().request.get(pathname);
  assert(allowed.includes(response.status()), 'GET ' + pathname + ' returned HTTP ' + response.status());
  return { status: response.status(), data: response.status() === 200 ? (await response.json()).data : null };
}
async function uiWrite(page, method, pathname, action, expectedStatus = 200) {
  const waiting = page.waitForResponse(response => new URL(response.url()).pathname === pathname
    && response.request().method() === method);
  const [response] = await Promise.all([waiting, action()]);
  assert.equal(response.status(), expectedStatus, method + ' ' + pathname + ' rejected UI action');
  return (await response.json()).data;
}
async function screenshot(page, name, responsive = false) {
  const file = path.join(out, name + '.png');
  await page.screenshot({ path: file, fullPage: true, animations: 'disabled' });
  report.screenshots.push(file);
  const layout = await page.evaluate(() => ({
    width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
    dialogs: [...document.querySelectorAll('[role="dialog"]')]
      .filter(element => element.getClientRects().length && getComputedStyle(element).visibility !== 'hidden')
      .map(element => {
        const box = element.getBoundingClientRect();
        return { x: box.x, right: box.right, width: box.width };
      }),
  }));
  report.layouts.push({ name, ...layout });
  persistReport();
  assert(layout.scrollWidth <= layout.width, 'Page overflows viewport at ' + name + ': ' + JSON.stringify(layout));
  assert(layout.dialogs.every(box => box.x >= 0 && box.right <= layout.width + 1), 'Dialog is clipped at ' + name);
  if (responsive) {
    const viewport = page.viewportSize();
    try {
      await page.setViewportSize({ width: 390, height: 844 });
      await screenshot(page, name + '-390px');
    } finally {
      await page.setViewportSize(viewport);
    }
  }
}
async function select(page, testId, label) {
  await page.getByTestId(testId).click();
  await page.locator('.el-select-dropdown:visible').getByText(label, { exact: true }).click();
}

function formItem(dialog, label) {
  return dialog.locator('.el-form-item').filter({ has: dialog.getByText(label, { exact: true }) });
}
async function requirementOwnerCheckpoint(project, pmName) {
  const title = 'QA-P1-owner-' + runId;
  const requester = await login('demo.requester');
  const created = await check('requester-create-single-project-requirement-ui', async () => {
    await requester.goto('/requirements');
    await requester.getByRole('button', { name: '\u65b0\u5efa\u9700\u6c42', exact: true }).click();
    const dialog = requester.getByRole('dialog', { name: '\u65b0\u5efa\u9700\u6c42', exact: true });
    await formItem(dialog, '\u9700\u6c42\u6807\u9898').locator('input').fill(title);
    await formItem(dialog, '\u9700\u6c42\u63cf\u8ff0').locator('textarea').fill('P1 UI-only owner and version checkpoint ' + runId);
    await formItem(dialog, '\u5173\u8054\u9879\u76ee').locator('.el-select').click();
    await requester.locator('.el-select-dropdown:visible').getByText(project.name, { exact: true }).click();
    await dialog.getByRole('heading', { name: '\u65b0\u5efa\u9700\u6c42', exact: true }).click();
    const requirement = await uiWrite(requester, 'POST', '/api/requirements',
      () => dialog.getByRole('button', { name: '\u4fdd\u5b58', exact: true }).click(), 201);
    report.requirementId = requirement.id;
    await dialog.waitFor({ state: 'hidden' });
    await requester.goto('/requirements/' + requirement.id);
    await requester.getByRole('heading', { name: title, exact: true }).waitFor();
    const persisted = (await get(requester, '/api/requirements/' + requirement.id)).data;
    assert.equal(persisted.title, title);
    assert.equal(persisted.status_code, 'PENDING_REVIEW');
    assert.equal(persisted.dev_lead_id, null, 'Fresh requirement already has an execution owner');
    assert.deepEqual(persisted.projects.map(item => item.id), [project.id]);
    await screenshot(requester, 'requirement-created-requester');
    return { id: persisted.id, title, submitterId: persisted.submitter_id,
      projectId: project.id, status: persisted.status_code, apiGetVerified: true };
  });
  await requester.context().close();
  const pm = await login('demo.it_pm');
  const requirementPath = '/api/requirements/' + created.id;
  await check('pm-review-approve-requirement-ui', async () => {
    await pm.goto('/requirements/' + created.id);
    await pm.getByRole('heading', { name: title, exact: true }).waitFor();
    await pm.getByTestId('approve-requirement').click();
    const dialog = pm.getByRole('dialog', { name: '\u5ba1\u6838\u901a\u8fc7\u9700\u6c42', exact: true });
    await dialog.locator('textarea').fill('P1 execution-owner review ' + runId);
    await uiWrite(pm, 'POST', requirementPath + '/review',
      () => dialog.getByRole('button', { name: '\u786e\u8ba4', exact: true }).click());
    await dialog.waitFor({ state: 'hidden' });
    await pm.locator('[data-testid="aggregate-status"][data-status-code="ASSIGNED"]').waitFor();
    const persisted = (await get(pm, requirementPath)).data;
    assert.equal(persisted.status_code, 'ASSIGNED');
    assert.equal(persisted.reviewer_id, project.managerId);
    assert.equal(persisted.dev_lead_id, null, 'Review unexpectedly assigned an execution owner');
    assert(persisted.allowed_actions.includes('assign_owner'), 'PM lacks owner assignment action');
    return { requirementId: created.id, reviewerId: persisted.reviewer_id, status: persisted.status_code };
  });
  await check('execution-owner-editor-reload-persistence', async () => {
    const before = (await get(pm, requirementPath)).data;
    const waiting = pm.waitForResponse(response => new URL(response.url()).pathname === requirementPath + '/execution-owner-options'
      && response.request().method() === 'GET');
    const [response] = await Promise.all([waiting, pm.getByTestId('edit-execution-owner').click()]);
    assert.equal(response.status(), 200);
    const options = (await response.json()).data;
    const owners = options.filter(user => user.id === project.managerId && user.display_name === pmName);
    assert.equal(owners.length, 1, 'Assigned demo PM is not an eligible execution owner');
    await select(pm, 'execution-owner-select', pmName);
    await screenshot(pm, 'execution-owner-editor', true);
    await uiWrite(pm, 'PUT', requirementPath, () => pm.getByTestId('save-execution-owner').click());
    await pm.getByRole('dialog', { name: '\u8bbe\u7f6e\u6267\u884c\u8d1f\u8d23\u4eba', exact: true }).waitFor({ state: 'hidden' });
    await pm.reload();
    await pm.getByRole('heading', { name: title, exact: true }).waitFor();
    await pm.locator('.execution-owner').getByText(pmName, { exact: true }).waitFor();
    const persisted = (await get(pm, requirementPath)).data;
    assert.equal(persisted.dev_lead_id, project.managerId);
    assert.equal(persisted.dev_lead?.id, project.managerId);
    assert.equal(persisted.dev_lead?.display_name, pmName);
    assert.equal(persisted.title, title);
    assert.deepEqual(persisted.projects.map(item => item.id), [project.id]);
    assert(persisted.version > before.version, 'Owner assignment did not advance the concurrency version');
    await screenshot(pm, 'execution-owner-reloaded', true);
    return { requirementId: created.id, ownerId: project.managerId,
      versionBefore: before.version, versionAfter: persisted.version, reloadVerified: true, apiGetVerified: true };
  });
  return { pm, requirement: created };
}

function assertSingleTarget(version, requirement, projectId, versionId) {
  assert.equal(version.id, versionId);
  assert.equal(version.project?.id, projectId);
  assert.deepEqual(requirement.projects.map(item => item.id), [projectId]);
  assert.equal(requirement.project_deliveries.length, 1, 'Requirement has more than one project delivery');
  const delivery = requirement.project_deliveries[0];
  assert.equal(delivery.project?.id, projectId);
  assert.equal(delivery.target_version?.id, versionId);
  assert.equal(version.scope.length, 1, 'Version must contain exactly the created requirement');
  assert.equal(version.counts.requirements, 1);
  const scope = version.scope[0];
  assert.equal(scope.requirement_id, requirement.id);
  assert.equal(scope.project_id, projectId);
  assert.equal(scope.project_version_id, versionId);
}
function assertDevelopmentGate(gate) {
  assert.equal(gate.target_status_code, 'IN_DEVELOPMENT');
  assert.equal(gate.passed, true, 'Development gate is blocked');
  assert.deepEqual(gate.blocking, []);
  const owner = gate.checks.find(item => item.code === 'reviewed_assigned_scope');
  assert(owner, 'Development owner gate is missing');
  assert.equal(owner.details.applicable, true, 'Owner gate did not evaluate the development target');
  assert.equal(owner.passed, true, 'Reviewed execution-owner gate did not pass');
  assert.deepEqual(owner.details.unapproved_requirement_project_ids, []);
  assert.deepEqual(owner.details.missing_execution_owner_requirement_project_ids, []);
  const scope = gate.checks.find(item => item.code === 'non_empty_scope');
  assert(scope && scope.details.applicable && scope.passed, 'Development scope gate did not pass');
  assert.equal(scope.details.requirement_project_count, 1);
  return { target: gate.target_status_code, passed: true, ownerGate: owner.code,
    ownerGateApplicable: true, missingOwnerCount: 0, unapprovedCount: 0, scopeCount: 1 };
}
async function versionCheckpoint(pm, project, requirement, pmName) {
  const code = 'P1-' + runId;
  const name = 'QA-P1-version-' + runId;
  const startDate = new Date().toISOString().slice(0, 10);
  const releaseDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
  const created = await check('pm-create-project-version-required-fields-ui', async () => {
    await pm.goto('/projects/' + project.id + '/versions');
    await pm.getByRole('button', { name: '\u65b0\u5efa\u7248\u672c', exact: true }).click();
    const dialog = pm.getByRole('dialog', { name: '\u65b0\u5efa\u9879\u76ee\u7248\u672c', exact: true });
    await formItem(dialog, '\u7248\u672c\u7f16\u53f7').locator('input').fill(code);
    await formItem(dialog, '\u7248\u672c\u540d\u79f0').locator('input').fill(name);
    await formItem(dialog, '\u8d1f\u8d23\u4eba').locator('.el-select').click();
    await pm.locator('.el-select-dropdown:visible').getByText(pmName, { exact: true }).click();
    for (const [label, value] of [['\u8ba1\u5212\u5f00\u59cb\u65e5\u671f', startDate], ['\u8ba1\u5212\u53d1\u5e03\u65e5\u671f', releaseDate]]) {
      const input = formItem(dialog, label).locator('input');
      await input.fill(value);
      await input.press('Enter');
    }
    await formItem(dialog, '\u7248\u672c\u8bf4\u660e').locator('textarea').fill('P1 single-target scope ' + runId);
    await screenshot(pm, 'version-create-required-fields');
    const version = await uiWrite(pm, 'POST', '/api/projects/' + project.id + '/versions',
      () => dialog.getByRole('button', { name: '\u521b\u5efa\u7248\u672c', exact: true }).click(), 201);
    report.projectVersionId = version.id;
    await dialog.waitFor({ state: 'hidden' });
    await pm.getByTestId('open-version-' + version.id).click();
    await pm.waitForURL('**/project-versions/' + version.id);
    await pm.getByRole('heading', { name: code + ' \u00b7 ' + name, exact: true }).waitFor();
    const persisted = (await get(pm, '/api/project-versions/' + version.id)).data;
    assert.equal(persisted.status_code, 'DRAFT');
    assert.equal(persisted.code, code);
    assert.equal(persisted.name, name);
    assert.equal(persisted.project?.id, project.id);
    assert.equal(persisted.owner?.id, project.managerId);
    assert.equal(persisted.planned_start_date, startDate);
    assert.equal(persisted.planned_release_date, releaseDate);
    return { id: persisted.id, ownerId: project.managerId, startDate, releaseDate, apiGetVerified: true };
  });
  const versionPath = '/api/project-versions/' + created.id;
  const requirementPath = '/api/requirements/' + requirement.id;
  async function reloadVersion(status) {
    await pm.reload();
    await pm.getByRole('heading', { name: code + ' \u00b7 ' + name, exact: true }).waitFor();
    await pm.locator('.version-detail[data-status-code="' + status + '"]').waitFor();
    const persisted = (await get(pm, versionPath)).data;
    assert.equal(persisted.status_code, status);
    assertSingleTarget(persisted, (await get(pm, requirementPath)).data, project.id, created.id);
    return persisted;
  }
  await check('map-requirement-single-target-reload-persistence', async () => {
    await pm.getByRole('tab', { name: /^\u9700\u6c42\u8303\u56f4/ }).click();
    await uiWrite(pm, 'PUT', requirementPath + '/projects/' + project.id + '/version',
      () => pm.getByTestId('plan-requirement-' + requirement.id).click());
    await pm.getByTestId('unplan-requirement-' + requirement.id).waitFor();
    await reloadVersion('DRAFT');
    await pm.getByRole('tab', { name: /^\u9700\u6c42\u8303\u56f4/ }).click();
    await pm.getByTestId('unplan-requirement-' + requirement.id).waitFor();
    assert.equal(await pm.getByTestId('plan-requirement-' + requirement.id).count(), 0);
    const pool = (await get(pm, '/api/requirements?project_id=' + project.id + '&version_scope=unplanned&page_size=100')).data;
    assert(!pool.items.some(item => item.id === requirement.id), 'Mapped requirement remains in the unplanned pool');
    await screenshot(pm, 'version-single-target-scope', true);
    return { requirementId: requirement.id, projectId: project.id, versionId: created.id,
      scopeCount: 1, targetCount: 1, unplannedAbsent: true, reloadVerified: true, apiGetVerified: true };
  });
  async function transition(status, label) {
    const before = (await get(pm, versionPath)).data;
    await pm.getByTestId('transition-command').filter({ hasText: '\u63a8\u8fdb\u81f3' + label }).click();
    const dialog = pm.getByRole('dialog', { name: '\u786e\u8ba4\u53d8\u66f4\u4e3a' + label, exact: true });
    await dialog.locator('textarea').fill('P1 UI checkpoint ' + status + ' ' + runId);
    await uiWrite(pm, 'POST', versionPath + '/status', () => dialog.getByTestId('confirm-release-command').click());
    await dialog.waitFor({ state: 'hidden' });
    await pm.locator('.version-detail[data-status-code="' + status + '"]').waitFor();
    const persisted = await reloadVersion(status);
    assert(persisted.lock_version > before.lock_version, 'Transition did not advance the concurrency version');
    return { versionId: created.id, status, lockBefore: before.lock_version,
      lockAfter: persisted.lock_version, reloadVerified: true, apiGetVerified: true };
  }
  await check('plan-version-ui-reload-persistence', () => transition('PLANNED', '\u5df2\u8ba1\u5212'));
  await check('development-execution-owner-gate', async () => {
    const gate = (await get(pm, versionPath + '/gate-check?target_status=3')).data;
    const evidence = assertDevelopmentGate(gate);
    await pm.getByRole('tab', { name: '\u53d1\u5e03\u95e8\u7981', exact: true }).click();
    await screenshot(pm, 'version-development-owner-gate');
    return evidence;
  });
  await check('start-development-ui-reload-persistence', () => transition('IN_DEVELOPMENT', '\u5f00\u53d1\u4e2d'));
  await check('prerelease-version-notes-editor-reload-persistence', async () => {
    const before = (await get(pm, versionPath)).data;
    const notes = 'P1 prerelease notes persisted through VersionEditDialog ' + runId;
    await pm.getByTestId('edit-version-command').click();
    const dialog = pm.getByRole('dialog', { name: '\u7f16\u8f91\u7248\u672c\u4fe1\u606f', exact: true });
    await dialog.getByTestId('edit-release_notes').fill(notes);
    await screenshot(pm, 'version-notes-editor', true);
    await uiWrite(pm, 'PUT', versionPath, () => dialog.getByTestId('save-version-info').click());
    await dialog.waitFor({ state: 'hidden' });
    const persisted = await reloadVersion('IN_DEVELOPMENT');
    assert.equal(persisted.release_notes, notes);
    assert.equal(persisted.released_at, null);
    assert.equal(persisted.release_snapshot, null, 'Prerelease edit unexpectedly created a release snapshot');
    assert.equal(persisted.code, before.code);
    assert.equal(persisted.name, before.name);
    assert.equal(persisted.owner?.id, project.managerId);
    assert.equal(persisted.planned_start_date, startDate);
    assert.equal(persisted.planned_release_date, releaseDate);
    assert(persisted.lock_version > before.lock_version, 'Notes edit did not advance the concurrency version');
    await pm.getByRole('tab', { name: '\u7248\u672c\u6982\u89c8', exact: true }).click();
    await pm.getByText(notes, { exact: true }).waitFor();
    await screenshot(pm, 'version-notes-reloaded', true);
    return { versionId: created.id, status: persisted.status_code, releaseNotesChecksum: sha(notes),
      lockBefore: before.lock_version, lockAfter: persisted.lock_version, released: false,
      reloadVerified: true, apiGetVerified: true };
  });
}

async function acceptance() {
  password = readPassword();
  browser = await chromium.launch({ headless: true });
  const name = 'QA-P1-membership-' + runId;
  const pmName = '\u6f14\u793a IT \u9879\u76ee\u7ecf\u7406';
  const testerName = '\u6f14\u793a\u4f9b\u5e94\u5546\u6d4b\u8bd5';
  const memberLabel = '\u9879\u76ee\u6210\u5458';
  const admin = await login('demo.super_admin');
  const project = await check('create-fresh-external-project-ui', async () => {
    await admin.goto('/projects');
    await admin.getByRole('heading', { name: '\u9879\u76ee\u7ba1\u7406', exact: true }).waitFor();
    await admin.getByRole('button', { name: /\u65b0\u5efa\u9879\u76ee|\u65b0\u589e\u9879\u76ee/ }).click();
    await admin.getByTestId('project-name').fill(name);
    await select(admin, 'project-manager', pmName);
    await select(admin, 'project-type', '\u5916\u90e8\u91c7\u8d2d');
    await select(admin, 'project-supplier', '\u4f9b\u5e94\u5546');
    const created = await uiWrite(admin, 'POST', '/api/projects', () => admin.getByTestId('save-project').click(), 201);
    report.projectId = created.id;
    report.projectName = name;
    await admin.getByRole('dialog').waitFor({ state: 'hidden' });
    const persisted = (await get(admin, '/api/projects/' + created.id)).data;
    assert.equal(persisted.name, name);
    assert(persisted.manager?.id && persisted.supplier_org?.id, 'Fresh project lacks assigned PM or supplier');
    assert.equal(persisted.manager.display_name, pmName);
    assert.equal(persisted.supplier_org.name, '\u4f9b\u5e94\u5546');
    assert.equal(Number(persisted.system_type), 1, 'Fresh project is not external procurement');
    return { id: created.id, name, managerId: persisted.manager.id, supplierOrgId: persisted.supplier_org.id };
  });
  await admin.context().close();
  const pm = await login('demo.it_pm');
  const projectPath = '/api/projects/' + project.id;
  const membersPath = projectPath + '/members';
  const panel = pm.getByRole('region', { name: memberLabel, exact: true });
  const memberTab = pm.getByRole('button', { name: memberLabel, exact: true });
  async function openMembers(reload = false) {
    if (reload) await pm.reload();
    else await pm.goto('/projects/' + project.id);
    await pm.getByRole('heading', { name, exact: true }).waitFor();
    await memberTab.click();
    await panel.waitFor();
    await panel.locator('tbody tr').filter({ hasText: pmName }).waitFor();
  }
  async function verifyMembership(userId, present) {
    const members = (await get(pm, membersPath)).data;
    assert.equal(members.filter(member => member.user_id === userId).length, present ? 1 : 0);
    await openMembers(true);
    const row = panel.locator('tbody tr').filter({ hasText: testerName });
    if (present) await row.waitFor();
    else assert.equal(await row.count(), 0, 'Removed member still visible after reload');
    assert.equal(await panel.getByTestId('remove-member-' + userId).count(), present ? 1 : 0);
    return { userId, present, apiGetVerified: true, reloadVerified: true };
  }
  await check('assigned-pm-protected-ui', async () => {
    await openMembers();
    const detail = (await get(pm, projectPath)).data;
    assert(detail.allowed_actions.includes('manage_members'), 'Assigned PM cannot manage members');
    const members = (await get(pm, membersPath)).data;
    const manager = members.find(member => member.is_manager);
    assert(manager && manager.user_id === project.managerId && manager.display_name === pmName);
    assert.equal(await panel.getByTestId('remove-member-' + manager.user_id).count(), 0);
    assert.equal(await panel.locator('tbody tr').filter({ hasText: pmName }).getByRole('button').count(), 0);
    assert(!members.some(member => member.display_name === testerName), 'Fresh project already includes tester');
    return { managerId: manager.user_id, removeControlAbsent: true, testerInitiallyAbsent: true };
  });
  let testerId;
  async function addTester() {
    const optionsWait = pm.waitForResponse(response => new URL(response.url()).pathname === projectPath + '/member-options'
      && response.request().method() === 'GET');
    const [optionsResponse] = await Promise.all([optionsWait, pm.getByTestId('add-member').click()]);
    assert.equal(optionsResponse.status(), 200);
    const options = (await optionsResponse.json()).data;
    const matches = options.filter(user => user.display_name === testerName);
    assert.equal(matches.length, 1, 'Expected one eligible demo supplier tester');
    assert(!options.some(user => user.id === project.managerId), 'Protected PM offered as new member');
    testerId = matches[0].id;
    await select(pm, 'member-select', testerName);
    await uiWrite(pm, 'POST', membersPath, () => pm.getByTestId('save-member').click());
    await pm.getByRole('dialog').waitFor({ state: 'hidden' });
    return verifyMembership(testerId, true);
  }
  await check('add-tester-reload-persistence', addTester);
  await check('remove-tester-confirm-reload-persistence', async () => {
    await pm.getByTestId('remove-member-' + testerId).click();
    const dialog = pm.getByRole('dialog', { name: '\u79fb\u9664\u9879\u76ee\u6210\u5458' });
    await dialog.waitFor();
    await uiWrite(pm, 'DELETE', membersPath + '/' + testerId,
      () => dialog.getByRole('button', { name: '\u79fb\u9664', exact: true }).click());
    await dialog.waitFor({ state: 'hidden' });
    return verifyMembership(testerId, false);
  });
  await check('readd-tester-reload-persistence', addTester);
  await check('desktop-and-390px-membership', async () => {
    await screenshot(pm, 'membership-desktop');
    await pm.setViewportSize({ width: 390, height: 844 });
    await panel.waitFor();
    await screenshot(pm, 'membership-390px');
    const layout = await pm.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth }));
    assert(layout.scrollWidth <= layout.width, 'Mobile page overflows viewport: ' + JSON.stringify(layout));
    const optionsWait = pm.waitForResponse(response => new URL(response.url()).pathname === projectPath + '/member-options'
      && response.request().method() === 'GET');
    const [optionsResponse] = await Promise.all([optionsWait, pm.getByTestId('add-member').click()]);
    assert.equal(optionsResponse.status(), 200);
    await pm.getByRole('dialog').waitFor();
    await screenshot(pm, 'membership-add-390px');
    const dialogBox = await pm.getByRole('dialog').boundingBox();
    assert(dialogBox && dialogBox.x >= 0 && dialogBox.x + dialogBox.width <= 391, 'Mobile dialog is clipped');
    await pm.getByRole('dialog').getByRole('button', { name: '\u53d6\u6d88', exact: true }).click();
    return layout;
  });
  await pm.context().close();
  for (const account of ['demo.supplier_tester', 'demo.requester']) {
    await check('no-member-management-' + account, async () => {
      const page = await login(account);
      const detail = await get(page, projectPath, account === 'demo.requester' ? [200, 403, 404] : [200]);
      if (detail.status === 200) assert(!detail.data.allowed_actions.includes('manage_members'));
      const loaded = page.waitForResponse(response => new URL(response.url()).pathname === projectPath
        && response.request().method() === 'GET');
      const [response] = await Promise.all([loaded, page.goto('/projects/' + project.id + '?tab=members')]);
      assert.equal(response.status(), detail.status);
      if (detail.status === 200) await page.getByRole('heading', { name, exact: true }).waitFor();
      else await page.getByRole('button', { name: '\u91cd\u8bd5', exact: true }).waitFor();
      assert.equal(await page.getByRole('button', { name: memberLabel, exact: true }).count(), 0);
      assert.equal(await page.getByTestId('add-member').count(), 0);
      assert.equal(await page.locator('[data-testid^="remove-member-"]').count(), 0);
      await screenshot(page, 'negative-' + account.replace('.', '-'));
      await page.context().close();
      return { projectStatus: detail.status, managementControlsAbsent: true };
    });
  }
  if (!membershipOnly) {
    const { pm: ownerPm, requirement } = await requirementOwnerCheckpoint(project, pmName);
    await versionCheckpoint(ownerPm, project, requirement, pmName);
    await ownerPm.context().close();
  }
  await check('unexpected-browser-errors', async () => {
    const errors = report.diagnostics.filter(item => {
      const expectedDenial = item.phase === 'no-member-management-demo.requester';
      if (expectedDenial && item.kind === 'http' && [403, 404].includes(item.status)
        && item.method === 'GET' && [projectPath, '/api/requirements', '/api/defects'].includes(item.path)) return false;
      if (expectedDenial && item.kind === 'console' && item.resourceDenialStatus) return false;
      return true;
    });
    assert.equal(errors.length, 0, 'Unexpected browser errors; see redacted diagnostics');
    return { collected: report.diagnostics.length, unexpected: errors.length };
  });
}

(async () => {
  fs.mkdirSync(out, { recursive: true });
  try {
    await check('candidate-source-build-guard', preflight);
    if (preflightOnly) report.status = 'PREFLIGHT_PASS';
    else {
      await acceptance();
      report.status = 'PASS';
    }
  } catch (error) {
    report.status = phase === 'candidate-source-build-guard' ? 'BLOCKED_STALE_CANDIDATE' : 'FAIL';
    report.error = safeError(error);
    process.exitCode = report.status === 'BLOCKED_STALE_CANDIDATE' ? 2 : 1;
    // Never screenshot the login form, where a credential may have been entered.
    if (activePage && !activePage.isClosed() && !new URL(activePage.url()).pathname.startsWith('/login')) {
      await screenshot(activePage, 'failure').catch(() => {});
    }
  } finally {
    if (browser) await browser.close().catch(() => {});
    report.finished = new Date().toISOString();
    persistReport();
    console.log(JSON.stringify({ status: report.status, out, report: path.join(out, 'report.json'),
      checks: report.checks.map(({ name, status }) => ({ name, status })), uiWriteCount: report.uiWrites.length }));
  }
})().catch(error => { console.error(safeError(error)); process.exitCode = 1; });
