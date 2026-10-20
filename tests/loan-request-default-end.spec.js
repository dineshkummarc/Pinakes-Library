// @ts-check
// Regression: a loan request sent without touching the calendars must ask for the
// default loan duration. The return-date picker inherited the start picker's
// defaultDate, so it showed the start date and the request went out with
// end_date = start_date: a one-day loan. The expected duration comes from the
// settings in the database, with the same cap the reservation endpoint applies.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';

const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME)',
);

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) {
    args.push('-h', DB_HOST);
    if (DB_PORT) args.push('-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.push('-S', DB_SOCKET);
  }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

function setting(key, fallback) {
  const value = parseInt(
    dbQuery(`SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='${key}' LIMIT 1`) || '',
    10,
  );
  return Number.isFinite(value) && value >= 1 ? value : fallback;
}

function addDays(iso, days) {
  const [y, m, d] = iso.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

test.describe('Book page: loan request return date', () => {
  let bookId = 0;
  let expectedDays = 0;

  test.beforeAll(() => {
    bookId = parseInt(
      dbQuery(
        "SELECT c.libro_id FROM copie c JOIN libri l ON l.id=c.libro_id " +
        "WHERE c.stato='disponibile' AND l.deleted_at IS NULL LIMIT 1",
      ) || '0',
      10,
    );
    expectedDays = Math.min(setting('loan_duration_days', 30), setting('max_loan_duration_days', 90));
  });

  test('without touching the calendars, the return date is start + the default loan duration', async ({ page }) => {
    test.skip(!bookId, 'No available book to open the loan request on');

    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/(\/$|admin|profilo|account)/, { timeout: 15000 }).catch(() => {});

    await page.goto(`${BASE}/libro/${bookId}`);
    const btn = page.locator('#btn-request-loan');
    test.skip((await btn.count()) === 0, 'This book has no request-loan button');

    await btn.click();
    await page.waitForSelector('.swal2-popup', { timeout: 8000 });
    await page.waitForFunction(
      () => {
        const start = document.querySelector('#swal-date-start');
        const end = document.querySelector('#swal-date-end');
        return start && end && /** @type {any} */ (start)._flatpickr && /** @type {any} */ (end)._flatpickr;
      },
      { timeout: 8000 },
    );

    const start = await page.locator('#swal-date-start').inputValue();
    const end = await page.locator('#swal-date-end').inputValue();
    expect(start).toMatch(/^\d{4}-\d{2}-\d{2}$/);
    expect(end, 'the return date must not repeat the start date').not.toBe(start);
    expect(end).toBe(addDays(start, expectedDays));
    // What the visitor reads is the same date, not an empty field.
    await expect(page.locator('#swal-date-end-display')).not.toHaveValue('');

  });
});
