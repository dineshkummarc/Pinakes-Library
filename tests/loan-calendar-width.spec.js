// @ts-check
// Loan request popup on the book page: the date pickers must fill the row of
// their field at every width (they were flatpickr's fixed 308px box in a 720px
// popup, and full-width only under 420px), and a reader must be able to move
// months ahead and pick a start on the last day of a month.
//
// Run: /tmp/run-e2e.sh tests/loan-calendar-width.spec.js --config=tests/playwright.config.js --workers=1
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

/** A book with a copy and no active loan or reservation: every day is free. */
function freeBookId() {
  const desiderata = db("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'libri' AND column_name = 'is_desiderata'") === '1';
  return db(`SELECT l.id FROM libri l
    WHERE l.deleted_at IS NULL ${desiderata ? 'AND l.is_desiderata = 0' : ''} AND l.copie_totali > 0
      AND NOT EXISTS (SELECT 1 FROM prestiti p WHERE p.libro_id = l.id AND p.attivo = 1)
      AND NOT EXISTS (SELECT 1 FROM prenotazioni r WHERE r.libro_id = l.id AND r.stato = 'attiva')
    ORDER BY l.id LIMIT 1`);
}

async function openLoanPopup(page, bookId) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL(/admin/, { timeout: 30000 });
  await page.goto(`${BASE}/libro/${bookId}`);
  await page.locator('#btn-request-loan').click();
  await page.locator('.loan-request-popup .flatpickr-calendar').first().waitFor({ state: 'attached' });
}

const shownMonth = (page) => page.evaluate(() => {
  const cal = document.querySelector('.loan-request-popup .flatpickr-calendar.open');
  const select = cal?.querySelector('.flatpickr-monthDropdown-months');
  return select ? `${select.value}/${cal.querySelector('.cur-year')?.value}` : null;
});

for (const [label, width] of [['desktop', 1280], ['phone', 390]]) {
  test(`${label}: the start calendar fills the row of its field`, async ({ page }) => {
    const bookId = freeBookId();
    expect(bookId, 'a free book is needed').not.toBe('');
    await page.setViewportSize({ width, height: 900 });
    await openLoanPopup(page, bookId);
    await page.locator('#swal-date-start-display').click();

    const sizes = await page.evaluate(() => {
      const field = document.getElementById('swal-date-start-display');
      const cal = document.querySelector('.loan-request-popup .flatpickr-calendar.open');
      const day = cal?.querySelector('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)');
      return {
        field: field?.getBoundingClientRect().width ?? 0,
        calendar: cal?.getBoundingClientRect().width ?? 0,
        day: day?.getBoundingClientRect().width ?? 0,
      };
    });
    expect(sizes.calendar).toBeGreaterThan(sizes.field - 2);
    // Seven columns share the row: no fixed 39px cells left in the middle.
    expect(sizes.day).toBeGreaterThan(sizes.calendar / 7 - 3);
  });
}

test('months ahead: four months forward, start on the last day of a month, end follows', async ({ page }) => {
  const bookId = freeBookId();
  await page.setViewportSize({ width: 1280, height: 900 });
  await openLoanPopup(page, bookId);
  const calendars = page.locator('.loan-request-popup .flatpickr-calendar');

  await page.locator('#swal-date-start-display').click();
  const first = await shownMonth(page);
  for (let i = 0; i < 4; i++) {
    await calendars.nth(0).locator('.flatpickr-next-month').click();
  }
  expect(await shownMonth(page)).not.toBe(first);

  const days = calendars.nth(0).locator('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay):not(.flatpickr-disabled)');
  const count = await days.count();
  expect(count, 'days to pick four months ahead').toBeGreaterThan(0);
  await days.nth(count - 1).click();

  const start = await page.inputValue('#swal-date-start');
  const lastOfMonth = new Date(Date.UTC(Number(start.slice(0, 4)), Number(start.slice(5, 7)), 0)).getUTCDate();
  expect(Number(start.slice(8, 10)), `${start} is the last day of its month`).toBe(lastOfMonth);
  const end = await page.inputValue('#swal-date-end');
  expect(end > start, `return date ${end} after ${start}`).toBe(true);

  // The return calendar opens on the next month and moves on from there.
  await page.locator('#swal-date-end-display').click();
  const endMonth = await shownMonth(page);
  await calendars.nth(1).locator('.flatpickr-next-month').click();
  expect(await shownMonth(page)).not.toBe(endMonth);
});
