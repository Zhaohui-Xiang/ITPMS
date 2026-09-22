'use strict';

/**
 * IPMS 验收压力测试套件（Node 内置并发压测器，无外部依赖）
 *
 * 用法：node ops/acceptance/stress-suite.cjs [--out <report.json>]
 * 环境变量：
 *   IPMS_ACC_URL            目标地址，默认 http://127.0.0.1（绕开公网，测应用本身）
 *   IPMS_ACC_REFERER        请求 Referer，默认 http://116.62.44.192/
 *                           （SANCTUM_STATEFUL_DOMAINS 不含 127.0.0.1:80，需声明 stateful 来源）
 *   IPMS_STRESS_STAGE_SEC   已认证读接口每档秒数，默认 20
 *   IPMS_STRESS_LOGIN_SEC   登录接口压测秒数，默认 15
 *
 * 场景：
 *   1. 登录接口（GET csrf-cookie + POST /api/login 完整握手），低并发 3
 *   2. 已认证读接口（列表类轮询），阶梯并发 10 -> 25 -> 50
 * 安全阀：单档错误率 >10% 或 p99 >10s 时自动中止后续档位；总时长上限 5 分钟；不做写操作压测。
 */

const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const baseURL = (process.env.IPMS_ACC_URL || 'http://127.0.0.1').replace(/\/$/, '');
const referer = (process.env.IPMS_ACC_REFERER || 'http://116.62.44.192').replace(/\/$/, '') + '/';
const outPath = process.argv.includes('--out')
  ? process.argv[process.argv.indexOf('--out') + 1]
  : null;

const STAGE_SEC = Number(process.env.IPMS_STRESS_STAGE_SEC || 20);
const LOGIN_SEC = Number(process.env.IPMS_STRESS_LOGIN_SEC || 15);
const REQUEST_TIMEOUT_MS = 10000;
const ERROR_RATE_LIMIT = 0.10;
const P99_LIMIT_MS = 10000;
const TOTAL_BUDGET_MS = 5 * 60 * 1000;

const DEMO_USERS = [
  'demo.super_admin', 'demo.it_pm', 'demo.it_member',
  'demo.supplier_pm', 'demo.supplier_dev', 'demo.supplier_tester', 'demo.requester',
];

const READ_ENDPOINTS = [
  '/api/projects?page_size=20',
  '/api/requirements?page_size=20',
  '/api/tasks?page_size=20',
  '/api/defects?page_size=20',
  '/api/notifications/unread-count',
  '/api/dashboard/summary',
];

function demoPassword() {
  const line = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8')
    .split('\n').find((l) => l.startsWith('IPMS_DEMO_PASSWORD='));
  assert.ok(line, 'IPMS_DEMO_PASSWORD not found');
  return line.split('=').slice(1).join('=').trim();
}

function percentile(sorted, p) {
  if (sorted.length === 0) return null;
  const idx = Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1);
  return Math.round(sorted[Math.max(0, idx)]);
}

/** 一次完整登录握手：GET csrf-cookie + POST login */
async function loginHandshake(username, password) {
  const jar = new Map();
  const store = (res) => {
    const lines = typeof res.headers.getSetCookie === 'function'
      ? res.headers.getSetCookie()
      : [res.headers.get('set-cookie')].filter(Boolean);
    for (const line of lines) {
      const pair = line.split(';')[0];
      const idx = pair.indexOf('=');
      if (idx > 0) jar.set(pair.slice(0, idx).trim(), pair.slice(idx + 1).trim());
    }
  };
  const cookieHeader = () => [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');

  const csrf = await fetch(baseURL + '/sanctum/csrf-cookie', {
    headers: { Accept: 'application/json', Referer: referer },
    signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
  });
  store(csrf);
  await csrf.arrayBuffer();

  const xsrf = jar.get('XSRF-TOKEN');
  const res = await fetch(baseURL + '/api/login', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Referer: referer,
      Cookie: cookieHeader(),
      ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
    },
    body: JSON.stringify({ username, password }),
    signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
  });
  store(res);
  await res.arrayBuffer();
  return { status: res.status, jar };
}

/**
 * 单档压测：concurrency 个 worker 在 durationMs 内循环发请求。
 * makeRequest(workerIndex) 返回 { ok, status } 或抛错。
 */
