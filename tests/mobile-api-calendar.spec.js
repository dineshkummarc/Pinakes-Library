// @ts-check
// Mobile API 1.6.0 — personal loans calendar.
//
//   GET/POST/DELETE /me/calendar[/token]  status, create/rotate, revoke
//   GET /calendar/feed/{token}.ics        the public, token-protected feed
//
// Contract under test: the feed holds ONLY the owner's loans, no name or
// e-mail address of anyone, a stable UID per loan, a SEQUENCE, RFC 5545 line
// folding/escaping and CRLF; ETag → 304; rotation kills the old URL at once;
// revocation is idempotent; catalogue mode and a disabled app-access gate turn
// the feed into a 404.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-calendar.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Cal1234Test!';
// Long, multibyte and full of characters RFC 5545 requires escaping.
const LONG_TITLE = `Calendario «prestiti», titolo; lunghissimo — con accenti àèìòù e ancora testo ${RUN}`;
/** @type {Record<string, any>} */
const ctx = { books: [] };

function insertLoan(bookId, userId, stato, start, due) {
    return F.dbInsert(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine)
                       VALUES (${bookId}, ${userId}, NULL, ${start}, ${due}, '${stato}', 1, 'diretto')`);
}

async function feed(request, url, extraHeaders) {
    const res = await request.fetch(url, { headers: { ...(extraHeaders || {}) } });
    return { status: res.status(), headers: res.headers(), text: await res.text() };
}

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    ctx.catalogueModeBefore = F.dbScalar("SELECT setting_value FROM system_settings WHERE category='system' AND setting_key='catalogue_mode' LIMIT 1") === '1';
    if (ctx.catalogueModeBefore) await F.setCatalogueMode(browser, false);

    ctx.emailA = `cal_a_${RUN}@pinakes.test`;
    ctx.emailB = `cal_b_${RUN}@pinakes.test`;
    ctx.userA = F.createUser(ctx.emailA, PASS, 'standard', 'CALA');
    ctx.userB = F.createUser(ctx.emailB, PASS, 'standard', 'CALB');
    ctx.tokenA = await F.login(request, ctx.emailA, PASS, 'cal-a');
    ctx.tokenB = await F.login(request, ctx.emailB, PASS, 'cal-b');

    for (const title of [LONG_TITLE, `Calendario programmato ${RUN}`, `Calendario altrui ${RUN}`]) {
        ctx.books.push(F.dbInsert(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES (${F.sqlStr(title)}, 1, 1, NOW(), NOW())`));
    }
    ctx.loanA = insertLoan(ctx.books[0], ctx.userA, 'in_corso', 'CURDATE()', 'DATE_ADD(CURDATE(), INTERVAL 14 DAY)');
    ctx.loanA2 = insertLoan(ctx.books[1], ctx.userA, 'prenotato', 'DATE_ADD(CURDATE(), INTERVAL 20 DAY)', 'DATE_ADD(CURDATE(), INTERVAL 34 DAY)');
    ctx.loanB = insertLoan(ctx.books[2], ctx.userB, 'in_corso', 'CURDATE()', 'DATE_ADD(CURDATE(), INTERVAL 7 DAY)');
});

test.afterAll(async ({ browser }) => {
    F.deleteBooks(ctx.books);
    F.deleteUsers([ctx.userA, ctx.userB]);
    F.restoreGate(ctx.gate);
    if (ctx.catalogueModeBefore) await F.setCatalogueMode(browser, true);
});

