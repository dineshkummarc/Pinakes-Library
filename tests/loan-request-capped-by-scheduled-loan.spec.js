// @ts-check
// Regression: when a scheduled loan (or a reservation on every copy) begins
// before the default return date, the public request calendar used to propose
// start + the default duration anyway. flatpickr silently dropped that date
// because it fell on a disabled day, the request went out without end_date,
// and the server rejected it with a bare "no copies available" although a
// shorter loan was possible. The calendar must now cap the return date at the
// day before the conflict and say how long the loan can be; the server's
// rejection must carry the same figures for clients that skip the calendar.
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

// The seeded loan starts this many days after today: inside the default
// duration, so it must shorten the proposed loan to CONFLICT_OFFSET - 1 days.
const CONFLICT_OFFSET = 5;

test.skip(
  !ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_USER, E2E_DB_NAME)',
);

/**
 * Runs one statement against the test database (TCP when E2E_DB_HOST is set, else the
 * socket) and returns its tab-separated output, trimmed.
 * @param {string} sql
 * @returns {string}
 */
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

/**
 * A loans setting as a positive integer, or the fallback the server uses when it is
 * missing or below 1.
 * @param {string} key
 * @param {number} fallback
 * @returns {number}
 */
function setting(key, fallback) {
  const value = parseInt(
    dbQuery(`SELECT setting_value FROM system_settings WHERE category='loans' AND setting_key='${key}' LIMIT 1`) || '',
    10,
  );
  return Number.isFinite(value) && value >= 1 ? value : fallback;
}

/**
 * yyyy-mm-dd plus a number of days, computed in UTC so no time zone shifts the day.
 * @param {string} iso
 * @param {number} days
 * @returns {string}
 */