async function runStage(name, concurrency, durationMs, makeRequest) {
  const latencies = [];
  let requests = 0;
  let errors = 0;
  let timeouts = 0;
  const statusCounts = {};

  const started = Date.now();
  const deadline = started + durationMs;
  const workers = Array.from({ length: concurrency }, (_, workerIndex) => (async () => {
    while (Date.now() < deadline) {
      const t0 = performance.now();
      try {
        const { ok, status } = await makeRequest(workerIndex);
        const ms = performance.now() - t0;
        latencies.push(ms);
        requests += 1;
        statusCounts[status] = (statusCounts[status] || 0) + 1;
        if (!ok) errors += 1;
      } catch (error) {
        const ms = performance.now() - t0;
        latencies.push(ms);
        requests += 1;
        errors += 1;
        if (error.name === 'TimeoutError' || error.name === 'AbortError' || ms >= REQUEST_TIMEOUT_MS - 50) {
          timeouts += 1;
        }
        statusCounts.error = (statusCounts.error || 0) + 1;
      }
    }
  })());
  await Promise.all(workers);

  const elapsed = (Date.now() - started) / 1000;
  const sorted = latencies.slice().sort((a, b) => a - b);
  const stage = {
    name,
    concurrency,
    durationSec: Math.round(elapsed * 10) / 10,
    requests,
    rps: Math.round((requests / elapsed) * 10) / 10,
    errors,
    errorRate: requests > 0 ? Math.round((errors / requests) * 10000) / 10000 : 0,
    timeouts,
    p50: percentile(sorted, 50),
    p95: percentile(sorted, 95),
    p99: percentile(sorted, 99),
    min: sorted.length ? Math.round(sorted[0]) : null,
    max: sorted.length ? Math.round(sorted[sorted.length - 1]) : null,
    statusCounts,
  };
  console.log(
    `${name.padEnd(28)} 并发=${String(concurrency).padStart(3)} 请求=${String(requests).padStart(5)} `
    + `RPS=${String(stage.rps).padStart(7)} p50=${String(stage.p50).padStart(5)}ms `
    + `p95=${String(stage.p95).padStart(5)}ms p99=${String(stage.p99).padStart(5)}ms `
    + `错误=${(stage.errorRate * 100).toFixed(2)}% 超时=${timeouts}`,
  );
  return stage;
}

function exceedsSafety(stage) {
  if (stage.errorRate > ERROR_RATE_LIMIT) {
    return `错误率 ${(stage.errorRate * 100).toFixed(2)}% 超过 10%`;
  }
  if (stage.p99 !== null && stage.p99 > P99_LIMIT_MS) {
    return `p99 ${stage.p99}ms 超过 10000ms`;
  }
  return null;
}

/**
 * 档位结论：
 *  FAIL —— 出现 5xx 响应，或基准档（登录/并发<=10）错误率超阈，属于应用异常
 *  WARN —— 高并发档超安全阈（容量上限信号），中止后续档位但不计失败
 *  PASS —— 其余
 */
function stageVerdict(stage) {
  const serverErrors = Object.entries(stage.statusCounts || {})
    .filter(([code]) => String(code).startsWith('5'))
    .reduce((n, [, count]) => n + count, 0);
  if (serverErrors > 0) return 'FAIL';
  if (stage.concurrency <= 10 && stage.errorRate > ERROR_RATE_LIMIT) return 'FAIL';
  if (stage.errorRate > ERROR_RATE_LIMIT || (stage.p99 ?? 0) > P99_LIMIT_MS) return 'WARN';
  return 'PASS';
}

