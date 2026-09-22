'use strict';

/**
 * IPMS 验收接口测试套件（Sanctum SPA cookie 会话）
 *
 * 用法：node ops/acceptance/api-suite.cjs [--out <report.json>]
 * 环境变量：
 *   IPMS_ACC_URL      目标地址，默认 http://127.0.0.1
 *   IPMS_ACC_REFERER  请求 Referer，默认 http://116.62.44.192/
 *                     （SANCTUM_STATEFUL_DOMAINS 只含 116.62.44.192 / 127.0.0.1:8082 / localhost:8082，
 *                      本机 127.0.0.1:80 不在列表中，必须声明已配置的 stateful 来源才会走会话认证）
 * 演示账号密码运行时从 /var/www/ipms/shared/.env 的 IPMS_DEMO_PASSWORD 读取。
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const baseURL = (process.env.IPMS_ACC_URL || 'http://127.0.0.1').replace(/\/$/, '');
const referer = (process.env.IPMS_ACC_REFERER || 'http://116.62.44.192').replace(/\/$/, '') + '/';
const outPath = process.argv.includes('--out')
  ? process.argv[process.argv.indexOf('--out') + 1]
  : null;

const ROLES = ['super_admin', 'it_pm', 'it_member', 'supplier_pm', 'supplier_dev', 'supplier_tester', 'requester'];

function demoPassword() {
  const line = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8')
    .split('\n').find((l) => l.startsWith('IPMS_DEMO_PASSWORD='));
  assert.ok(line, 'IPMS_DEMO_PASSWORD not found in /var/www/ipms/shared/.env');
  return line.split('=').slice(1).join('=').trim();
}

/** 基于 fetch 的 Sanctum SPA cookie 会话客户端 */
class Session {
  constructor(base) {
    this.base = base;
    this.referer = referer;
    this.cookies = new Map();
  }

  storeCookies(res) {
    let lines = [];
    if (typeof res.headers.getSetCookie === 'function') {
      lines = res.headers.getSetCookie();
    } else {
      const single = res.headers.get('set-cookie');
      if (single) lines = [single];
    }
    for (const line of lines) {
      const pair = line.split(';')[0];
      const idx = pair.indexOf('=');
      if (idx > 0) this.cookies.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
    }
  }

  async request(method, urlPath, body) {
    const headers = {
      Accept: 'application/json',
      Referer: this.referer,
    };
    if (this.cookies.size > 0) {
      headers.Cookie = [...this.cookies.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
    }
    const xsrf = this.cookies.get('XSRF-TOKEN');
    if (xsrf && method !== 'GET') {
      headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);
    }
    let payload;
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
      payload = JSON.stringify(body);
    }
    const res = await fetch(this.base + urlPath, {
      method, headers, body: payload, redirect: 'manual',
    });
    this.storeCookies(res);
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { /* 非 JSON 响应 */ }
    return {
      status: res.status,
      json,
      text,
      isJson: (res.headers.get('content-type') || '').includes('application/json'),
    };
  }

  async login(username, password) {
    await this.request('GET', '/sanctum/csrf-cookie');
    return this.request('POST', '/api/login', { username, password });
  }

  get(urlPath) { return this.request('GET', urlPath); }
  post(urlPath, body) { return this.request('POST', urlPath, body); }
}

// ---------- 契约断言 ----------

function assertSuccessEnvelope(res, status = 200) {
  assert.equal(res.status, status, `HTTP ${res.status}: ${res.text.slice(0, 300)}`);
  assert.ok(res.isJson && res.json, '响应必须是 JSON');
  assert.equal(res.json.code, status, `code 字段应为 ${status}`);
  assert.equal(typeof res.json.message, 'string', '缺少 message 字段');
  assert.ok('data' in res.json, '缺少 data 字段');
  return res.json.data;
}

function assertPaginated(data) {
  assert.ok(Array.isArray(data.items), '分页 data.items 必须是数组');
  for (const key of ['page', 'page_size', 'total', 'total_pages']) {
    assert.ok(Number.isInteger(data[key]), `分页字段 ${key} 必须是整数`);
  }
  assert.ok(data.total >= data.items.length || data.page > 1, 'total 与 items 长度矛盾');
}

function assertErrorEnvelope(res, status, errorCode) {
  assert.equal(res.status, status, `期望 HTTP ${status}，实际 ${res.status}: ${res.text.slice(0, 300)}`);
  assert.ok(res.isJson && res.json, '错误响应必须是 JSON');
  assert.equal(res.json.error_code, errorCode, `error_code 应为 ${errorCode}，实际 ${res.json.error_code}`);
  assert.equal(typeof res.json.message, 'string', '错误响应缺少 message');
  assert.ok('trace_id' in res.json, '错误响应缺少 trace_id');
}