function addDays(iso, days) {
  const [y, m, d] = iso.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

test.describe('Book page: loan request capped by a scheduled loan', () => {
  let bookId = 0;
  let copyId = 0;
  let seededLoanId = 0;
  let borrowerId = 0;
  let bookSnapshot = '';
  let today = '';
  const RUN = Date.now().toString(36).slice(-6);
  const BORROWER_EMAIL = `lw-cap-${RUN}@example.invalid`;
  // password_hash() output contains no quote characters.
  const BORROWER_HASH = execFileSync('php', ['-r', "echo password_hash('LoanWindow1!', PASSWORD_DEFAULT);"], { encoding: 'utf-8' }).trim();
  let defaultDays = 0;

  test.beforeAll(() => {
    defaultDays = Math.min(setting('loan_duration_days', 30), setting('max_loan_duration_days', 90));
    test.skip(defaultDays <= CONFLICT_OFFSET, `Default loan duration must exceed ${CONFLICT_OFFSET} days for this scenario`);

    // A title with exactly one lendable copy and nothing in circulation on it,
    // so the seeded scheduled loan is the only thing blocking the calendar.
    bookId = parseInt(
      dbQuery(
        "SELECT c.libro_id FROM copie c JOIN libri l ON l.id=c.libro_id " +
        "WHERE l.deleted_at IS NULL " +
        "AND NOT EXISTS (SELECT 1 FROM prestiti p WHERE p.libro_id=c.libro_id AND p.attivo=1) " +
        "AND NOT EXISTS (SELECT 1 FROM prenotazioni r WHERE r.libro_id=c.libro_id AND r.stato='attiva') " +
        "GROUP BY c.libro_id HAVING COUNT(*)=1 AND SUM(c.stato='disponibile')=1 LIMIT 1",
      ) || '0',
      10,
    );
    if (!bookId) return;
    copyId = parseInt(dbQuery(`SELECT id FROM copie WHERE libro_id=${bookId} LIMIT 1`) || '0', 10);
    // The request may be auto-approved (loans.auto_approve_requests), which
    // recomputes the title's derived columns: restore them afterwards.
    bookSnapshot = dbQuery(`SELECT CONCAT(IFNULL(copie_disponibili,'NULL'),'\t',IFNULL(stato,'')) FROM libri WHERE id=${bookId}`);
    // The application's clock (DateHelper::today(): app.timezone, default
    // Europe/Rome), not the database session's: near midnight they differ.
    const tz = dbQuery("SELECT setting_value FROM system_settings WHERE category='app' AND setting_key='timezone' LIMIT 1") || 'Europe/Rome';
    today = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());

    // Another patron's scheduled loan: the admin's own commitments never block
    // the admin (and a second loan on the same book is refused as a duplicate),
    // so the borrower is a patron this spec creates. The fresh CI database
    // holds only the admin, and other specs' users come and go.
    dbQuery(`DELETE FROM utenti WHERE email='${BORROWER_EMAIL}'`);
    dbQuery(
      `INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, email_verificata, tipo_utente, created_at) ` +
      `VALUES ('LWC${RUN}', 'Loan', 'Window', '${BORROWER_EMAIL}', '${BORROWER_HASH}', 'attivo', 1, 'standard', NOW())`,
    );
    borrowerId = parseInt(dbQuery(`SELECT id FROM utenti WHERE email='${BORROWER_EMAIL}' LIMIT 1`) || '0', 10);
    if (!borrowerId) { bookId = 0; return; }

    const loanStart = addDays(today, CONFLICT_OFFSET);
    const loanEnd = addDays(today, CONFLICT_OFFSET + 14);
    dbQuery(
      `INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, origine, attivo) ` +
      `VALUES (${bookId}, ${copyId}, ${borrowerId}, '${loanStart}', '${loanEnd}', 'prenotato', 'prenotazione', 1)`,
    );
    seededLoanId = parseInt(dbQuery(`SELECT id FROM prestiti WHERE libro_id=${bookId} AND utente_id=${borrowerId} ORDER BY id DESC LIMIT 1`) || '0', 10);
  });

  test.afterAll(() => {
    if (seededLoanId) {
      dbQuery(`DELETE FROM prestiti WHERE id=${seededLoanId}`);
      dbQuery(`DELETE FROM prestiti WHERE libro_id=${bookId} AND utente_id=(SELECT id FROM utenti WHERE email='${ADMIN_EMAIL.replace(/'/g, "''")}' LIMIT 1) AND data_prestito>='${today}'`);
      dbQuery(`UPDATE copie SET stato='disponibile' WHERE id=${copyId}`);
      const [avail, stato] = bookSnapshot.split('\t');
      if (avail !== undefined && stato !== undefined) {
        const availSql = avail === 'NULL' ? 'NULL' : String(parseInt(avail, 10) || 0);
        dbQuery(`UPDATE libri SET copie_disponibili=${availSql}, stato='${stato.replace(/'/g, "''")}' WHERE id=${bookId}`);
      }
    }
    if (borrowerId) {
      dbQuery(`DELETE FROM prestiti WHERE utente_id=${borrowerId}`);
      dbQuery(`DELETE FROM utenti WHERE id=${borrowerId}`);
    }
  });

  // Runs first: the calendar test below ends by creating the admin's own loan.
  test('the server rejection names the maximum loan length and the blocked day', async ({ page }) => {
    test.skip(!bookId || !seededLoanId, 'No single-copy book free of circulation to seed the scenario on');

    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', ADMIN_EMAIL);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/(\/$|admin|profilo|account)/, { timeout: 15000 }).catch(() => {});
    await page.goto(`${BASE}/libro/${bookId}`);

    // A client that skips the calendar and asks for the full default window.
    const result = await page.evaluate(async ({ id, start, end }) => {
      const meta = document.querySelector('meta[name="csrf-token"]');
      const csrf = meta ? meta.getAttribute('content') : '';
      const base = /^\/en\//.test(location.pathname) ? '/en/api/book' : '/api/libro';
      const res = await fetch(`${base}/${id}/reservation`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ start_date: start, end_date: end, csrf_token: csrf }),
      });
      return { status: res.status, body: await res.json() };
    }, { id: bookId, start: today, end: addDays(today, CONFLICT_OFFSET + 1) });

    expect(result.status).toBe(400);
    expect(result.body.success).toBe(false);
    expect(result.body.first_conflict_date).toBe(addDays(today, CONFLICT_OFFSET));
    expect(result.body.max_end_date).toBe(addDays(today, CONFLICT_OFFSET - 1));
    expect(result.body.max_duration_days).toBe(CONFLICT_OFFSET - 1);
    expect(result.body.message).toContain(String(CONFLICT_OFFSET - 1));
  });

  test('the return date stops the day before the scheduled loan and the limit is explained', async ({ page }) => {
    test.skip(!bookId || !seededLoanId, 'No single-copy book free of circulation to seed the scenario on');

    const expectedEnd = addDays(today, CONFLICT_OFFSET - 1);
    const expectedDays = CONFLICT_OFFSET - 1;

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

    expect(await page.locator('#swal-date-start').inputValue()).toBe(today);
    // Not start + default duration: the day before the other patron's loan.
    expect(await page.locator('#swal-date-end').inputValue()).toBe(expectedEnd);
    await expect(page.locator('#swal-date-end-display')).not.toHaveValue('');

    const note = page.locator('#swal-loan-limit');
    await expect(note).toBeVisible();
    await expect(note).toContainText(String(expectedDays));

    // The capped return date is accepted and the request goes through.
    const createResponse = page.waitForResponse((r) => /\/reservation$/.test(r.url()) && r.request().method() === 'POST');
    await page.locator('.swal2-confirm').click();
    const created = await (await createResponse).json();
    expect(created.success, JSON.stringify(created)).toBe(true);
    await page.waitForSelector('.swal2-popup.swal2-icon-success', { timeout: 8000 });
  });
});
