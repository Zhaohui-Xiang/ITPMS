'use strict';

/**
 * IPMS 验收功能测试套件（Playwright 浏览器端到端，从零实现，不复用 ops/qa-*.cjs）
 *
 * 用法：node ops/acceptance/functional-suite.cjs [--out <report.json>]
 * 环境变量：
 *   IPMS_FUNC_URL             功能测试目标，默认 http://116.62.44.192（公网演示地址）
 *     注意：SANCTUM_STATEFUL_DOMAINS 未包含 127.0.0.1:80，浏览器从 127.0.0.1 访问时
 *     SPA 会话认证不可用（登录后仍 401），因此浏览器端测试必须走已配置的域名。
 *   IPMS_QA_URL               兼容变量，IPMS_FUNC_URL 未设置时生效
 *   IPMS_ACC_SHOTS            截图目录，默认 <repo>/ops/reports/functional-latest/screenshots
 *   PLAYWRIGHT_BROWSERS_PATH  浏览器目录，默认 /home/itpms/.cache/ms-playwright
 *
 * 覆盖：
 *   A. 7 个演示角色逐一登录 -> 菜单按角色差异化渲染校验 -> 登出
 *   B. 核心业务闭环 UI 走查：requester 提交需求 -> it_pm 审核 -> it_pm 拆分任务
 *      -> supplier_dev 认领任务 -> supplier_tester 登记缺陷 -> supplier_dev 可见
 *      -> 版本发布入口按角色区分可见性
 *   C. 越权 UI：requester / supplier_pm 直连 /organizations 进入 403 页
 *
 * UI 走查创建的数据带 acc-test 标记；当前部署版本无需求/任务/缺陷删除接口，
 * 无法通过 UI 或 API 清理，数据保留并在报告中如实记录。
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

process.env.PLAYWRIGHT_BROWSERS_PATH = process.env.PLAYWRIGHT_BROWSERS_PATH
  || '/home/itpms/.cache/ms-playwright';
const { chromium } = require('/srv/itpms-dev/tools/browser/node_modules/playwright');

const baseURL = (process.env.IPMS_FUNC_URL || process.env.IPMS_QA_URL || 'http://116.62.44.192').replace(/\/$/, '');
const outPath = process.argv.includes('--out')
  ? process.argv[process.argv.indexOf('--out') + 1]
  : null;
const shotsDir = process.env.IPMS_ACC_SHOTS
  || path.join(__dirname, '..', 'reports', 'functional-latest', 'screenshots');
fs.mkdirSync(shotsDir, { recursive: true });

const tag = 'acc-test-' + new Date().toISOString().replace(/[-:T]/g, '').slice(0, 14);
const DEMO_PROJECT_NAME = '[DEMO] 核心业务平台';

const EXPECTED_MENUS = {
  super_admin: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理', '审计日志', '组织架构'],
  it_pm: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理', '审计日志'],
  it_member: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理'],
  supplier_pm: ['工作台', '项目管理', '需求管理', '任务管理', '缺陷管理', '文档管理'],
  supplier_dev: ['工作台', '任务管理', '缺陷管理', '文档管理'],
  supplier_tester: ['工作台', '任务管理', '缺陷管理', '文档管理'],
  requester: ['工作台', '需求管理', '缺陷管理'],
};

function demoPassword() {
  const line = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8')
    .split('\n').find((l) => l.startsWith('IPMS_DEMO_PASSWORD='));
  assert.ok(line, 'IPMS_DEMO_PASSWORD not found');
  return line.split('=').slice(1).join('=').trim();
}

// ---------- 用例执行器 ----------

const checks = [];
const artifacts = { tag, requirementId: null, taskId: null, defectId: null, projectId: null };

async function check(name, fn) {
  const started = performance.now();
  const record = { name, suite: 'functional' };
  try {
    const evidence = await fn();
    Object.assign(record, {
      status: 'PASS',
      durationMs: Math.round(performance.now() - started),
    });
    if (evidence) record.evidence = typeof evidence === 'string' ? evidence : JSON.stringify(evidence).slice(0, 400);
  } catch (error) {
    if (error && error.skip) {
      Object.assign(record, { status: 'SKIP', durationMs: Math.round(performance.now() - started), error: error.message });
    } else {
      Object.assign(record, { status: 'FAIL', durationMs: Math.round(performance.now() - started), error: String(error.message || error).slice(0, 800) });
    }
  }
  checks.push(record);
  console.log(`[${record.status}] ${name}${record.error ? ' -- ' + record.error : ''}`);
  return record.status === 'PASS';
}

function skip(reason) {
  const error = new Error(reason);
  error.skip = true;
  throw error;
}

async function screenshot(page, name) {
  const file = path.join(shotsDir, name + '.png');
  try {
    await page.screenshot({ path: file, fullPage: true });
    return file;
  } catch {
    return null;
  }
}

// ---------- UI 交互辅助 ----------

async function login(page, role, password) {
  await page.goto('/login');
  await page.getByPlaceholder('请输入账号').fill('demo.' + role);
  await page.getByPlaceholder('请输入密码').fill(password);
  await page.locator('.login-btn').click();
  await page.waitForURL('**/dashboard', { timeout: 20000 });
  await page.waitForLoadState('networkidle');
}

