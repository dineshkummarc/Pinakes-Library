// @ts-check
// Mobile API 1.7.0 — CSV / LibraryThing imports, missing covers and bulk
// enrichment from the staff app.
//
// What this proves, end to end against the real Apache instance:
//   - an upload becomes a durable job (no PHP session): chunks are driven by
//     the client in order (409 out_of_order otherwise), the books land in the
//     catalogue through the web importer, the import_logs row carries the
//     operator, and a finished / cancelled / expired job answers 410;
//   - a cancel waits for a chunk in flight (409 job_busy) and a chunk that
//     loses the race with a cancel answers 409 job_cancelled without
//     resurrecting the job;
//   - jobs are private to their operator (an admin sees them all), the
//     history follows the web rule (own imports unless admin), the error CSV
//     is owner-or-admin;
//   - a LibraryThing TSV goes through the LibraryThing importer;
//   - missing covers list, cover fetch reasons, enrichment stats and the cron
//     switch round trip (the long sync/batch calls only with PINAKES_E2E_SLOW=1).
//
// Run: /tmp/run-e2e.sh tests/mobile-api-imports.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect, request: playwrightRequest } = require('@playwright/test');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Imp1234Test!';
/** @type {Record<string, any>} */
const ctx = { users: [], jobs: [] };

const csv = (rows) => Buffer.from(['titolo;autori;isbn13', ...rows].join('\n') + '\n', 'utf-8');
const upload = (request, token, name, buffer, fields = {}) => F.api(request, 'POST', '/staff/imports', {
    token,
    multipart: { file: { name, mimeType: 'application/octet-stream', buffer }, kind: 'csv', ...fields },
});

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    for (const [key, role] of [['staff', 'staff'], ['other', 'staff'], ['admin', 'admin'], ['std', 'standard']]) {
        const email = `imp_${key}_${RUN}@pinakes.test`;
        ctx[`${key}Id`] = F.createUser(email, PASS, role, `IMP${key.slice(0, 2).toUpperCase()}`);
        ctx.users.push(ctx[`${key}Id`]);
        ctx[key] = await F.login(request, email, PASS, `imp-${key}`);
    }
    ctx.cron = (await F.api(request, 'GET', '/staff/enrich', { token: ctx.staff })).json?.data?.cron_enabled;
});