test('status starts disabled; POST creates the secret URL; GET shows it again', async ({ request }) => {
    const s0 = await F.api(request, 'GET', '/me/calendar', { token: ctx.tokenA });
    expect(s0.status).toBe(200);
    expect(s0.json.data).toEqual({ enabled: false, feed_url: null, webcal_url: null, created_at: null, rotated_at: null });

    const created = await F.api(request, 'POST', '/me/calendar/token', { token: ctx.tokenA });
    expect(created.status).toBe(201);
    const d = created.json.data;
    expect(d.enabled).toBe(true);
    expect(d.feed_url).toMatch(/^https?:\/\/.+\/api\/v1\/calendar\/feed\/[a-f0-9]{64}\.ics$/);
    expect(d.webcal_url).toBe(d.feed_url.replace(/^https?:\/\//, 'webcal://'));
    expect(d.created_at).toBeTruthy();
    expect(d.rotated_at).toBeNull();
    ctx.feedUrl = d.feed_url;
    // The plaintext is never stored: only its hash and an encrypted copy.
    const token = d.feed_url.match(/([a-f0-9]{64})\.ics$/)[1];
    expect(F.dbInt(`SELECT COUNT(*) FROM mobile_calendar_tokens WHERE token_enc LIKE '%${token}%' OR token_hash='${token}'`)).toBe(0);

    const again = await F.api(request, 'GET', '/me/calendar', { token: ctx.tokenA });
    expect(again.json.data.feed_url).toBe(ctx.feedUrl);
    expect((await F.api(request, 'GET', '/me/calendar')).status, 'token required').toBe(401);
});

test('the feed: own loans only, no personal data, RFC 5545 shape, ETag → 304', async ({ request }) => {
    const r = await feed(request, ctx.feedUrl);
    expect(r.status, r.text.slice(0, 200)).toBe(200);
    expect(r.headers['content-type']).toMatch(/^text\/calendar; charset=utf-8/);
    expect(r.headers['cache-control']).toBe('private, max-age=900');
    expect(r.headers['x-robots-tag']).toContain('noindex');
    const ics = r.text;
    expect(ics.startsWith('BEGIN:VCALENDAR\r\n')).toBe(true);
    expect(ics.endsWith('END:VCALENDAR\r\n')).toBe(true);
    expect(ics.replace(/\r\n/g, '')).not.toContain('\n');
    for (const line of ics.split('\r\n')) {
        expect(Buffer.byteLength(line, 'utf8'), `folded line: ${line}`).toBeLessThanOrEqual(75);
    }
    const unfolded = ics.replace(/\r\n /g, '');
    expect(unfolded).toContain(`UID:loan-${ctx.loanA}@pinakes`);
    expect(unfolded).toContain(`UID:loan-${ctx.loanA2}@pinakes`);
    expect(unfolded, "user B's loan must not leak").not.toContain(`loan-${ctx.loanB}@pinakes`);
    expect(unfolded).not.toContain(`Calendario altrui ${RUN}`);
    // No identity of anyone: no address, no name, no Utente/Email lines.
    expect(unfolded).not.toContain('@pinakes.test');
    expect(unfolded).not.toMatch(/Utente:|Email:|E2E CALA|E2E CALB/);
    expect(unfolded).toMatch(/SEQUENCE:\d+/);
    expect(unfolded).toMatch(/DTSTAMP:\d{8}T\d{6}Z/);
    // Escaping of , ; inside SUMMARY (the title).
    expect(unfolded).toContain('Calendario «prestiti»\\, titolo\\; lunghissimo');
    // One reminder before the due date of the running loan, one before the scheduled start.
    expect(unfolded).toContain('TRIGGER;RELATED=END:-P1D');
    expect(unfolded).toContain('TRIGGER:-P1D');
    expect((unfolded.match(/BEGIN:VEVENT/g) || []).length).toBe(2);

    const etag = r.headers.etag;
    expect(etag).toMatch(/^"[a-f0-9]{40}"$/);
    const cached = await feed(request, ctx.feedUrl, { 'If-None-Match': etag });
    expect(cached.status).toBe(304);

    // A renewal bumps SEQUENCE and the representation.
    const seqBefore = Number(unfolded.match(new RegExp(`UID:loan-${ctx.loanA}@pinakes\\r\\nDTSTAMP:\\S+\\r\\nLAST-MODIFIED:\\S+\\r\\nSEQUENCE:(\\d+)`))?.[1]);
    expect(seqBefore).toBeGreaterThan(0);
    await F.sleep(1100);
    F.dbExec(`UPDATE prestiti SET data_scadenza = DATE_ADD(data_scadenza, INTERVAL 7 DAY), renewals = renewals + 1 WHERE id=${ctx.loanA}`);
    const renewed = await feed(request, ctx.feedUrl, { 'If-None-Match': etag });
    expect(renewed.status, 'changed loan → new representation').toBe(200);
    const seqAfter = Number(renewed.text.replace(/\r\n /g, '').match(new RegExp(`UID:loan-${ctx.loanA}@pinakes\\r\\nDTSTAMP:\\S+\\r\\nLAST-MODIFIED:\\S+\\r\\nSEQUENCE:(\\d+)`))?.[1]);
    expect(seqAfter).toBeGreaterThan(seqBefore);
});

test('active reservations are in the feed from requested start to end; cancelled ones and other users are not', async ({ request }) => {
    const ins = (userId, bookId, stato) => F.dbInsert(`INSERT INTO prenotazioni (libro_id, utente_id, data_prenotazione, data_inizio_richiesta, data_fine_richiesta, stato)
        VALUES (${bookId}, ${userId}, NOW(), DATE_ADD(CURDATE(), INTERVAL 40 DAY), DATE_ADD(CURDATE(), INTERVAL 54 DAY), '${stato}')`);
    const mine = ins(ctx.userA, ctx.books[2], 'attiva');
    const cancelled = ins(ctx.userA, ctx.books[2], 'annullata');
    const other = ins(ctx.userB, ctx.books[0], 'attiva');
    try {
        const r = await feed(request, ctx.feedUrl);
        expect(r.status).toBe(200);
        const body = r.text.replace(/\r\n[ \t]/g, '');
        const start = F.dbScalar("SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 40 DAY), '%Y%m%d')");
        // All-day DTEND is exclusive: the day after the requested end.
        const endExclusive = F.dbScalar("SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 55 DAY), '%Y%m%d')");
        const event = body.split('BEGIN:VEVENT').find((e) => e.includes(`UID:reservation-${mine}@pinakes`));
        expect(event, 'own active reservation present').toBeTruthy();
        expect(event).toContain(`DTSTART;VALUE=DATE:${start}`);
        expect(event).toContain(`DTEND;VALUE=DATE:${endExclusive}`);
        expect(body).not.toContain(`UID:reservation-${cancelled}@pinakes`);
        expect(body).not.toContain(`UID:reservation-${other}@pinakes`);
    } finally {
        F.dbExec(`DELETE FROM prenotazioni WHERE id IN (${mine}, ${cancelled}, ${other})`);
    }
});

test('unknown or malformed tokens → 404', async ({ request }) => {
    expect((await feed(request, `${F.API}/calendar/feed/${'0'.repeat(64)}.ics`)).status).toBe(404);
    expect((await feed(request, ctx.feedUrl.replace(/[a-f0-9]{64}/, (t) => t.slice(0, 63) + (t.endsWith('a') ? 'b' : 'a')))).status).toBe(404);
    expect((await feed(request, `${F.API}/calendar/feed/NOTAHEXTOKEN.ics`)).status).toBe(404);
});

test('rotation: the old URL dies, the new one works', async ({ request }) => {
    const r = await F.api(request, 'POST', '/me/calendar/token', { token: ctx.tokenA });
    expect(r.status).toBe(201);
    expect(r.json.data.feed_url).not.toBe(ctx.feedUrl);
    expect(r.json.data.rotated_at).toBeTruthy();
    expect((await feed(request, ctx.feedUrl)).status).toBe(404);
    expect((await feed(request, r.json.data.feed_url)).status).toBe(200);
    ctx.feedUrl = r.json.data.feed_url;
});

test('a user B token feed never shows user A', async ({ request }) => {
    const r = await F.api(request, 'POST', '/me/calendar/token', { token: ctx.tokenB });
    const b = await feed(request, r.json.data.feed_url);
    expect(b.status).toBe(200);
    const unfolded = b.text.replace(/\r\n /g, '');
    expect(unfolded).toContain(`UID:loan-${ctx.loanB}@pinakes`);
    expect(unfolded).not.toContain(`loan-${ctx.loanA}@pinakes`);
});

test('a suspended or unverified account loses its feed without any revocation', async ({ request }) => {
    for (const [change, restore] of [
        ["stato='sospeso'", "stato='attivo'"],
        ["stato='scaduto'", "stato='attivo'"],
        ['email_verificata=0', 'email_verificata=1'],
    ]) {
        F.dbExec(`UPDATE utenti SET ${change} WHERE id=${ctx.userA}`);
        try {
            expect((await feed(request, ctx.feedUrl)).status, change).toBe(404);
        } finally {
            F.dbExec(`UPDATE utenti SET ${restore} WHERE id=${ctx.userA}`);
        }
        expect((await feed(request, ctx.feedUrl)).status, `${restore} again`).toBe(200);
    }
    expect(F.dbInt(`SELECT COUNT(*) FROM mobile_calendar_tokens WHERE user_id=${ctx.userA} AND revoked_at IS NULL`), 'the token itself was never revoked').toBe(1);
});

test('app access disabled → 404', async ({ request }) => {
    F.dbExec("UPDATE system_settings SET setting_value='0' WHERE category='mobile_api' AND setting_key='enabled'");
    try {
        expect((await feed(request, ctx.feedUrl)).status).toBe(404);
    } finally {
        F.dbExec("UPDATE system_settings SET setting_value='1' WHERE category='mobile_api' AND setting_key='enabled'");
    }
    expect((await feed(request, ctx.feedUrl)).status).toBe(200);
});

test('catalogue mode → feed 404 and /me/calendar feature_disabled; restored after', async ({ browser, request }) => {
    await F.setCatalogueMode(browser, true);
    try {
        expect((await feed(request, ctx.feedUrl)).status).toBe(404);
        const s = await F.api(request, 'GET', '/me/calendar', { token: ctx.tokenA });
        expect(s.status).toBe(404);
        expect(s.json.error.code).toBe('feature_disabled');
        const h = await F.api(request, 'GET', '/health');
        expect(h.json.data.features.calendar_feed).toBe(false);
    } finally {
        await F.setCatalogueMode(browser, false);
    }
    expect((await feed(request, ctx.feedUrl)).status).toBe(200);
});

test('revocation is immediate and idempotent', async ({ request }) => {
    const r1 = await F.api(request, 'DELETE', '/me/calendar/token', { token: ctx.tokenA });
    expect(r1.status).toBe(200);
    expect(r1.json.data).toEqual({ enabled: false });
    expect((await feed(request, ctx.feedUrl)).status).toBe(404);
    const r2 = await F.api(request, 'DELETE', '/me/calendar/token', { token: ctx.tokenA });
    expect(r2.status).toBe(200);
    const s = await F.api(request, 'GET', '/me/calendar', { token: ctx.tokenA });
    expect(s.json.data).toMatchObject({ enabled: false, feed_url: null, webcal_url: null });
    // Re-enabling issues a brand-new URL.
    const again = await F.api(request, 'POST', '/me/calendar/token', { token: ctx.tokenA });
    expect(again.json.data.feed_url).not.toBe(ctx.feedUrl);
    ctx.feedUrl = again.json.data.feed_url;
    expect((await feed(request, ctx.feedUrl)).status).toBe(200);
});

test('changing the password through the API revokes the feed URL too', async ({ request }) => {
    const newPass = 'Cal5678Changed!';
    const r = await F.api(request, 'POST', '/me/password', {
        token: ctx.tokenA, body: { current_password: PASS, password: newPass, password_confirm: newPass },
    });
    expect(r.status, r.text.slice(0, 300)).toBe(200);
    expect((await feed(request, ctx.feedUrl)).status, 'a leaked feed URL dies with the old password').toBe(404);
    expect(F.dbInt(`SELECT COUNT(*) FROM mobile_calendar_tokens WHERE user_id=${ctx.userA} AND revoked_at IS NULL`)).toBe(0);
    // The owner can subscribe again with a fresh URL.
    const token = await F.login(request, ctx.emailA, newPass, 'cal-a-new');
    const status = await F.api(request, 'GET', '/me/calendar', { token });
    expect(status.json.data).toMatchObject({ enabled: false, feed_url: null });
    const fresh = await F.api(request, 'POST', '/me/calendar/token', { token });
    expect((await feed(request, fresh.json.data.feed_url)).status).toBe(200);
});
