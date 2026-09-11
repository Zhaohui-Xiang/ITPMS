const { chromium } = require('/srv/itpms-dev/tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const baseURL = process.env.IPMS_QA_URL || 'http://127.0.0.1:8082';
const out = '/srv/itpms-dev/tools/qa-demo';
const password = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8')
  .split('\n').find(line => line.startsWith('IPMS_DEMO_PASSWORD=')).split('=').slice(1).join('=');
const title = '跨项目统一身份集成 ' + Date.now();
const report = { steps: [], title, versions: [] };
(async () => {
  const browser = await chromium.launch({ headless: true });
  let context, page;
  async function as(role) {
    if (context) await context.close();
    context = await browser.newContext({ baseURL, extraHTTPHeaders: { Referer: baseURL + '/' }, viewport: { width: 1440, height: 900 } });
    page = await context.newPage();
    await page.goto('/login');
    await page.getByPlaceholder('请输入账号').fill('demo.' + role);
    await page.getByPlaceholder('请输入密码').fill(password);
    await page.locator('.login-btn').click();
    await page.waitForURL('**/dashboard');
    await page.waitForLoadState('networkidle');
  }
  function field(label) {
    return page.locator('.el-dialog:visible .el-form-item').filter({ has: page.locator('.el-form-item__label', { hasText: new RegExp('^' + label + '$') }) });
  }
  async function changed(path, click) {
    const [response] = await Promise.all([
      page.waitForResponse(r => new URL(r.url()).pathname === path && ['POST', 'PUT', 'DELETE'].includes(r.request().method())),
      click(),
    ]);
    assert.ok(response.ok(), path + ': ' + response.status() + ' ' + (await response.text()).slice(0, 500));
    return (await response.json()).data;
  }
  async function choose(label, text) {
    await field(label).locator('.el-select').click();
    await page.locator('.el-select-dropdown:visible').getByText(text, { exact: true }).click();
  }
  try {
    await as('requester');
    await page.goto('/requirements');
    await page.getByRole('button', { name: '新建需求', exact: true }).click();
    await field('需求标题').locator('input').fill(title);
    await field('需求描述').locator('textarea').fill('统一身份认证与跨项目用户资料同步，覆盖核心业务和协同办公平台。');
    await choose('关联项目', '[DEMO] 核心业务平台');
    await page.locator('.el-select-dropdown:visible').getByText('[DEMO] 协同办公平台', { exact: true }).click();
    await page.keyboard.press('Escape');
    const requirement = await changed('/api/requirements', () => page.getByRole('button', { name: '保存', exact: true }).click());
    report.requirementId = requirement.id;
    report.steps.push('requester submits two-project requirement through UI');

    await as('it_pm');
    await page.goto('/requirements');
    await page.getByTestId('review-requirement-' + requirement.id).click();
    await changed('/api/requirements/' + requirement.id + '/review', () => page.locator('.el-message-box').getByRole('button', { name: '通过', exact: true }).click());
    report.steps.push('internal PM approves through UI');
    const projects = (await (await context.request.get('/api/projects')).json()).data.items;
    for (const project of projects) {
      await page.goto('/projects/' + project.id + '/versions');
      await page.getByRole('button', { name: '新建版本', exact: true }).click();
      await field('版本编号').locator('input').fill('DEMO-' + Date.now());
      await field('版本名称').locator('input').fill('统一身份集成首期');
      const version = await changed('/api/projects/' + project.id + '/versions', () => page.getByRole('button', { name: '创建版本', exact: true }).click());
      report.versions.push({ id: version.id, projectId: project.id });
      await page.goto('/project-versions/' + version.id);
      await page.getByRole('tab', { name: /需求范围/ }).click();
      await changed('/api/requirements/' + requirement.id + '/projects/' + project.id + '/version',
        () => page.getByTestId('plan-requirement-' + requirement.id).click());
      await page.getByTestId('unplan-requirement-' + requirement.id).waitFor();
    }
    report.steps.push('internal PM creates and plans independent project versions through UI');

    await as('supplier_pm');
    await page.goto('/tasks');
    await page.getByRole('button', { name: '新建任务', exact: true }).click();
    await choose('所属需求', title);
    await choose('所属项目', '[DEMO] 核心业务平台');
    await field('任务标题').locator('input').fill('统一身份接口开发');
    await field('任务描述').locator('textarea').fill('实现统一身份校验接口并完成联调。');
    await choose('负责人', '演示供应商开发');
    await field('截止日期').locator('input').fill('2026-12-31');
    await field('截止日期').locator('input').press('Enter');
    const task = await changed('/api/tasks', () => page.getByRole('button', { name: '保存', exact: true }).click());
    report.taskId = task.id;
    report.steps.push('supplier PM creates and assigns task using database assignee options');

    await as('supplier_dev');
    await page.goto('/tasks');
    const taskRow = page.locator('tbody tr').filter({ hasText: 'TASK-' + task.id });
    await taskRow.getByRole('button', { name: '开始', exact: true }).click();
    await changed('/api/tasks/' + task.id + '/status', () => page.locator('.el-message-box').getByRole('button', { name: '确认', exact: true }).click());
    report.steps.push('assigned developer starts persisted task through UI');

    await as('super_admin');
    for (const viewport of [{ width: 1440, height: 900 }, { width: 1024, height: 768 }]) {
      await page.setViewportSize(viewport);
      for (const [name, path] of [
        ['requirement-detail', '/requirements/' + requirement.id],
        ['version-detail', '/project-versions/' + report.versions[0].id],
        ['version-list', '/projects/' + report.versions[0].projectId + '/versions'],
      ]) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
        await page.screenshot({ path: out + '/' + name + '-' + viewport.width + '.png' });
      }
    }
    report.status = 'PASS';
  } catch (error) {
    report.status = 'FAIL';
    report.failure = error.message;
    if (page && !page.url().includes('/login')) await page.screenshot({ path: out + '/actions-failure.png' });
    process.exitCode = 1;
  } finally {
    fs.writeFileSync(out + '/actions-report.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
    await browser.close();
  }
})();
