const { chromium } = require('/srv/itpms-dev/tools/browser/node_modules/playwright');
const { readFileSync, mkdirSync, writeFileSync } = require('node:fs');
const assert = require('node:assert/strict');

const baseURL = process.env.IPMS_QA_URL || 'http://127.0.0.1:8082';
const out = '/srv/itpms-dev/tools/qa-demo';
const password = readFileSync('/var/www/ipms/shared/.env', 'utf8')
  .split('\n').find(line => line.startsWith('IPMS_DEMO_PASSWORD=')).split('=').slice(1).join('=');
mkdirSync(out, { recursive: true });
const report = { baseURL, started: new Date().toISOString(), pages: [], roles: [], errors: [] };

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 900 } });
    const page = await context.newPage();
    page.on('pageerror', error => report.errors.push(error.message));
    page.on('response', response => {
      if (response.status() >= 500) report.errors.push(response.status() + ' ' + new URL(response.url()).pathname);
    });
    await page.goto('/login');
    await page.screenshot({ path: out + '/login-desktop.png' });
    await login(page, 'super_admin');
    for (const viewport of [{ width: 1440, height: 900 }, { width: 1024, height: 768 }, { width: 390, height: 844 }]) {
      await page.setViewportSize(viewport);
      for (const path of ['/dashboard', '/projects', '/requirements', '/tasks', '/defects', '/documents', '/audit-logs', '/organizations']) {
        await page.goto(path);
        await page.waitForLoadState('networkidle');
        const layout = await page.evaluate(() => ({
          overflow: document.documentElement.scrollWidth > innerWidth,
          images: [...document.images].map(image => ({ src: image.getAttribute('src'), width: image.naturalWidth })),
          text: document.body.innerText.slice(0, 250),
        }));
        report.pages.push({ path, viewport, ...layout });
        await page.screenshot({ path: out + '/' + path.slice(1) + '-' + viewport.width + '.png', fullPage: true });
        assert.equal(layout.overflow, false, path + ' body overflow at ' + viewport.width);
        assert.ok(layout.images.length > 0 && layout.images.every(image => image.width > 0), 'Brand assets not loaded');
      }
    }
    await page.setViewportSize({ width: 1024, height: 768 });
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');
    await page.locator('.header-bar [aria-label="收起导航"]').click();
    await page.locator('.sidebar.collapsed').waitFor();
    await page.screenshot({ path: out + '/shell-collapsed-1024.png' });
    await context.close();
    const menus = {
      super_admin: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理', '审计日志', '组织架构'],
      it_pm: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理', '审计日志'],
      it_member: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理'],
      supplier_pm: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理'],
      supplier_dev: ['工作台', '任务管理', '缺陷管理', '文档管理'],
      supplier_tester: ['工作台', '任务管理', '缺陷管理', '文档管理'],
      requester: ['工作台', '需求管理', '缺陷管理'],
    };
    for (const role of ['super_admin', 'it_pm', 'it_member', 'supplier_pm', 'supplier_dev', 'supplier_tester', 'requester']) {
      const roleContext = await browser.newContext({ baseURL });
      const rolePage = await roleContext.newPage();
      await rolePage.goto('/login');
      await login(rolePage, role);
      await rolePage.waitForLoadState('networkidle');
      assert.ok(await rolePage.locator('main').isVisible());
      assert.deepEqual((await rolePage.locator('.sidebar-menu .el-menu-item').allTextContents()).map(text => text.trim()), menus[role]);
      if (role !== 'super_admin') {
        await rolePage.goto('/organizations');
        await rolePage.waitForURL('**/403');
      }
      report.roles.push({ role, login: 'pass', forbiddenOrganization: role === 'super_admin' ? 'not-applicable' : 'pass' });
      await roleContext.close();
    }
    assert.deepEqual(report.errors, []);
    report.status = 'PASS';
  } catch (error) {
    report.status = 'FAIL';
    report.failure = error.message;
    process.exitCode = 1;
  } finally {
    await browser.close();
    writeFileSync(out + '/report.json', JSON.stringify(report, null, 2));
    console.log(JSON.stringify(report));
  }
})();

async function login(page, role) {
  await page.getByPlaceholder('请输入账号').fill('demo.' + role);
  await page.getByPlaceholder('请输入密码').fill(password);
  await page.locator('.login-btn').click();
  await page.waitForURL('**/dashboard');
}
