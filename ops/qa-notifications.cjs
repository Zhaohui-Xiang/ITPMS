const { chromium } = require('/srv/itpms-dev/tools/browser/node_modules/playwright');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const baseURL = process.env.IPMS_QA_URL || 'http://127.0.0.1:8082';
const password = fs.readFileSync('/var/www/ipms/shared/.env', 'utf8')
  .split('\n').find(line => line.startsWith('IPMS_DEMO_PASSWORD=')).split('=').slice(1).join('=');
(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext({ baseURL, extraHTTPHeaders: { Referer: baseURL + '/', Accept: 'application/json' } });
    assert.equal((await context.request.get('/api/notifications')).status(), 401);
    const page = await context.newPage();
    await page.goto('/login');
    await page.getByPlaceholder('请输入账号').fill('demo.it_pm');
    await page.getByPlaceholder('请输入密码').fill(password);
    await page.locator('.login-btn').click();
    await page.waitForURL('**/dashboard');
    const inbox = await context.request.get('/api/notifications');
    assert.equal(inbox.status(), 200);
    assert.ok(Array.isArray((await inbox.json()).data.items));
    const count = await context.request.get('/api/notifications/unread-count');
    assert.equal(count.status(), 200);
    assert.ok(Number.isInteger((await count.json()).data.unread_count));
    const csrf = (await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN');
    const headers = { 'X-XSRF-TOKEN': decodeURIComponent(csrf.value) };
    assert.equal((await context.request.post('/api/notifications/999999/read', { headers })).status(), 404);
    const readAll = await context.request.post('/api/notifications/read-all', { headers });
    assert.equal(readAll.status(), 200);
    assert.equal((await readAll.json()).data.unread_count, 0);
    console.log(JSON.stringify({ status: 'PASS', baseURL, checks: ['anonymous denied', 'inbox', 'unread count', 'missing item', 'read all'] }));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