function assertUserContract(user, username) {
  assert.equal(user.username, username, 'username 不匹配');
  assert.ok(Number.isInteger(user.id), 'user.id 必须是整数');
  assert.equal(typeof user.display_name, 'string', '缺少 display_name');
  assert.ok(typeof user.email === 'string' || user.email === null, 'email 字段类型错误');
  assert.ok(Number.isInteger(user.user_type), 'user_type 必须是整数');
  assert.ok(Array.isArray(user.roles), 'roles 必须是数组');
  assert.ok(Array.isArray(user.permissions), 'permissions 必须是数组');
  assert.ok(Array.isArray(user.organizations), 'organizations 必须是数组');
  assert.equal(typeof user.must_change_password, 'boolean', 'must_change_password 必须是布尔值');
  if (username === 'demo.super_admin') {
    assert.equal(user.is_super_admin, true, 'super_admin 的 is_super_admin 应为 true');
  }
}

// ---------- 用例执行器 ----------

const checks = [];

async function check(name, fn) {
  const started = performance.now();
  try {
    await fn();
    checks.push({ name, suite: 'api', status: 'PASS', durationMs: Math.round(performance.now() - started) });
  } catch (error) {
    if (error && error.skip) {
      checks.push({ name, suite: 'api', status: 'SKIP', durationMs: Math.round(performance.now() - started), error: error.message });
    } else {
      checks.push({ name, suite: 'api', status: 'FAIL', durationMs: Math.round(performance.now() - started), error: String(error.message || error).slice(0, 600) });
    }
  }
}

function skip(reason) {
  const error = new Error(reason);
  error.skip = true;
  throw error;
}

// ---------- 用例定义 ----------

