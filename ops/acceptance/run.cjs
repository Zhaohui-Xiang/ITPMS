'use strict';

/**
 * IPMS 验收测试总入口：依次运行 接口测试 -> 功能测试 -> 压力测试，汇总生成报告。
 *
 * 用法：
 *   node ops/acceptance/run.cjs                  # 全部三段
 *   node ops/acceptance/run.cjs --only=api,stress
 * 环境变量：
 *   IPMS_ACC_URL  目标地址，默认 http://127.0.0.1
 *
 * 输出：ops/reports/<runId>/{report.json, report.md, api.json, functional.json, stress.json, screenshots/}
 * 任一环节存在 FAIL 时进程退出码为 1。
 */

const { spawnSync, execSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const repoRoot = path.join(__dirname, '..', '..');
const baseURL = (process.env.IPMS_ACC_URL || 'http://127.0.0.1').replace(/\/$/, '');

const onlyArg = process.argv.find((a) => a.startsWith('--only='));
const ORDER = ['api', 'functional', 'stress'];
const selected = onlyArg ? onlyArg.slice('--only='.length).split(',').filter((s) => ORDER.includes(s)) : ORDER;
if (selected.length === 0) {
  console.error('无效的 --only 参数，可选：' + ORDER.join(','));
  process.exit(2);
}

const runId = new Date().toISOString().replace(/[:.]/g, '-').replace('T', '_').slice(0, 19);
const outDir = path.join(repoRoot, 'ops', 'reports', runId);
const shotsDir = path.join(outDir, 'screenshots');
fs.mkdirSync(shotsDir, { recursive: true });

function gitCommit() {
  try {
    return execSync('git rev-parse --short HEAD', { cwd: repoRoot, encoding: 'utf8' }).trim();
  } catch {
    return 'unknown';
  }
}

function runSuite(name) {
  const script = path.join(__dirname, `${name}-suite.cjs`);
  const jsonPath = path.join(outDir, `${name}.json`);
  console.log(`\n===== 运行 ${name} 套件 =====`);
  // 功能测试（真实浏览器）必须走 SANCTUM_STATEFUL_DOMAINS 已配置的来源；
  // 本机 127.0.0.1:80 不在列表中，浏览器会话认证不可用，故功能测试默认走公网地址。
  const funcURL = process.env.IPMS_FUNC_URL
    || (baseURL === 'http://127.0.0.1' ? 'http://116.62.44.192' : baseURL);
  const result = spawnSync(process.execPath, [script, '--out', jsonPath], {
    stdio: 'inherit',
    env: {
      ...process.env,
      IPMS_ACC_URL: baseURL,
      IPMS_FUNC_URL: funcURL,
      IPMS_ACC_SHOTS: shotsDir,
      PLAYWRIGHT_BROWSERS_PATH: process.env.PLAYWRIGHT_BROWSERS_PATH
        || '/home/itpms/.cache/ms-playwright',
    },
    timeout: 10 * 60 * 1000,
  });
  let data = null;
  try {
    data = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
  } catch {
    data = { suite: name, fatal: `套件未产出 ${name}.json`, total: 0, passed: 0, failed: 1, skipped: 0, checks: [] };
  }
  data.exitCode = result.status;
  return data;
}

function mdEscape(text) {
  return String(text ?? '').replace(/\|/g, '\\|').replace(/\n/g, '<br>');
}

function buildMarkdown(report) {
  const lines = [];
  lines.push('# IPMS 验收测试报告', '');
  lines.push('## 概要', '');
  lines.push('| 套件 | 总用例 | 通过 | 失败 | 跳过 | 通过率 |');
  lines.push('| --- | --- | --- | --- | --- | --- |');
  for (const s of report.suites) {
    const rate = s.total > 0 ? ((s.passed / s.total) * 100).toFixed(1) + '%' : '-';
    lines.push(`| ${s.suite} | ${s.total} | ${s.passed} | ${s.failed} | ${s.skipped} | ${rate} |`);
  }
  const rate = report.total > 0 ? ((report.passed / report.total) * 100).toFixed(1) + '%' : '-';
  lines.push(`| **合计** | **${report.total}** | **${report.passed}** | **${report.failed}** | **${report.skipped}** | **${rate}** |`);
  lines.push('');
  lines.push(`- 总时长：${report.durationSec} 秒`);
  lines.push(`- 总体结论：${report.failed > 0 ? '**存在失败项**' : '全部通过'}`, '');

  lines.push('## 环境信息', '');
  lines.push('| 项 | 值 |');
  lines.push('| --- | --- |');
  lines.push(`| 目标 URL（接口/压测） | ${report.environment.baseURL} |`);
  lines.push(`| 目标 URL（功能/浏览器） | ${report.environment.functionalBaseURL} |`);
  lines.push(`| git commit | ${report.environment.gitCommit} |`);
  lines.push(`| 分支 | ${report.environment.gitBranch} |`);
  lines.push(`| 日期 | ${report.environment.started} |`);
  lines.push(`| Node | ${report.environment.node} |`);
  lines.push('');

  const api = report.suites.find((s) => s.suite === 'api');
  if (api && !api.missing) {
    lines.push('## 接口测试明细', '');
    if (api.fatal) lines.push(`> 套件异常终止：${mdEscape(api.fatal)}`, '');
    lines.push('| 用例 | 结果 | 耗时(ms) | 错误摘要 |');
    lines.push('| --- | --- | --- | --- |');
    for (const c of api.checks) {
      lines.push(`| ${mdEscape(c.name)} | ${c.status} | ${c.durationMs} | ${mdEscape((c.error || '').slice(0, 200))} |`);
    }
    lines.push('');
  }

  const functional = report.suites.find((s) => s.suite === 'functional');
  if (functional && !functional.missing) {
    lines.push('## 功能测试明细', '');
    if (functional.fatal) lines.push(`> 套件异常终止：${mdEscape(functional.fatal)}`, '');
    lines.push('| 检查点 | 结果 | 耗时(ms) | 错误/证据 |');
    lines.push('| --- | --- | --- | --- |');
    for (const c of functional.checks) {
      lines.push(`| ${mdEscape(c.name)} | ${c.status} | ${c.durationMs} | ${mdEscape((c.error || c.evidence || '').slice(0, 200))} |`);
    }
    lines.push('');
    if (functional.cleanupNote) lines.push(`> 数据清理说明：${functional.cleanupNote}`, '');
    lines.push(`> 截图目录：\`${path.relative(repoRoot, shotsDir)}/\``, '');
  }

  const stress = report.suites.find((s) => s.suite === 'stress');
  if (stress && !stress.missing) {
    lines.push('## 压力测试', '');
    if (stress.fatal) {
      lines.push(`> 套件异常终止：${mdEscape(stress.fatal)}`, '');
    } else {
      lines.push('| 场景 | 并发 | 请求数 | RPS | p50(ms) | p95(ms) | p99(ms) | 错误率 | 超时 | 结论 |');
      lines.push('| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |');
      for (const s of stress.stages) {
        if (s.skipped) {
          lines.push(`| ${s.name} | ${s.concurrency} | - | - | - | - | - | - | - | 跳过：${mdEscape(s.reason)} |`);
        } else {
          lines.push(`| ${s.name} | ${s.concurrency} | ${s.requests} | ${s.rps} | ${s.p50} | ${s.p95} | ${s.p99} | ${(s.errorRate * 100).toFixed(2)}% | ${s.timeouts} | ${s.verdict || '-'} |`);
        }
      }
      lines.push('');
      lines.push(`- 安全阀中止：${stress.aborted || '无'}；压测总时长 ${stress.totalDurationSec}s；容量告警档 ${stress.warnings ?? 0} 个（WARN 不计失败，仅 FAIL 档影响退出码）`);
      lines.push('- 压测后健康检查：' + (report.healthCheck ? `${report.healthCheck.status}（${report.healthCheck.ok ? '正常' : '异常'}）` : '未执行'), '');
    }
  }

  const failures = report.suites.flatMap((s) =>
    (s.checks || []).filter((c) => c.status === 'FAIL').map((c) => ({ suite: s.suite, ...c })));
  if (failures.length > 0) {
    lines.push('## 失败项错误摘要', '');
    for (const f of failures) {
      lines.push(`- **[${f.suite}] ${f.name}**：${mdEscape((f.error || '').slice(0, 400))}`);
    }
    lines.push('');
  }

  const skips = report.suites.flatMap((s) =>
    (s.checks || []).filter((c) => c.status === 'SKIP').map((c) => ({ suite: s.suite, ...c })));
  if (skips.length > 0) {
    lines.push('## 跳过项说明', '');
    for (const s of skips) {
      lines.push(`- [${s.suite}] ${s.name}：${mdEscape(s.error || '')}`);
    }
    lines.push('');
  }
  return lines.join('\n');
}

(async () => {
  const started = Date.now();
  const gitBranch = (() => {
    try { return execSync('git branch --show-current', { cwd: repoRoot, encoding: 'utf8' }).trim(); }
    catch { return 'unknown'; }
  })();

  console.log(`验收测试 runId=${runId}，目标 ${baseURL}，套件：${selected.join(' -> ')}`);
  const suites = selected.map(runSuite);

  // 压测后健康检查
  let healthCheck = null;
  if (selected.includes('stress')) {
    try {
      const res = await fetch(baseURL + '/', { signal: AbortSignal.timeout(10000) });
      await res.arrayBuffer();
      healthCheck = { url: baseURL + '/', status: res.status, ok: res.status === 200 };
    } catch (error) {
      healthCheck = { url: baseURL + '/', status: 0, ok: false, error: String(error.message || error) };
    }
    console.log(`压测后健康检查 GET / -> ${healthCheck.status}`);
  }

  const report = {
    runId,
    environment: {
      baseURL,
      functionalBaseURL: process.env.IPMS_FUNC_URL
        || (baseURL === 'http://127.0.0.1' ? 'http://116.62.44.192' : baseURL),
      gitCommit: gitCommit(),
      gitBranch,
      started: new Date(started).toISOString(),
      node: process.version,
    },
    total: suites.reduce((n, s) => n + (s.total || 0), 0),
    passed: suites.reduce((n, s) => n + (s.passed || 0), 0),
    failed: suites.reduce((n, s) => n + (s.failed || 0), 0),
    skipped: suites.reduce((n, s) => n + (s.skipped || 0), 0),
    durationSec: Math.round((Date.now() - started) / 1000),
    healthCheck,
    suites,
  };

  fs.writeFileSync(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2) + '\n');
  fs.writeFileSync(path.join(outDir, 'report.md'), buildMarkdown(report));

  console.log('\n===== 验收测试汇总 =====');
  console.log(`总用例 ${report.total}，通过 ${report.passed}，失败 ${report.failed}，跳过 ${report.skipped}，总时长 ${report.durationSec}s`);
  console.log(`报告目录：${outDir}`);
  if (report.failed > 0) process.exitCode = 1;
})().catch((error) => {
  console.error('run.cjs 执行异常:', error);
  process.exitCode = 1;
});
