const { chromium } = require('/srv/itpms-dev/tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const baseURL = process.env.IPMS_QA_URL || 'http://116.62.44.192';
const out = '/srv/itpms-dev/tools/qa-admin-regressions';
const password = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8')
  .split('\n').find(line => line.startsWith('IPMS_DEMO_PASSWORD=')).split('=').slice(1).join('=');
const report = { baseURL, started: new Date().toISOString(), checks: [] };
const suffix = Date.now();
async function check(name, fn) {
  try { report.checks.push({ name, status: 'PASS', evidence: await fn() }); }
  catch (error) { report.checks.push({ name, status: 'FAIL', evidence: error.message }); }
}
(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 },
      extraHTTPHeaders: { Referer: baseURL + '/', Accept: 'application/json' } });
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByPlaceholder('请输入账号').fill('demo.super_admin');
    await page.getByPlaceholder('请输入密码').fill(password);
    await page.locator('.login-btn').click();
    await page.waitForURL('**/dashboard');
    await page.goto('/projects');
    await page.getByRole('heading', { name: '项目管理', exact: true }).waitFor();
    await page.screenshot({ path: out + '/projects.png', fullPage: true });
    await check('superadmin project creation entry', async () => {
      const count = await page.getByRole('button', { name: /新建项目|新增项目/ }).count();
      assert.equal(count, 1, 'Project list has no new-project button for superadmin');
      await page.getByRole('button', { name: /新建项目|新增项目/ }).click();
      const dialog = page.getByRole('dialog');
      const projectName = 'QA-system-' + suffix;
      await page.getByTestId('project-name').fill(projectName);
      await page.getByTestId('project-manager').click();
      await page.locator('.el-select-dropdown:visible').getByText('演示 IT 项目经理', { exact: true }).click();
      await page.getByTestId('project-type').click();
      await page.locator('.el-select-dropdown:visible').getByText('外部采购', { exact: true }).click();
      await page.getByTestId('project-supplier').click();
      await page.locator('.el-select-dropdown:visible').getByText('供应商', { exact: true }).click();
      await page.setViewportSize({ width: 390, height: 844 });
      await page.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth, null, { timeout: 5000 });
      await page.screenshot({ path: out + '/project-create-mobile.png', fullPage: true });
      const layout = await page.evaluate(() => ['body', 'main', '.project-list-page', '.filter-band', '.table-scroll', '.el-dialog'].map(selector => {
        const node = document.querySelector(selector), box = node?.getBoundingClientRect();
        return { selector, left: box?.left, right: box?.right, scrollWidth: node?.scrollWidth };
      }));
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, JSON.stringify(layout));
      await page.setViewportSize({ width: 1440, height: 900 });
      const createdPromise = page.waitForResponse(response =>
        new URL(response.url()).pathname === '/api/projects' && response.request().method() === 'POST');
      await page.getByTestId('save-project').click();
      const createdResponse = await createdPromise;
      assert.equal(createdResponse.status(), 201, 'Project create API rejected form');
      const project = (await createdResponse.json()).data;
      report.projectId = project.id;
      report.projectName = projectName;
      await dialog.waitFor({ state: 'hidden' });
      await page.reload();
      await page.getByPlaceholder('搜索项目名称').fill(projectName);
      await page.getByRole('button', { name: '查询', exact: true }).click();
      await page.locator('tbody tr').filter({ hasText: projectName }).waitFor();
      return { createdId: project.id, persisted: true, mobileFormVerified: true };
    });
    await check('project-scoped version directory', async () => {
      await page.goto('/projects/1');
      await page.getByRole('button', { name: '发布版本', exact: true }).click();
      await page.waitForURL('**/projects/1/versions');
      await page.getByRole('heading', { name: /发布版本/ }).waitFor();
      await page.screenshot({ path: out + '/versions.png', fullPage: true });
      return 'Project detail -> 发布版本 -> /projects/1/versions; no top-level menu by current design';
    });
    await check('organization create persists through UI', async () => {
      await page.goto('/organizations');
      await page.getByRole('button', { name: '新建组织', exact: true }).click();
      const dialog = page.getByRole('dialog');
      const name = 'QA-admin-' + suffix;
      await dialog.locator('input').fill(name);
      const responsePromise = page.waitForResponse(response =>
        new URL(response.url()).pathname === '/api/organizations' && response.request().method() === 'POST');
      await dialog.getByRole('button', { name: '保存', exact: true }).click();
      const response = await responsePromise;
      const body = await response.json();
      if (response.status() !== 201) {
        await page.screenshot({ path: out + '/organization-error.png', fullPage: true });
        throw new Error('POST /api/organizations HTTP ' + response.status() + ': ' + body.message);
      }
      await dialog.waitFor({ state: 'hidden' });
      await page.reload();
      await page.getByRole('treeitem').filter({ hasText: name }).waitFor();
      return { createdId: body.data.id, persisted: true };
    });
  } finally {
    await browser.close();
    report.status = report.checks.every(item => item.status === 'PASS') ? 'PASS' : 'FAIL';
    fs.writeFileSync(out + '/report.json', JSON.stringify(report, null, 2));
    if (report.status !== 'PASS') process.exitCode = 1;
    console.log(JSON.stringify(report));
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