async function logout(page) {
  await page.locator('.header-bar .user-info').click();
  await page.locator('.el-dropdown-menu__item', { hasText: '退出登录' }).click();
  await page.waitForURL('**/login**', { timeout: 15000 });
}

function formItem(page, dialog, label) {
  return dialog.locator('.el-form-item')
    .filter({ has: page.locator('.el-form-item__label', { hasText: new RegExp('^' + label + '$') }) });
}

/** 在可见的 Element Plus 下拉中选择选项；filterable 场景先输入关键字过滤 */
async function chooseOption(page, dialog, label, text) {
  const select = formItem(page, dialog, label).locator('.el-select');
  await select.click();
  const dropdown = page.locator('.el-select-dropdown:visible');
  await dropdown.waitFor();
  const filterInput = select.locator('input');
  try {
    await filterInput.fill(text.slice(0, 20), { timeout: 2000 });
    await page.waitForTimeout(400);
  } catch { /* 非 filterable，直接点选项 */ }
  await dropdown.getByText(text, { exact: true }).click();
  // 多选下拉选中后不自动收起，需手动关闭避免遮挡后续操作
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);
  if (await page.locator('.el-select-dropdown:visible').count() > 0) {
    await dialog.locator('.el-dialog__title').click();
    await page.waitForTimeout(300);
  }
}

/** 触发 UI 写操作并校验响应 */
async function uiWrite(page, method, urlPath, action, expected = [200, 201]) {
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => new URL(r.url()).pathname === urlPath && r.request().method() === method,
      { timeout: 20000 },
    ),
    action(),
  ]);
  assert.ok(
    expected.includes(response.status()),
    `${method} ${urlPath}: HTTP ${response.status} ${(await response.text()).slice(0, 400)}`,
  );
  return (await response.json()).data;
}

async function apiGet(page, urlPath) {
  const response = await page.context().request.get(urlPath);
  assert.equal(response.status(), 200, `GET ${urlPath}: HTTP ${response.status()}`);
  return (await response.json()).data;
}

async function searchList(page, placeholder, keyword) {
  const input = page.getByPlaceholder(placeholder);
  await input.fill(keyword);
  await input.press('Enter');
  // 部分列表（如旧构建的缺陷列表）回车不触发查询，需要点击查询按钮
  const searchButton = page.getByRole('button', { name: '查询', exact: true });
  if (await searchButton.count() > 0) {
    await searchButton.first().click();
  }
  await page.waitForLoadState('networkidle');
}

// ---------- 套件主体 ----------