test.afterAll(async ({ request }) => {
    if (typeof ctx.cron === 'boolean') {
        await F.api(request, 'PUT', '/staff/enrich/cron', { token: ctx.staff, body: { enabled: ctx.cron } });
    }
    const books = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM libri WHERE titolo LIKE '%${RUN}%'`);
    if (books && books !== 'NULL') F.deleteBooks(books.split(',').map(Number));
    try { F.dbExec(`DELETE FROM autori WHERE nome LIKE '%${RUN}%'`); } catch {}
    const logs = F.dbScalar(`SELECT GROUP_CONCAT(QUOTE(import_id)) FROM import_logs WHERE user_id IN (${ctx.users.join(',')})`);
    if (logs && logs !== 'NULL') { try { F.dbExec(`DELETE FROM import_logs WHERE import_id IN (${logs})`); } catch {} }
    try { F.dbExec(`DELETE FROM mobile_import_jobs WHERE user_id IN (${ctx.users.join(',')})`); } catch {}
    F.deleteUsers(ctx.users);
    F.restoreGate(ctx.gate);
});

test('a borrower gets 403; health advertises imports, covers and LibraryThing', async ({ request }) => {
    for (const [method, route] of [['GET', '/staff/imports'], ['POST', '/staff/imports'], ['GET', '/staff/imports/example.csv'],
        ['GET', '/staff/covers/missing'], ['POST', '/staff/covers/sync'], ['GET', '/staff/enrich'], ['POST', '/staff/enrich/batch'],
        ['PUT', '/staff/enrich/cron'], ['POST', '/staff/books/1/cover/fetch'], ['GET', '/staff/exports/librarything.tsv']]) {
        expect((await F.api(request, method, route, { token: ctx.std, body: method === 'GET' ? undefined : {} })).status, `${method} ${route}`).toBe(403);
    }
    const f = (await F.api(request, 'GET', '/health')).json.data.features;
    expect(f.staff_imports).toBe(true);
    expect(f.staff_covers).toBe(true);
    expect(f.staff_librarything).toBe(true);
});

test.describe('CSV import job', () => {
    test('wrong extension / missing file → 422 field=file', async ({ request }) => {
        const bad = await upload(request, ctx.staff, 'books.xlsx', csv([`A ${RUN};X;`]));
        expect(bad.status).toBe(422);
        expect(bad.json.meta.field).toBe('file');
        const none = await F.api(request, 'POST', '/staff/imports', { token: ctx.staff, multipart: { kind: 'csv' } });
        expect(none.status).toBe(422);
        const kind = await upload(request, ctx.staff, 'books.csv', csv([`A ${RUN};X;`]), { kind: 'marc' });
        expect(kind.status).toBe(422);
        expect(kind.json.meta.field).toBe('kind');
    });

    test('upload → ordered chunks → books in the catalogue, history row by the operator, then 410', async ({ request }) => {
        const isbn = F.uniqueIsbn13();
        const r = await upload(request, ctx.staff, `libri_${RUN}.csv`, csv([
            `Import one ${RUN};Autore Import ${RUN};${isbn}`,
            `Import two ${RUN};Autore Import ${RUN};`,
        ]));
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        const job = r.json.data;
        expect(job).toMatchObject({ kind: 'csv', status: 'ready', total_rows: 2, processed: 0, enable_scraping: false, complete: false });
        expect(job.chunk_size).toBeGreaterThan(1);
        ctx.job = job.id;

        const early = await F.api(request, 'POST', `/staff/imports/${job.id}/chunk`, { token: ctx.staff, body: { start: 1 } });
        expect(early.status).toBe(409);
        expect(early.json.error.code).toBe('out_of_order');
        expect(early.json.meta.processed).toBe(0);

        const c = await F.api(request, 'POST', `/staff/imports/${job.id}/chunk`, { token: ctx.staff, body: {} });
        expect(c.status, c.text.slice(0, 300)).toBe(200);
        expect(c.json.data).toMatchObject({ status: 'completed', processed: 2, imported: 2, complete: true });
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE titolo LIKE 'Import % ${RUN}' AND deleted_at IS NULL`)).toBe(2);
        expect(F.dbInt(`SELECT COUNT(*) FROM import_logs WHERE user_id=${ctx.staffId} AND import_type='csv' AND imported=2`)).toBe(1);

        const after = await F.api(request, 'POST', `/staff/imports/${job.id}/chunk`, { token: ctx.staff, body: {} });
        expect(after.status).toBe(410);
        expect(after.json.error.code).toBe('gone');
    });

    test('jobs are private: another operator gets 404, an admin sees it; history follows the web rule', async ({ request }) => {
        expect((await F.api(request, 'GET', `/staff/imports/${ctx.job}`, { token: ctx.other })).status).toBe(404);
        expect((await F.api(request, 'GET', `/staff/imports/${ctx.job}`, { token: ctx.admin })).status).toBe(200);
        const mine = await F.api(request, 'GET', '/staff/imports', { token: ctx.staff });
        expect(mine.status).toBe(200);
        expect(mine.json.data.jobs.map((j) => j.id)).toContain(ctx.job);
        const entry = mine.json.data.history.find((h) => h.user?.id === ctx.staffId);
        expect(entry).toMatchObject({ kind: 'csv', imported: 2, status: 'completed' });
        ctx.importId = entry.import_id;
        const other = await F.api(request, 'GET', '/staff/imports', { token: ctx.other });
        expect(other.json.data.jobs.map((j) => j.id)).not.toContain(ctx.job);
        expect(other.json.data.history.some((h) => h.import_id === ctx.importId)).toBe(false);
        const admin = await F.api(request, 'GET', '/staff/imports', { token: ctx.admin });
        expect(admin.json.data.history.some((h) => h.import_id === ctx.importId)).toBe(true);

        const errs = await F.api(request, 'GET', `/staff/imports/history/${ctx.importId}/errors.csv`, { token: ctx.staff });
        expect(errs.status, errs.text.slice(0, 200)).toBe(200);
        expect(errs.headers['content-type']).toContain('text/csv');
        expect((await F.api(request, 'GET', `/staff/imports/history/${ctx.importId}/errors.csv`, { token: ctx.other })).status).toBe(403);
        expect((await F.api(request, 'GET', `/staff/imports/history/${ctx.importId}/errors.csv`, { token: ctx.admin })).status).toBe(200);
        const example = await F.api(request, 'GET', '/staff/imports/example.csv', { token: ctx.staff });
        expect(example.status).toBe(200);
        expect(example.text).toContain('titolo');
    });

    test('cancel is idempotent and closes the job; an expired job answers 410', async ({ request }) => {
        const r = await upload(request, ctx.staff, `cancel_${RUN}.csv`, csv([`Cancel me ${RUN};X;`]));
        expect(r.status).toBe(201);
        const d1 = await F.api(request, 'DELETE', `/staff/imports/${r.json.data.id}`, { token: ctx.staff });
        expect(d1.status).toBe(200);
        expect(d1.json.data).toEqual({ id: r.json.data.id, cancelled: true });
        expect((await F.api(request, 'DELETE', `/staff/imports/${r.json.data.id}`, { token: ctx.staff })).status).toBe(200);
        expect((await F.api(request, 'POST', `/staff/imports/${r.json.data.id}/chunk`, { token: ctx.staff, body: {} })).status).toBe(410);
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE titolo = 'Cancel me ${RUN}'`)).toBe(0);

        const e = await upload(request, ctx.staff, `expire_${RUN}.csv`, csv([`Expire me ${RUN};X;`]));
        F.dbExec(`UPDATE mobile_import_jobs SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id='${e.json.data.id}'`);
        expect((await F.api(request, 'POST', `/staff/imports/${e.json.data.id}/chunk`, { token: ctx.staff, body: {} })).status).toBe(410);
        expect((await F.api(request, 'DELETE', '/staff/imports/00000000-0000-4000-8000-000000000000', { token: ctx.staff })).status).toBe(404);
    });

    // A cancel must not race a chunk in flight. A trigger on libri stands in
    // for the slow / concurrent part: it fires inside the importer's own
    // INSERT, on the request's connection, while the chunk holds its lock.
    test('cancel during a running chunk → 409 job_busy; the chunk completes and the job is not cancelled', async ({ request }) => {
        const r = await upload(request, ctx.staff, `busy_${RUN}.csv`, csv([`Slow race ${RUN};X;`]));
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        const id = r.json.data.id;
        const trigger = `mapi_e2e_slow_${process.pid}`;
        F.dbExec(`CREATE TRIGGER ${trigger} AFTER INSERT ON libri FOR EACH ROW DO IF(NEW.titolo = 'Slow race ${RUN}', SLEEP(8), 0)`);
        try {
            const chunk = F.api(request, 'POST', `/staff/imports/${id}/chunk`, { token: ctx.staff, body: {} });
            for (let i = 0; i < 100 && F.dbScalar(`SELECT status FROM mobile_import_jobs WHERE id='${id}'`) !== 'running'; i++) await F.sleep(100);
            expect(F.dbScalar(`SELECT status FROM mobile_import_jobs WHERE id='${id}'`)).toBe('running');
            // A separate context: the shared one carries a PHP session cookie,
            // and the session file lock would queue this call behind the chunk.
            const other = await playwrightRequest.newContext();
            let busy;
            try {
                busy = await F.api(other, 'DELETE', `/staff/imports/${id}`, { token: ctx.staff });
            } finally {
                await other.dispose();
            }
            expect(busy.status, busy.text.slice(0, 300)).toBe(409);
            expect(busy.json.error.code).toBe('job_busy');
            const done = await chunk;
            expect(done.status, done.text.slice(0, 300)).toBe(200);
            expect(done.json.data).toMatchObject({ status: 'completed', processed: 1, complete: true });
        } finally {
            F.dbExec(`DROP TRIGGER IF EXISTS ${trigger}`);
        }
        const late = await F.api(request, 'DELETE', `/staff/imports/${id}`, { token: ctx.staff });
        expect(late.status).toBe(200);
        expect(F.dbScalar(`SELECT status FROM mobile_import_jobs WHERE id='${id}'`)).toBe('completed');
    });

    test('a chunk that loses the race with a cancel → 409 job_cancelled and the job stays cancelled', async ({ request }) => {
        const r = await upload(request, ctx.staff, `lost_${RUN}.csv`, csv([`Race cancel ${RUN};X;`]));
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        const id = r.json.data.id;
        const trigger = `mapi_e2e_cancel_${process.pid}`;
        // A cancel landing mid-chunk (what the lock prevents through the API):
        // the job flips to cancelled while the importer writes its row.
        F.dbExec(`CREATE TRIGGER ${trigger} AFTER INSERT ON libri FOR EACH ROW UPDATE mobile_import_jobs SET status = 'cancelled' WHERE id = '${id}' AND NEW.titolo = 'Race cancel ${RUN}'`);
        let c;
        try {
            c = await F.api(request, 'POST', `/staff/imports/${id}/chunk`, { token: ctx.staff, body: {} });
        } finally {
            F.dbExec(`DROP TRIGGER IF EXISTS ${trigger}`);
        }
        expect(c.status, c.text.slice(0, 300)).toBe(409);
        expect(c.json.error.code).toBe('job_cancelled');
        expect(c.json.meta.processed).toBe(1);
        const job = (await F.api(request, 'GET', `/staff/imports/${id}`, { token: ctx.staff })).json.data;
        expect(job).toMatchObject({ status: 'cancelled', processed: 0 });
        expect((await F.api(request, 'POST', `/staff/imports/${id}/chunk`, { token: ctx.staff, body: {} })).status).toBe(410);
    });

    test('a LibraryThing TSV goes through the LibraryThing importer', async ({ request }) => {
        const tsv = Buffer.from(['Book Id\tTitle\tISBNs\tPrimary Author', `99${String(process.pid).slice(-5)}\tLT book ${RUN}\t\tAutore LT ${RUN}`].join('\n') + '\n');
        const r = await F.api(request, 'POST', '/staff/imports', {
            token: ctx.staff, multipart: { file: { name: `lt_${RUN}.tsv`, mimeType: 'text/tab-separated-values', buffer: tsv }, kind: 'librarything' },
        });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data).toMatchObject({ kind: 'librarything', total_rows: 1 });
        const c = await F.api(request, 'POST', `/staff/imports/${r.json.data.id}/chunk`, { token: ctx.staff, body: { start: 0 } });
        expect(c.status, c.text.slice(0, 300)).toBe(200);
        expect(c.json.data).toMatchObject({ status: 'completed', processed: 1, complete: true });
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE titolo = 'LT book ${RUN}' AND deleted_at IS NULL`)).toBe(1);
        const notLt = await F.api(request, 'POST', '/staff/imports', {
            token: ctx.staff, multipart: { file: { name: 'x.tsv', mimeType: 'text/plain', buffer: Buffer.from('a\tb\n1\t2\n') }, kind: 'librarything' },
        });
        expect(notLt.status).toBe(422);
        const exp = await F.api(request, 'GET', '/staff/exports/librarything.tsv', { token: ctx.staff });
        expect(exp.status).toBe(200);
        expect(exp.text).toContain(`LT book ${RUN}`);
    });
});