(async () => {
  const overallStart = Date.now();
  const password = demoPassword();
  const stages = [];
  let aborted = null;

  console.log(`stress-suite 目标 ${baseURL}，登录档 ${LOGIN_SEC}s，读接口每档 ${STAGE_SEC}s，总预算 5 分钟`);

  // --- 场景 1：登录接口低并发 ---
  const loginStage = await runStage('登录握手（CSRF+login）', 3, LOGIN_SEC * 1000, async (worker) => {
    const username = DEMO_USERS[worker % DEMO_USERS.length];
    const { status } = await loginHandshake(username, password);
    return { ok: status === 200, status };
  });
  stages.push(loginStage);
  aborted = exceedsSafety(loginStage);

  // --- 场景 2：已认证读接口阶梯并发 ---
  for (const concurrency of [10, 25, 50]) {
    if (aborted) {
      stages.push({ name: `已认证读 并发=${concurrency}`, concurrency, skipped: true, reason: aborted });
      console.log(`已认证读 并发=${concurrency} 跳过：${aborted}`);
      continue;
    }
    if (Date.now() - overallStart > TOTAL_BUDGET_MS - (STAGE_SEC + 10) * 1000) {
      aborted = '总时长预算不足，跳过后续档位';
      stages.push({ name: `已认证读 并发=${concurrency}`, concurrency, skipped: true, reason: aborted });
      console.log(`已认证读 并发=${concurrency} 跳过：${aborted}`);
      continue;
    }

    // 每个 worker 一条独立会话，开压前分批登录（每批 5 个，失败重试 2 次），避免登录风暴
    const cookieHeaders = new Array(concurrency).fill(null);
    const loginOne = async (i) => {
      for (let attempt = 0; attempt < 3 && !cookieHeaders[i]; attempt += 1) {
        try {
          const { status, jar } = await loginHandshake(DEMO_USERS[i % DEMO_USERS.length], password);
          if (status === 200) {
            cookieHeaders[i] = [...jar.entries()].map(([k, v]) => `${k}=${v}`).join('; ');
          }
        } catch { /* 重试 */ }
      }
    };
    for (let batch = 0; batch < concurrency; batch += 5) {
      await Promise.all(
        Array.from({ length: Math.min(5, concurrency - batch) }, (_, k) => loginOne(batch + k)),
      );
    }
    const sessionFailures = cookieHeaders.filter((c) => !c).length;
    if (sessionFailures > 0) {
      console.log(`  警告：${sessionFailures}/${concurrency} 条会话登录失败，对应 worker 记为错误请求`);
    }

    const stage = await runStage(`已认证读 并发=${concurrency}`, concurrency, STAGE_SEC * 1000, async (worker) => {
      const cookie = cookieHeaders[worker];
      if (!cookie) {
        // 会话不可用：退避后计为错误，避免空转
        await new Promise((resolve) => setTimeout(resolve, 500));
        return { ok: false, status: 0 };
      }
      const endpoint = READ_ENDPOINTS[(Math.random() * READ_ENDPOINTS.length) | 0];
      const res = await fetch(baseURL + endpoint, {
        headers: { Accept: 'application/json', Referer: referer, Cookie: cookie },
        signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS),
      });
      await res.arrayBuffer();
      return { ok: res.status === 200, status: res.status };
    });
    stages.push(stage);
    stage.sessionFailures = sessionFailures;
    const reason = exceedsSafety(stage);
    if (reason) aborted = reason;
  }

  // --- 汇总 ---
  const active = stages.filter((s) => !s.skipped);
  for (const stage of active) stage.verdict = stageVerdict(stage);
  const summary = {
    suite: 'stress',
    baseURL,
    started: new Date(overallStart).toISOString(),
    finished: new Date().toISOString(),
    totalDurationSec: Math.round((Date.now() - overallStart) / 1000),
    aborted,
    stages,
    total: active.length,
    passed: active.filter((s) => s.verdict === 'PASS').length,
    warnings: active.filter((s) => s.verdict === 'WARN').length,
    failed: active.filter((s) => s.verdict === 'FAIL').length,
    skipped: stages.filter((s) => s.skipped).length,
  };
  console.log(`stress-suite 完成：总时长 ${summary.totalDurationSec}s，中止原因：${aborted || '无'}，`
    + `PASS=${summary.passed} WARN=${summary.warnings} FAIL=${summary.failed}`);

  if (outPath) {
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify(summary, null, 2) + '\n');
  }
  // 仅 FAIL 档（5xx 或基准档超阈）使套件失败；WARN 为容量信号
  if (summary.failed > 0) process.exitCode = 1;
})().catch((error) => {
  console.error('stress-suite 执行异常:', error);
  if (outPath) {
    fs.mkdirSync(path.dirname(outPath), { recursive: true });
    fs.writeFileSync(outPath, JSON.stringify({ suite: 'stress', baseURL, fatal: String(error.stack || error) }, null, 2) + '\n');
  }
  process.exitCode = 1;
});
