// @ts-check
// Library-wide ICS feed privacy.
//
//   GET /calendar/events.ics                  anonymous, guessable URL
//   GET /calendar/events.ics?token=<hmac>     operator link copied from the dashboard
//   GET /storage/calendar/library-calendar.ics legacy alias of the anonymous URL
//
// Contract under test: the anonymous feed still lists the loan events (existing
// subscriptions keep working) but never writes a borrower's name or e-mail; the
// tokenized operator link keeps them and is never publicly cacheable; a wrong
// token behaves like the anonymous feed; the dashboard hands out the tokenized
// link.
//
// Run: /tmp/run-e2e.sh tests/library-calendar-privacy.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const SURNAME = `Riservato${RUN}`;
/** @type {Record<string, any>} */
const ctx = { books: [] };

/** RFC 5545 folding splits long lines: unfold before searching. */
const unfold = (text) => text.replace(/\r\n[ \t]/g, '');

test.beforeAll(async () => {
    ctx.email = `libcal_${RUN}@pinakes.test`;
    ctx.user = F.createUser(ctx.email, 'LibCal1234!', 'standard', 'LIBCAL');
    F.dbExec(`UPDATE utenti SET cognome = ${F.sqlStr(SURNAME)} WHERE id = ${ctx.user}`);
    ctx.books.push(F.dbInsert(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (${F.sqlStr(`Calendario biblioteca ${RUN}`)}, 1, 1, NOW(), NOW())`));
    ctx.loan = F.dbInsert(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine)
                           VALUES (${ctx.books[0]}, ${ctx.user}, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1, 'diretto')`);
});

test.afterAll(async () => {
    F.deleteBooks(ctx.books);
    F.deleteUsers([ctx.user]);
});

test('anonymous feed lists the loan but no name or e-mail', async ({ request }) => {
    for (const path of ['/calendar/events.ics', '/storage/calendar/library-calendar.ics']) {
        const res = await request.get(`${F.BASE}${path}`);
        expect(res.status(), path).toBe(200);
        expect(res.headers()['content-type']).toContain('text/calendar');
        const body = unfold(await res.text());
        expect(body, path).toContain(`UID:loan-${ctx.loan}@pinakes`);
        expect(body, path).not.toContain(ctx.email);
        expect(body, path).not.toContain(SURNAME);
        expect(body, path).not.toMatch(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/);
    }
});

test('a wrong token gets the anonymous variant', async ({ request }) => {
    const res = await request.get(`${F.BASE}/calendar/events.ics?token=${'0'.repeat(64)}`);
    expect(res.status()).toBe(200);
    const body = unfold(await res.text());
    expect(body).toContain(`UID:loan-${ctx.loan}@pinakes`);
    expect(body).not.toContain(SURNAME);
    expect(res.headers()['cache-control']).toContain('public');
});

test('the dashboard hands out the tokenized link, which keeps borrower details privately', async ({ page, request }) => {
    await F.adminLogin(page);
    await page.goto(`${F.BASE}/admin/dashboard`);
    const href = await page.locator('a[href*="/calendar/events.ics"]').first().getAttribute('href');
    expect(href).toMatch(/\/calendar\/events\.ics\?op=\d+&token=[a-f0-9]{64}$/);

    const url = /^https?:/.test(String(href)) ? String(href) : `${F.BASE}${href}`;
    const res = await request.get(url);
    expect(res.status()).toBe(200);
    const body = unfold(await res.text());
    expect(body).toContain(`UID:loan-${ctx.loan}@pinakes`);
    expect(body).toContain(SURNAME);
    expect(body).toContain(ctx.email);
    expect(res.headers()['cache-control']).toContain('private');
    expect(res.headers()['cache-control']).not.toContain('public');
    ctx.operatorUrl = url;
});

test('the operator link is bound to that operator: another id or a demoted account gets no personal data', async ({ request }) => {
    expect(ctx.operatorUrl).toBeTruthy();
    const u = new URL(ctx.operatorUrl);
    const opId = Number(u.searchParams.get('op'));
    const token = String(u.searchParams.get('token'));

    const wrongOp = await request.get(`${F.BASE}/calendar/events.ics?op=${opId + 1}&token=${token}`);
    expect(unfold(await wrongOp.text())).not.toContain(SURNAME);

    const role = F.dbScalar(`SELECT tipo_utente FROM utenti WHERE id=${opId}`);
    F.dbExec(`UPDATE utenti SET tipo_utente='standard' WHERE id=${opId}`);
    try {
        const demoted = await request.get(ctx.operatorUrl);
        expect(demoted.status()).toBe(200);
        const body = unfold(await demoted.text());
        expect(body).toContain(`UID:loan-${ctx.loan}@pinakes`);
        expect(body).not.toContain(SURNAME);
        expect(body).not.toContain(ctx.email);
    } finally {
        F.dbExec(`UPDATE utenti SET tipo_utente=${F.sqlStr(role)} WHERE id=${opId}`);
    }
    const restored = await request.get(ctx.operatorUrl);
    expect(unfold(await restored.text())).toContain(SURNAME);
});

test('a patron opening the dashboard only ever gets the bare link', async ({ browser }) => {
    const email = `libcal_patron_${RUN}@pinakes.test`;
    const pass = 'LibCalPatron1!';
    const patron = F.createUser(email, pass, 'standard', 'LIBCALP');
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
        await page.goto(`${F.BASE}/accedi`);
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', pass);
        await page.locator('button[type="submit"]').click();
        await page.waitForURL((u) => !u.toString().includes('/accedi'), { timeout: 15000 });
        const res = await page.goto(`${F.BASE}/admin/dashboard`);
        const html = await page.content();
        expect(html, `dashboard status ${res?.status()}`).not.toMatch(/events\.ics\?[^"']*token=/);
    } finally {
        await context.close();
        F.deleteUsers([patron]);
    }
});