test.describe('Covers and enrichment', () => {
    test('missing covers list and fetch reasons', async ({ request }) => {
        const withIsbn = F.dbInsert(`INSERT INTO libri (titolo, isbn13, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('Cover missing ${RUN}', '${F.uniqueIsbn13()}', 0, 0, NOW(), NOW())`);
        const noIsbn = F.dbInsert(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, created_at, updated_at) VALUES ('Cover noisbn ${RUN}', 0, 0, NOW(), NOW())`);
        const list = await F.api(request, 'GET', '/staff/covers/missing?limit=100', { token: ctx.staff });
        expect(list.status).toBe(200);
        expect(list.json.meta.total_count).toBeGreaterThanOrEqual(1);
        let found = list.json.data.some((b) => b.id === withIsbn);
        let cursor = list.json.meta.next_cursor;
        while (!found && cursor) {
            const page = await F.api(request, 'GET', `/staff/covers/missing?limit=100&cursor=${cursor}`, { token: ctx.staff });
            found = page.json.data.some((b) => b.id === withIsbn);
            cursor = page.json.meta.next_cursor;
        }
        expect(found).toBe(true);
        const r = await F.api(request, 'POST', `/staff/books/${noIsbn}/cover/fetch`, { token: ctx.staff });
        expect(r.status).toBe(200);
        expect(r.json.data).toMatchObject({ fetched: false, reason: 'no_isbn' });
        expect((await F.api(request, 'POST', '/staff/books/999999999/cover/fetch', { token: ctx.staff })).status).toBe(404);
    });

    test('enrich stats and the cron switch round trip', async ({ request }) => {
        const s = await F.api(request, 'GET', '/staff/enrich', { token: ctx.staff });
        expect(s.status).toBe(200);
        for (const k of ['total_with_isbn', 'missing_cover', 'missing_description', 'pending']) {
            expect(typeof s.json.data.stats[k], k).toBe('number');
        }
        const flip = !s.json.data.cron_enabled;
        const t = await F.api(request, 'PUT', '/staff/enrich/cron', { token: ctx.staff, body: { enabled: flip } });
        expect(t.status).toBe(200);
        expect(t.json.data.enabled).toBe(flip);
        expect((await F.api(request, 'GET', '/staff/enrich', { token: ctx.staff })).json.data.cron_enabled).toBe(flip);
        expect((await F.api(request, 'PUT', '/staff/enrich/cron', { token: ctx.staff, body: { enabled: 'yes' } })).status).toBe(422);
    });

    test('synchronous sync and batch (PINAKES_E2E_SLOW=1 only)', async ({ request }) => {
        test.skip(!process.env.PINAKES_E2E_SLOW, 'long synchronous calls: set PINAKES_E2E_SLOW=1');
        const sync = await F.api(request, 'POST', '/staff/covers/sync', { token: ctx.staff });
        expect(sync.status).toBe(200);
        expect(typeof sync.json.data.synced).toBe('number');
        const batch = await F.api(request, 'POST', '/staff/enrich/batch', { token: ctx.staff });
        expect(batch.status).toBe(200);
        expect(Array.isArray(batch.json.data.details)).toBe(true);
    });
});