async function run() {
  const password = demoPassword();

  // --- 匿名访问 ---
  await check('匿名访问 /api/user 返回 401 JSON', async () => {
    const res = await new Session(baseURL).get('/api/user');
    assertErrorEnvelope(res, 401, 'UNAUTHENTICATED');
  });
  await check('匿名访问 /api/projects 返回 401 JSON', async () => {
    const res = await new Session(baseURL).get('/api/projects');
    assertErrorEnvelope(res, 401, 'UNAUTHENTICATED');
  });
  await check('错误密码登录返回 422 VALIDATION_FAILED', async () => {
    const session = new Session(baseURL);
    const res = await session.login('demo.requester', 'wrong-password-acc-test');
    assertErrorEnvelope(res, 422, 'VALIDATION_FAILED');
    assert.ok(res.json.errors && Array.isArray(res.json.errors.username), 'errors.username 应为数组');
  });

  // --- 7 角色会话与列表/详情契约 ---
  const sessions = {};
  const firstIds = {}; // role -> {projects, requirements, tasks, defects}

  for (const role of ROLES) {
    const username = 'demo.' + role;
    const session = new Session(baseURL);

    await check(`登录：${username}`, async () => {
      const res = await session.login(username, password);
      const data = assertSuccessEnvelope(res, 200);
      assertUserContract(data.user, username);
      sessions[role] = session;
    });
    if (!sessions[role]) continue; // 登录失败则该角色后续用例整体跳过

    await check(`会话保持：${username} GET /api/user`, async () => {
      const data = assertSuccessEnvelope(await session.get('/api/user'), 200);
      assertUserContract(data.user, username);
    });

    const listEndpoints = [
      ['projects', '/api/projects?page_size=5'],
      ['requirements', '/api/requirements?page_size=5'],
      ['tasks', '/api/tasks?page_size=5'],
      ['defects', '/api/defects?page_size=5'],
      ['notifications', '/api/notifications?page_size=5'],
      ['audit-logs', '/api/audit-logs?page_size=5'],
    ];
    for (const [module, url] of listEndpoints) {
      await check(`列表契约：${username} GET ${url}`, async () => {
        const data = assertSuccessEnvelope(await session.get(url), 200);
        assertPaginated(data);
        (firstIds[role] ||= {})[module] = data.items.length > 0 ? data.items[0].id : null;
      });
    }

    await check(`未读通知计数：${username} GET /api/notifications/unread-count`, async () => {
      const data = assertSuccessEnvelope(await session.get('/api/notifications/unread-count'), 200);
      assert.ok(Number.isInteger(data.unread_count), 'unread_count 必须是整数');
    });

    await check(`工作台汇总：${username} GET /api/dashboard/summary`, async () => {
      const data = assertSuccessEnvelope(await session.get('/api/dashboard/summary'), 200);
      assert.ok(data && typeof data === 'object', 'summary 必须是对象');
    });

    await check(`登出：${username}`, async () => {
      assertSuccessEnvelope(await session.post('/api/logout'), 200);
      const after = await session.get('/api/user');
      assertErrorEnvelope(after, 401, 'UNAUTHENTICATED');
    });
  }

  // --- 详情契约（重新登录需要详情的角色） ---
  const detailRoles = ['super_admin', 'it_pm', 'supplier_pm', 'requester'];
  for (const role of detailRoles) {
    const session = new Session(baseURL);
    await session.login('demo.' + role, password);
    const ids = firstIds[role] || {};

    for (const [module, urlFn] of [
      ['projects', (id) => `/api/projects/${id}`],
      ['requirements', (id) => `/api/requirements/${id}`],
      ['tasks', (id) => `/api/tasks/${id}`],
      ['defects', (id) => `/api/defects/${id}`],
    ]) {
      await check(`详情契约：demo.${role} GET ${urlFn(ids[module] ?? 0)}`, async () => {
        if (!ids[module]) skip(`demo.${role} 的 ${module} 列表为空，无详情可校验`);
        const data = assertSuccessEnvelope(await session.get(urlFn(ids[module])), 200);
        assert.equal(data.id, ids[module], '详情 id 与列表 id 不一致');
      });
    }

    await check(`版本列表：demo.${role} GET /api/projects/{id}/versions`, async () => {
      if (!ids.projects) skip(`demo.${role} 无可见项目`);
      const data = assertSuccessEnvelope(await session.get(`/api/projects/${ids.projects}/versions?page_size=5`), 200);
      assertPaginated(data);
    });

    await session.post('/api/logout');
  }

  // --- 越权断言 ---
  const forbiddenMatrix = [
    ['requester', '/api/organizations'],
    ['it_pm', '/api/organizations'],
    ['supplier_pm', '/api/organizations'],
    ['requester', '/api/users'],
    ['it_member', '/api/users'],
    ['supplier_dev', '/api/users'],
  ];
  for (const [role, url] of forbiddenMatrix) {
    await check(`越权：demo.${role} GET ${url} 返回 403 FORBIDDEN`, async () => {
      const session = new Session(baseURL);
      await session.login('demo.' + role, password);
      assertErrorEnvelope(await session.get(url), 403, 'FORBIDDEN');
      await session.post('/api/logout');
    });
  }

  await check('越权：demo.requester 审核需求返回 403', async () => {
    const session = new Session(baseURL);
    await session.login('demo.requester', password);
    const list = assertSuccessEnvelope(await session.get('/api/requirements?page_size=1'), 200);
    if (list.items.length === 0) {
      await session.post('/api/logout');
      skip('requester 无可见需求，无法验证审核越权');
    }
    const res = await session.post(`/api/requirements/${list.items[0].id}/review`, { action: 'approve' });
    assertErrorEnvelope(res, 403, 'FORBIDDEN');
    await session.post('/api/logout');
  });

  await check('越权：demo.supplier_dev 创建任务返回 403', async () => {
    const session = new Session(baseURL);
    await session.login('demo.supplier_dev', password);
    const res = await session.post('/api/tasks', {
      title: 'acc-test 越权任务', requirement_id: 1, project_id: 1,
    });
    assert.ok([403, 422].includes(res.status), `期望 403/422，实际 ${res.status}`);
    if (res.status === 403) assertErrorEnvelope(res, 403, 'FORBIDDEN');
    await session.post('/api/logout');
  });

  await check('越权：demo.it_pm 创建项目返回 403（仅超管）', async () => {
    const session = new Session(baseURL);
    await session.login('demo.it_pm', password);
    const res = await session.post('/api/projects', { name: 'acc-test 越权项目' });
    assertErrorEnvelope(res, 403, 'FORBIDDEN');
    await session.post('/api/logout');
  });

  await check('组织树契约：demo.super_admin GET /api/organizations', async () => {
    const session = new Session(baseURL);
    await session.login('demo.super_admin', password);
    const data = assertSuccessEnvelope(await session.get('/api/organizations'), 200);
    assert.ok(Array.isArray(data), '组织树必须是数组');
    if (data.length > 0) {
      assert.ok(Number.isInteger(data[0].id) && typeof data[0].name === 'string', '组织节点缺少 id/name');
      assert.ok(Array.isArray(data[0].children), '组织节点 children 必须是数组');
    }
    await session.post('/api/logout');
  });

  await check('资源不存在：GET /api/requirements/999999 返回 404 JSON', async () => {
    const session = new Session(baseURL);
    await session.login('demo.super_admin', password);
    const res = await session.get('/api/requirements/999999');
    assert.equal(res.status, 404, `期望 404，实际 ${res.status}`);
    assert.ok(res.isJson && res.json, '404 响应必须是 JSON');
    await session.post('/api/logout');
  });
}

// ---------- 入口 ----------

(async () => {
  const started = new Date();
  await run();
  const summary = {
    suite: 'api',
    baseURL,
    started: started.toISOString(),
    finished: new Date().toISOString(),
    total: checks.length,
    passed: checks.filter((c) => c.status === 'PASS').length,
    failed: checks.filter((c) => c.status === 'FAIL').length,
    skipped: checks.filter((c) => c.status === 'SKIP').length,
    checks,
  };
  const line = `api-suite: ${summary.passed}/${summary.total} 通过，${summary.failed} 失败，${summary.skipped} 跳过`;
  console.log(line);
  for (const c of checks.filter((c) => c.status !== 'PASS')) {
    console.log(`  [${c.status}] ${c.name}: ${c.error || ''}`);
  }
  if (outPath) {
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify(summary, null, 2) + '\n');
  }
  if (summary.failed > 0) process.exitCode = 1;
})().catch((error) => {
  console.error('api-suite 执行异常:', error);
  if (outPath) {
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify({
      suite: 'api', baseURL, fatal: String(error.stack || error), checks,
    }, null, 2) + '\n');
  }
  process.exitCode = 1;
});