(async () => {
  const started = new Date();
  const password = demoPassword();
  const browser = await chromium.launch({ headless: true });

  const newPage = async () => {
    const context = await browser.newContext({
      baseURL,
      viewport: { width: 1440, height: 900 },
      extraHTTPHeaders: { Referer: baseURL + '/' },
    });
    context.setDefaultTimeout(15000);
    const page = await context.newPage();
    page.on('pageerror', () => {});
    return { context, page };
  };

  // ========== A. 7 角色登录 / 菜单差异化 / 登出 ==========
  for (const [role, expectedMenus] of Object.entries(EXPECTED_MENUS)) {
    const { context, page } = await newPage();
    await check(`角色登录与菜单：demo.${role}`, async () => {
      await login(page, role, password);
      await page.waitForSelector('.sidebar-menu .el-menu-item');
      const menus = (await page.locator('.sidebar-menu .el-menu-item').allInnerTexts())
        .map((t) => t.trim()).filter(Boolean).sort();
      assert.deepEqual(menus, expectedMenus.slice().sort(),
        `菜单不匹配，实际: ${menus.join('/')}`);
      assert.ok(await page.getByRole('heading', { name: '工作台' }).count() > 0
        || await page.locator('.page-title', { hasText: '工作台' }).count() > 0
        || page.url().includes('/dashboard'), '未进入工作台');
      const shot = await screenshot(page, `menu-${role}`);
      await logout(page);
      assert.ok(page.url().includes('/login'), '登出后未回到登录页');
      return { menus, screenshot: shot };
    });
    await context.close();
  }

  // ========== B. 核心业务闭环 UI 走查 ==========
  const requirementTitle = `${tag} 跨项目协同验收需求`;
  const taskTitle = `${tag} 开发任务`;
  const defectTitle = `${tag} 缺陷`;
  let loopOk = true;

  // B1. requester 提交需求
  {
    const { context, page } = await newPage();
    const ok = await check('闭环-B1 requester 提交需求（UI）', async () => {
      if (!loopOk) skip('前序步骤失败');
      await login(page, 'requester', password);
      await page.goto('/requirements');
      await page.waitForLoadState('networkidle');
      await page.getByRole('button', { name: '新建需求', exact: true }).click();
      const dialog = page.getByRole('dialog');
      await formItem(page, dialog, '需求标题').locator('input').fill(requirementTitle);
      await formItem(page, dialog, '需求描述').locator('textarea')
        .fill('验收测试闭环需求：覆盖提交、审核、任务拆分、供应商协作与版本发布入口可见性。');
      await chooseOption(page, dialog, '关联项目', DEMO_PROJECT_NAME);
      await screenshot(page, 'b1-requirement-form');
      const created = await uiWrite(page, 'POST', '/api/requirements',
        () => dialog.getByRole('button', { name: '保存', exact: true }).click());
      artifacts.requirementId = created.id;
      await dialog.waitFor({ state: 'hidden' });
      const persisted = await apiGet(page, `/api/requirements/${created.id}`);
      assert.equal(persisted.title, requirementTitle);
      assert.equal(persisted.status_code, 'PENDING_REVIEW', '新需求应为待审核');
      await page.goto('/requirements');
      await searchList(page, '搜索需求标题', requirementTitle);
      await page.getByText(requirementTitle, { exact: true }).first()
        .waitFor({ timeout: 10000 })
        .catch(() => assert.fail('列表未出现新需求'));
      const shot = await screenshot(page, 'b1-requirement-listed');
      return { id: created.id, screenshot: shot };
    });
    loopOk = loopOk && ok;
    await context.close();
  }

  // B2. it_pm 审核通过
  {
    const { context, page } = await newPage();
    const ok = await check('闭环-B2 it_pm 审核通过需求（UI）', async () => {
      if (!loopOk) skip('B1 失败');
      await login(page, 'it_pm', password);
      await page.goto('/requirements');
      await page.waitForLoadState('networkidle');
      await searchList(page, '搜索需求标题', requirementTitle);
      await page.getByText(requirementTitle, { exact: true }).first().waitFor();
      await page.getByTestId(`review-requirement-${artifacts.requirementId}`).click();
      const box = page.locator('.el-message-box');
      await uiWrite(page, 'POST', `/api/requirements/${artifacts.requirementId}/review`,
        () => box.getByRole('button', { name: '通过', exact: true }).click(), [200]);
      const persisted = await apiGet(page, `/api/requirements/${artifacts.requirementId}`);
      assert.equal(persisted.status_code, 'ASSIGNED', `审核后应为 ASSIGNED，实际 ${persisted.status_code}`);
      const shot = await screenshot(page, 'b2-requirement-approved');
      return { status: persisted.status_code, screenshot: shot };
    });
    loopOk = loopOk && ok;
    await context.close();
  }

  // B3. it_pm 拆分任务
  {
    const { context, page } = await newPage();
    const ok = await check('闭环-B3 it_pm 拆分任务（UI）', async () => {
      if (!loopOk) skip('B2 失败');
      await login(page, 'it_pm', password);
      await page.goto('/tasks');
      await page.waitForLoadState('networkidle');
      await page.getByRole('button', { name: '新建任务', exact: true }).click();
      const dialog = page.getByRole('dialog');
      await chooseOption(page, dialog, '所属需求', requirementTitle);
      await chooseOption(page, dialog, '所属项目', DEMO_PROJECT_NAME);
      await formItem(page, dialog, '任务标题').locator('input').fill(taskTitle);
      await formItem(page, dialog, '任务描述').locator('textarea').fill('验收测试任务：供应商认领与协作。');
      const dueInput = formItem(page, dialog, '截止日期').locator('input');
      await dueInput.fill('2026-12-31');
      await dueInput.press('Enter');
      await page.waitForTimeout(300);
      if (await page.locator('.el-picker-panel:visible').count() > 0) {
        await dialog.locator('.el-dialog__title').click();
      }
      await screenshot(page, 'b3-task-form');
      const created = await uiWrite(page, 'POST', '/api/tasks',
        () => dialog.getByRole('button', { name: '保存', exact: true }).click());
      artifacts.taskId = created.id;
      await dialog.waitFor({ state: 'hidden' });
      const persisted = await apiGet(page, `/api/tasks/${created.id}`);
      assert.equal(persisted.title, taskTitle);
      assert.equal(persisted.requirement?.id ?? persisted.requirement_id, artifacts.requirementId);
      await page.goto('/tasks');
      await searchList(page, '搜索任务标题', taskTitle);
      await page.getByText(taskTitle, { exact: true }).first()
        .waitFor({ timeout: 10000 })
        .catch(() => assert.fail('任务列表未出现新任务'));
      const shot = await screenshot(page, 'b3-task-listed');
      return { id: created.id, screenshot: shot };
    });
    loopOk = loopOk && ok;
    await context.close();
  }

  // B4. supplier_dev 认领任务
  {
    const { context, page } = await newPage();
    const ok = await check('闭环-B4 supplier_dev 认领任务（UI）', async () => {
      if (!loopOk) skip('B3 失败');
      await login(page, 'supplier_dev', password);
      const me = await apiGet(page, '/api/user');
      await page.goto('/tasks');
      await page.waitForLoadState('networkidle');
      await searchList(page, '搜索任务标题', taskTitle);
      await page.getByText(taskTitle, { exact: true }).first().waitFor();
      await page.getByTestId(`claim-task-${artifacts.taskId}`).click();
      const box = page.locator('.el-message-box');
      await uiWrite(page, 'POST', `/api/tasks/${artifacts.taskId}/claim`,
        () => box.getByRole('button', { name: '认领', exact: true }).click(), [200]);
      const persisted = await apiGet(page, `/api/tasks/${artifacts.taskId}`);
      assert.equal(persisted.assignee?.id, me.user.id, '任务负责人应为 supplier_dev');
      const shot = await screenshot(page, 'b4-task-claimed');
      return { assignee: persisted.assignee?.display_name, screenshot: shot };
    });
    loopOk = loopOk && ok;
    await context.close();
  }

  // B5. supplier_tester 登记缺陷
  {
    const { context, page } = await newPage();
    const ok = await check('闭环-B5 supplier_tester 登记缺陷（UI）', async () => {
      if (!loopOk) skip('B2 失败（缺陷登记依赖已审核需求）');
      await login(page, 'supplier_tester', password);
      await page.goto('/defects');
      await page.waitForLoadState('networkidle');
      await page.getByRole('button', { name: '登记缺陷', exact: true }).click();
      const dialog = page.getByRole('dialog');
      await chooseOption(page, dialog, '所属需求', requirementTitle);
      await chooseOption(page, dialog, '所属项目', DEMO_PROJECT_NAME);
      await formItem(page, dialog, '缺陷标题').locator('input').fill(defectTitle);
      await formItem(page, dialog, '缺陷描述').locator('textarea').fill('验收测试缺陷：供应商测试登记。');
      await screenshot(page, 'b5-defect-form');
      const created = await uiWrite(page, 'POST', '/api/defects',
        () => dialog.getByRole('button', { name: '保存', exact: true }).click());
      artifacts.defectId = created.id;
      await dialog.waitFor({ state: 'hidden' });
      const persisted = await apiGet(page, `/api/defects/${created.id}`);
      assert.equal(persisted.title, defectTitle);
      const shot = await screenshot(page, 'b5-defect-created');
      return { id: created.id, screenshot: shot };
    });
    loopOk = loopOk && ok;
    await context.close();
  }

  // B6. supplier_dev 可见该缺陷（供应商协作）
  {
    const { context, page } = await newPage();
    await check('闭环-B6 supplier_dev 可见缺陷（供应商协作）', async () => {
      if (!artifacts.defectId) skip('B5 失败');
      await login(page, 'supplier_dev', password);
      await page.goto('/defects');
      await page.waitForLoadState('networkidle');
      await searchList(page, '搜索缺陷标题', defectTitle);
      await page.getByText(defectTitle, { exact: true }).first()
        .waitFor({ timeout: 10000 })
        .catch(() => assert.fail('supplier_dev 缺陷列表看不到供应商测试登记的缺陷'));
      const shot = await screenshot(page, 'b6-defect-visible');
      return { screenshot: shot };
    });
    await context.close();
  }

  // B7/B8. 版本发布入口可见性按角色区分
  {
    const { context, page } = await newPage();
    await check('闭环-B7 it_pm 可见版本发布入口', async () => {
      await login(page, 'it_pm', password);
      const projects = await apiGet(page, `/api/projects?keyword=${encodeURIComponent('DEMO')}&page_size=20`);
      const project = projects.items.find((p) => p.name === DEMO_PROJECT_NAME) || projects.items[0];
      if (!project) skip('it_pm 无可见 DEMO 项目');
      artifacts.projectId = project.id;
      await page.goto(`/projects/${project.id}/versions`);
      await page.waitForLoadState('networkidle');
      await page.getByRole('button', { name: '新建版本', exact: true }).first()
        .waitFor({ timeout: 10000 })
        .catch(() => assert.fail(`it_pm 在项目「${project.name}」版本页看不到「新建版本」入口`));
      const shot = await screenshot(page, 'b7-versions-it-pm');
      return { projectId: project.id, screenshot: shot };
    });
    await context.close();
  }
  {
    const { context, page } = await newPage();
    await check('闭环-B8 supplier_dev 不可见版本发布入口', async () => {
      if (!artifacts.projectId) skip('B7 未取得项目 id');
      await login(page, 'supplier_dev', password);
      await page.goto(`/projects/${artifacts.projectId}/versions`);
      await page.waitForLoadState('networkidle').catch(() => {});
      await page.waitForTimeout(1500);
      assert.equal(
        await page.getByRole('button', { name: '新建版本', exact: true }).count(), 0,
        'supplier_dev 不应看到「新建版本」入口',
      );
      const shot = await screenshot(page, 'b8-versions-supplier-dev');
      return { screenshot: shot };
    });
    await context.close();
  }

  // ========== C. 越权 UI ==========
  for (const role of ['requester', 'supplier_pm']) {
    const { context, page } = await newPage();
    await check(`越权：demo.${role} 直连 /organizations 进入 403 页`, async () => {
      await login(page, role, password);
      await page.goto('/organizations');
      await page.waitForURL('**/403', { timeout: 15000 });
      assert.ok(await page.locator('.error-title', { hasText: '无访问权限' }).count() > 0,
        '403 页未渲染「无访问权限」');
      const shot = await screenshot(page, `c-forbidden-${role}`);
      return { screenshot: shot };
    });
    await context.close();
  }

  await browser.close();

  const summary = {
    suite: 'functional',
    baseURL,
    started: started.toISOString(),
    finished: new Date().toISOString(),
    total: checks.length,
    passed: checks.filter((c) => c.status === 'PASS').length,
    failed: checks.filter((c) => c.status === 'FAIL').length,
    skipped: checks.filter((c) => c.status === 'SKIP').length,
    checks,
    artifacts,
    cleanupNote: '当前部署版本无需求/任务/缺陷删除接口，acc-test 标记数据保留在演示库中：'
      + `需求#${artifacts.requirementId ?? '-'} 任务#${artifacts.taskId ?? '-'} 缺陷#${artifacts.defectId ?? '-'}`,
    screenshotsDir: shotsDir,
  };
  console.log(`functional-suite: ${summary.passed}/${summary.total} 通过，${summary.failed} 失败，${summary.skipped} 跳过`);
  console.log(summary.cleanupNote);
  if (outPath) {
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify(summary, null, 2) + '\n');
  }
  if (summary.failed > 0) process.exitCode = 1;
})().catch((error) => {
  console.error('functional-suite 执行异常:', error);
  if (outPath) {
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify({
      suite: 'functional', baseURL, fatal: String(error.stack || error), checks, artifacts,
    }, null, 2) + '\n');
  }
  process.exitCode = 1;
});
