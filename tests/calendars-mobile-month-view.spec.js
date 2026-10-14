// @ts-check
/**
 * Every month calendar of the application on a phone (375px, Android UA).
 *
 * Calendars covered:
 *  1. admin dashboard — FullCalendar "Calendario Prestiti e Prenotazioni";
 *  2. admin book detail — FullCalendar copy availability;
 *  3. public book page — the loan request calendar (flatpickr, in a modal);
 *  4. admin "new loan" — the availability-coloured date pickers (flatpickr).
 *
 * Regressions guarded:
 *  - main.css turns every <table> into `display:block` below 768px; the
 *    FullCalendar month grid is made of tables and collapsed to ~4px, so the
 *    "Mese" view showed an empty strip on phones;
 *  - flatpickr swaps in the native date picker on mobile user agents unless
 *    told otherwise, so the availability-coloured month calendar of the loan
 *    form never appeared on a phone.
 */

const { test, expect, devices } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_*)');

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

const TOKEN = Math.random().toString(16).slice(2, 8);
const PHONE = { ...devices['Pixel 5'], viewport: { width: 375, height: 812 } };

async function horizontalOverflow(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

/** Switch a FullCalendar to its month view and measure the rendered grid. */
async function assertFullCalendarMonth(page, calendarSelector) {
  const calendar = page.locator(calendarSelector);
  await calendar.scrollIntoViewIfNeeded();
  const monthButton = calendar.locator('.fc-dayGridMonth-button');
  await expect(monthButton, 'the month button is reachable on a phone').toBeVisible();
  const btnBox = await monthButton.boundingBox();
  expect(btnBox && btnBox.x >= 0 && btnBox.x + btnBox.width <= 375).toBe(true);
  await monthButton.click();
  await expect(monthButton).toHaveClass(/fc-button-active/);
  await expect(calendar.locator('.fc-dayGridMonth-view')).toBeVisible();

  const grid = await calendar.evaluate((root) => {
    const body = root.querySelector('.fc-daygrid-body');
    const cells = [...root.querySelectorAll('.fc-daygrid-day')];
    const header = [...root.querySelectorAll('.fc-col-header-cell')];
    const rect = (el) => (el ? el.getBoundingClientRect() : { width: 0, height: 0, left: 0, right: 0 });
    const rootRect = rect(root);
    return {
      bodyHeight: rect(body).height,
      bodyWidth: rect(body).width,
      cells: cells.length,
      visibleCells: cells.filter((c) => rect(c).height > 20 && rect(c).width > 20).length,
      headerCells: header.filter((h) => rect(h).width > 20).length,
      withinCalendar: cells.every((c) => rect(c).right <= rootRect.right + 1 && rect(c).left >= rootRect.left - 1),
    };
  });
  expect(grid.cells, 'a month has at least 4 full weeks').toBeGreaterThanOrEqual(28);
  expect(grid.visibleCells, 'every day cell is rendered with a real size').toBe(grid.cells);
  expect(grid.headerCells, 'seven weekday headers').toBe(7);
  expect(grid.bodyHeight, 'the month grid is not collapsed').toBeGreaterThan(200);
  expect(grid.withinCalendar, 'day cells stay inside the calendar').toBe(true);
  expect(await horizontalOverflow(page), 'no horizontal page scroll').toBeLessThanOrEqual(0);
}

/** Measure an open flatpickr calendar: a real month grid, inside the viewport. */
async function assertFlatpickrMonth(page, scope) {
  const cal = page.locator(`${scope} .flatpickr-calendar.open`).first();
  await expect(cal).toBeVisible();
  const m = await cal.evaluate((el) => {
    const r = el.getBoundingClientRect();
    const days = [...el.querySelectorAll('.dayContainer .flatpickr-day')];
    const dc = el.querySelector('.dayContainer');
    return {
      left: r.left, right: r.right,
      days: days.length,
      visibleDays: days.filter((d) => d.getBoundingClientRect().height > 15).length,
      gridHeight: dc ? dc.getBoundingClientRect().height : 0,
      monthLabel: (el.querySelector('.flatpickr-current-month')?.textContent || '').trim(),
    };
  });
  expect(m.days).toBeGreaterThanOrEqual(28);
  expect(m.visibleDays).toBe(m.days);
  expect(m.gridHeight).toBeGreaterThan(150);
  expect(m.monthLabel).not.toBe('');
  expect(m.left).toBeGreaterThanOrEqual(0);
  expect(m.right).toBeLessThanOrEqual(375);
  expect(await horizontalOverflow(page), 'no horizontal page scroll').toBeLessThanOrEqual(0);
}

test.describe.serial('Month calendars at phone width', () => {
  /** @type {import('@playwright/test').Page} */
  let page;
  let bookId = 0;

  test.beforeAll(async ({ browser }) => {
    // A book with one available copy: the admin copy calendar and the public
    // loan request calendar both need one.
    dbQuery(`INSERT INTO libri (titolo, copie_totali, copie_disponibili) VALUES ('ZZ Mobile Calendar ${TOKEN}', 1, 1)`);
    bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='ZZ Mobile Calendar ${TOKEN}' AND deleted_at IS NULL`));
    dbQuery(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, 'ZZCAL-${TOKEN}', 'disponibile')`);

    const ctx = await browser.newContext(PHONE);
    page = await ctx.newPage();
    await page.goto(`${BASE}/login`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
      page.waitForURL(/\/admin\//, { timeout: 30000, waitUntil: 'domcontentloaded' }),
      page.click('button[type="submit"]'),
    ]);
  });

  test.afterAll(async () => {
    if (bookId) {
      dbQuery(`DELETE FROM prestiti WHERE libro_id=${bookId}`);
      dbQuery(`DELETE FROM copie WHERE libro_id=${bookId}`);
      dbQuery(`DELETE FROM libri WHERE id=${bookId}`);
    }
    await page?.context()?.close();
  });

  test('1. admin dashboard calendar: month view renders a full grid', async () => {
    await page.goto(`${BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#dashboard-calendar .fc-view-harness')).toBeVisible();
    await assertFullCalendarMonth(page, '#dashboard-calendar');
  });

  test('2. admin book detail copy calendar: month view renders a full grid', async () => {
    await page.goto(`${BASE}/admin/books/${bookId}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#copy-availability-calendar .fc-view-harness')).toBeVisible();
    await assertFullCalendarMonth(page, '#copy-availability-calendar');
  });

  test('2b. calendar fallback texts follow the app language, not FullCalendar English', async () => {
    // The book has no loans, so the phone's initial list view shows the empty
    // message; the standard bundle has no locales, the app must supply it.
    await page.goto(`${BASE}/admin/books/${bookId}`, { waitUntil: 'domcontentloaded' });
    const harness = page.locator('#copy-availability-calendar');
    await expect(harness.locator('.fc-view-harness')).toBeVisible();
    const listButton = harness.locator('.fc-listWeek-button');
    if (await listButton.count()) await listButton.click();
    const empty = harness.locator('.fc-list-empty-cushion');
    await expect(empty).toBeVisible();
    const text = (await empty.textContent() || '').trim();
    expect(text).not.toBe('');
    const appLocale = await page.evaluate(() => document.documentElement.lang || '');
    if (!appLocale.toLowerCase().startsWith('en')) {
      expect(text, `locale ${appLocale}`).not.toBe('No events to display');
    }
  });

  test('3. public book page: the loan request month calendar opens inside the screen', async () => {
    await page.goto(`${BASE}/autore/libro/${bookId}`, { waitUntil: 'domcontentloaded' });
    const request = page.locator('#btn-request-loan');
    await expect(request).toBeEnabled({ timeout: 15000 });
    await request.click();
    const popup = page.locator('.swal2-popup');
    await expect(popup).toBeVisible();
    // The visible (alt) input of the start date opens the calendar.
    await popup.locator('#swal-date-start + input, #swal-date-start ~ input').first().click();
    await assertFlatpickrMonth(page, '.swal2-popup');
  });

  test('4. admin new loan: the availability month calendar replaces the native picker', async () => {
    await page.goto(`${BASE}/admin/loans/create`, { waitUntil: 'domcontentloaded' });
    // flatpickr would otherwise build a native <input type="date"> on phones.
    await expect(page.locator('input.flatpickr-mobile')).toHaveCount(0);
    await page.locator('#data_prestito + input').click();
    await assertFlatpickrMonth(page, 'body');
    await page.keyboard.press('Escape');
    await page.locator('#data_scadenza + input').click();
    await assertFlatpickrMonth(page, 'body');
  });
});
