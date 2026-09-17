const { chromium } = require('/srv/itpms-dev/tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const baseURL = process.env.IPMS_QA_URL || 'http://127.0.0.1:8082';
const out = '/srv/itpms-dev/tools/qa-system-audit';
const setup = JSON.parse(fs.readFileSync('/srv/itpms-dev/tools/qa-admin-regressions/report.json', 'utf8'));
assert.ok(setup.projectId && setup.projectName.startsWith('QA-system-'), 'Run admin regression setup first');
const seeded = process.env.IPMS_QA_SEEDED_PROJECT === '1';
const projectId = seeded ? 1 : setup.projectId, suffix = Date.now();
const password = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8').split('\n')
  .find(line => line.startsWith('IPMS_DEMO_PASSWORD=')).split('=').slice(1).join('=');
const report = { baseURL, projectId, fixture: seeded ? 'seeded-members' : 'new-project', started: new Date().toISOString(), checks: [], requests: [], records: {} };
const sessions = {};
let browser;
async function group(name, fn) {
  try { await fn(); report.checks.push({ name, status: 'PASS' }); }
  catch (error) { report.checks.push({ name, status: error.message.includes('Blocked:') ? 'BLOCKED' : 'FAIL', evidence: error.message }); }
}
async function request(role, method, path, data, expected = 200, multipart = false, raw = false) {
  const context = sessions[role].context;
  const csrf = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
  const options = { method, headers: { Accept: 'application/json' } };
  if (method !== 'GET') options.headers['X-XSRF-TOKEN'] = decodeURIComponent(csrf.value);
  if (data !== undefined) options[multipart ? 'multipart' : 'data'] = data;
  const response = await context.request.fetch('/api' + path, options);
  report.requests.push({ role, method, path, status: response.status(), expected });
  assert.equal(response.status(), expected, role + ' ' + method + ' ' + path + ': HTTP ' + response.status() + ' ' + (await response.text()).slice(0, 450));
  return raw ? response : (await response.json()).data;
}
async function version() { return request('it_pm', 'GET', '/project-versions/' + report.records.version); }
async function versionStatus(status, expected = 200) {
  const current = await version();
  return request('it_pm', 'POST', '/project-versions/' + current.id + '/status', { status, lock_version: current.lock_version }, expected);
}
(async () => {
  fs.mkdirSync(out, { recursive: true });
  browser = await chromium.launch({ headless: true });
  for (const role of ['super_admin', 'it_pm', 'it_member', 'supplier_pm', 'supplier_dev', 'supplier_tester', 'requester']) {
    const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 }, extraHTTPHeaders: { Referer: baseURL + '/' } });
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByPlaceholder('请输入账号').fill('demo.' + role);
    await page.getByPlaceholder('请输入密码').fill(password);
    await page.locator('.login-btn').click();
    await page.waitForURL('**/dashboard');
    sessions[role] = { context, page };
  }
  await group('seven-role directories and session authorization', async () => {
    for (const role of Object.keys(sessions)) {
      await request(role, 'GET', '/user');
      await request(role, 'GET', '/dashboard/summary');
      if (role !== 'super_admin') {
        await request(role, 'GET', '/users', undefined, 403);
        await request(role, 'GET', '/organizations', undefined, 403);
        await request(role, 'POST', '/projects', {}, 403);
      }
    }
    await request('it_member', 'GET', '/projects/' + projectId, undefined, seeded ? 200 : 403);
  });
  await group('requirement submission, review and project version scope', async () => {
    const requirement = await request('requester', 'POST', '/requirements', {
      title: 'QA release requirement ' + suffix, description: 'Isolated end-to-end acceptance data',
      priority: 2, requirement_type: 1, project_ids: [projectId],
    }, 201);
    report.records.requirement = requirement.id;
    await request('supplier_dev', 'POST', '/requirements/' + requirement.id + '/review', { action: 'approve' }, 403);
    await request('it_pm', 'POST', '/requirements/' + requirement.id + '/review', { action: 'approve' });
    await request('super_admin', 'POST', '/projects/' + projectId + '/versions', { code: 'QA-' + suffix, name: 'QA release' }, 403);
    const project = await request('it_pm', 'GET', '/projects/' + projectId);
    const created = await request('it_pm', 'POST', '/projects/' + projectId + '/versions', {
      code: 'QA-' + suffix, name: 'QA release', owner_id: project.manager.id, planned_release_date: '2026-12-01', release_notes: 'QA release notes configured through API',
    }, 201);
    report.records.version = created.id;
    await request('it_pm', 'PUT', '/requirements/' + requirement.id + '/projects/' + projectId + '/version', {
      project_version_id: created.id, lock_version: created.lock_version,
    });
    await request('it_pm', 'PUT', '/requirements/' + requirement.id, { dev_lead_id: project.manager.id });
    report.uiGap = 'Requirement execution owner and pre-release notes currently require API setup; new project member management has no UI or API.';
    const stale = await version();
    await versionStatus(2);
    await request('it_pm', 'POST', '/project-versions/' + created.id + '/status', { status: 3, lock_version: stale.lock_version }, 409);
    await versionStatus(3);
    await request('it_pm', 'POST', '/requirements/' + requirement.id + '/status', { project_id: projectId, status: 3 });
    const page = sessions.it_pm.page;
    await page.goto('/project-versions/' + created.id);
    await page.getByRole('tab', { name: /需求范围/ }).waitFor();
    await page.screenshot({ path: out + '/version-scope.png', fullPage: true });
    report.workflowReady = true;
  });
  await group('task and defect lifecycle, release gate, snapshot and acceptance', async () => {
    assert.ok(report.workflowReady, 'Blocked: version setup did not complete');
    const rid = report.records.requirement, vid = report.records.version;
    const people = await request('super_admin', 'GET', '/users?page_size=100');
    const developer = people.items.find(user => user.username === 'demo.supplier_dev');
    const task = await request('supplier_pm', 'POST', '/tasks', {
      requirement_id: rid, project_id: projectId, title: 'QA task ' + suffix,
      assignee_id: developer.id, priority: 2, due_date: '2026-12-31',
    }, 201);
    report.records.task = task.id;
    await request('requester', 'POST', '/tasks/' + task.id + '/status', { status: 2 }, 403);
    await request('supplier_dev', 'POST', '/tasks/' + task.id + '/status', { status: 2 });
    const defect = await request('supplier_tester', 'POST', '/defects', {
      requirement_id: rid, project_id: projectId, title: 'QA serious defect ' + suffix,
      description: 'Regression case for gate and retest', severity: 2, defect_type: 1, discovery_phase: 1,
    }, 201);
    report.records.defect = defect.id;
    await request('it_pm', 'POST', '/defects/' + defect.id + '/confirm', {});
    await request('supplier_pm', 'POST', '/defects/' + defect.id + '/assign', { assignee_id: developer.id });
    await request('supplier_dev', 'POST', '/tasks/' + task.id + '/status', { status: 3 });
    await request('it_pm', 'POST', '/requirements/' + rid + '/status', { project_id: projectId, status: 4 });
    await versionStatus(4);
    const blocked = await request('it_pm', 'GET', '/project-versions/' + vid + '/gate-check?target_status=5');
    assert.equal(blocked.passed, false);
    await versionStatus(5, 409);
    await request('supplier_dev', 'POST', '/defects/' + defect.id + '/resolve', { fix_description: 'QA fix' });
    await request('supplier_tester', 'POST', '/defects/' + defect.id + '/verify', { result: 'fail', comment: 'First retest fails' });
    await request('supplier_pm', 'POST', '/defects/' + defect.id + '/assign', { assignee_id: developer.id });
    await request('supplier_dev', 'POST', '/defects/' + defect.id + '/resolve', { fix_description: 'QA corrected fix' });
    await request('supplier_tester', 'POST', '/defects/' + defect.id + '/verify', { result: 'pass', comment: 'Retest passed' });
    await request('it_pm', 'POST', '/requirements/' + rid + '/status', { project_id: projectId, status: 5 });
    await versionStatus(5);
    const ready = await version();
    await request('supplier_pm', 'POST', '/project-versions/' + vid + '/release', { lock_version: ready.lock_version }, 403);
    await request('it_pm', 'POST', '/project-versions/' + vid + '/release', { lock_version: ready.lock_version, release_notes: 'QA normal release' });
    const released = await version();
    assert.equal(released.status, 6);
    assert.ok(released.release_snapshot);
    const snapshotId = released.release_snapshot.id;
    await request('it_pm', 'POST', '/project-versions/' + vid + '/release', { lock_version: released.lock_version, release_notes: 'QA replay' });
    assert.equal((await version()).release_snapshot.id, snapshotId);
    await request('requester', 'POST', '/requirements/' + rid + '/status', { project_id: projectId, status: 7 }, 403);
    await request('it_pm', 'POST', '/requirements/' + rid + '/status', { project_id: projectId, status: 7 });
    await request('requester', 'GET', '/requirements/' + rid);
    const page = sessions.it_pm.page;
    await page.goto('/project-versions/' + vid);
    await page.getByRole('tab', { name: /变更历史/ }).click();
    await page.screenshot({ path: out + '/release-snapshot.png', fullPage: true });
  });
  await group('document folder, upload, download and nonmember isolation', async () => {
    const folder = await request('it_pm', 'POST', '/projects/' + projectId + '/documents/folder', { name: 'QA folder ' + suffix }, 201);
    const text = 'QA file roundtrip ' + suffix;
    const file = await request('supplier_dev', 'POST', '/projects/' + projectId + '/documents/upload', {
      folder_id: String(folder.id), file: { name: 'qa-roundtrip.txt', mimeType: 'text/plain', buffer: Buffer.from(text) },
    }, 201, true);
    report.records.document = file.id;
    const downloaded = await request('it_pm', 'GET', '/documents/' + file.id + '/download', undefined, 200, false, true);
    assert.equal(await downloaded.text(), text);
    await request('it_member', 'GET', '/documents/' + file.id + '/download', undefined, seeded ? 200 : 403, false, seeded);
  });
  await group('IT PM can create API documentation under approved edit permission', async () => {
    await request('it_pm', 'POST', '/projects/' + projectId + '/api-docs', {
      api_name: 'QA API PM ' + suffix, request_path: '/qa', request_method: 'GET',
    }, 201);
  });
  await group('admin API documentation create, update, version and export', async () => {
    const doc = await request('super_admin', 'POST', '/projects/' + projectId + '/api-docs', {
      api_name: 'QA API admin ' + suffix, request_path: '/qa', request_method: 'GET',
    }, 201);
    report.records.apiDocument = doc.id;
    await request('super_admin', 'PUT', '/api-docs/' + doc.id, { api_name: 'QA API edited ' + suffix });
    const versions = await request('super_admin', 'GET', '/api-docs/' + doc.id + '/versions');
    assert.ok(versions.length > 0);
    await request('super_admin', 'POST', '/api-docs/' + doc.id + '/export', {}, 200, false, true);
  });
  await group('requester cannot edit API documentation', async () => {
    assert.ok(report.records.apiDocument, 'Blocked: API document setup failed');
    await request('requester', 'PUT', '/api-docs/' + report.records.apiDocument, { api_name: 'QA unauthorized edit attempt ' + suffix }, 403);
  });
  await group('requester cannot upload project documents', async () => {
    await request('requester', 'POST', '/projects/' + projectId + '/documents/upload', {
      file: { name: 'qa-denied.txt', mimeType: 'text/plain', buffer: Buffer.from('QA negative permission test') },
    }, 403, true);
  });
  await group('audit log list and CSV export; notification read API', async () => {
    const audit = await request('super_admin', 'GET', '/audit-logs?page_size=20');
    assert.ok(audit.items.length > 0);
    const csv = await request('super_admin', 'GET', '/audit-logs/export', undefined, 200, false, true);
    assert.ok((await csv.body()).length > 0);
    for (const role of Object.keys(sessions)) {
      await request(role, 'GET', '/notifications');
      await request(role, 'GET', '/notifications/unread-count');
    }
    await request('supplier_dev', 'POST', '/logout', {});
    await request('supplier_dev', 'GET', '/user', undefined, 401);
  });
})().catch(error => { report.fatal = error.message; }).finally(async () => {
  if (browser) await browser.close();
  report.status = !report.fatal && report.checks.every(item => item.status === 'PASS') ? 'PASS' : 'FAIL';
  fs.mkdirSync(out, { recursive: true });
  report.acceptance = report.uiGap ? 'BLOCKED_UI_GAPS' : report.status;
  fs.writeFileSync(out + (seeded ? '/report-seeded.json' : '/report.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ ...report, requests: report.requests.length }));
  if (report.status !== 'PASS') process.exitCode = 1;
});
