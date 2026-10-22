// @ts-check
// Settings > Loans: the pickup reminder notice is set next to the pickup days,
// saved and clamped by the server; Settings > Email templates lists the new
// "Pickup reminder" template so it can be edited like the others.
//
// Run: /tmp/run-e2e.sh tests/pickup-reminder-settings.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
const stored = () => db("SELECT COALESCE(MAX(setting_value), '') FROM system_settings WHERE category = 'loans' AND setting_key = 'pickup_reminder_days'");

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/admin/, { timeout: 30000 });
}

/** Save from the form; `bypassMax` drops the advisory max, as a forged POST would. */
async function saveReminderDays(page, value, bypassMax = false) {
  await page.goto(`${BASE}/admin/settings?tab=loans`);
  const field = page.locator('#pickup_reminder_days');
  if (bypassMax) {
    // requestSubmit() runs HTML5 validation: without this the browser refuses
    // to send 99 and the server-side clamp is never reached.
    await field.evaluate((el) => el.removeAttribute('max'));
  }
  await field.fill(value);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/admin/settings/loans')),
    field.evaluate((el) => el.closest('form')?.requestSubmit()),
  ]);
}

test('pickup reminder days: saved from Settings > Loans, clamped, template listed', async ({ page }) => {
  const prior = stored();
  await login(page);
  try {
    await saveReminderDays(page, '4');
    expect(stored()).toBe('4');
    await page.goto(`${BASE}/admin/settings?tab=loans`);
    await expect(page.locator('#pickup_reminder_days')).toHaveValue('4');

    // 0 turns it off; a hand-crafted out-of-range value is clamped by the server.
    await saveReminderDays(page, '0');
    expect(stored()).toBe('0');
    await saveReminderDays(page, '99', true);
    expect(stored()).toBe('30');

    await page.goto(`${BASE}/admin/settings?tab=templates`);
    await expect(page.locator('form[action*="/admin/settings/templates/loan_pickup_reminder"]')).toHaveCount(1);
  } finally {
    await saveReminderDays(page, prior === '' ? '2' : prior);
  }
});
