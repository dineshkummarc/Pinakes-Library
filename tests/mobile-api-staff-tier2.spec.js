// @ts-check
// Mobile API 1.7.0 Tier 2 — staff work on the optional plugins: the
// periodicals desk (emeroteca bridge), book club management (book-club
// bridge), FRBR works (frbr-lrm) and digital attachments (digital-library).
// Every block skips when its plugin is inactive.
//
// What this proves, end to end against the real Apache instance: each
// route is admin/staff only, writes go through the plugin's own admin
// controllers (a year and an issue added, an expected issue received and a
// missing one claimed with the web desk's refusals; subscriptions merged on
// PUT; a club created with its workflow and members; works and expressions
// with the book link; a PDF stored by the digital-library upload with its
// content check), and /health advertises the four areas.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-staff-tier2.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Tier1234Test!';
const PUBLIC = path.resolve(__dirname, '..', 'public');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
/** @type {Record<string, any>} */
const ctx = { users: [], books: [], files: [] };
const active = (name) => F.dbScalar(`SELECT is_active FROM plugins WHERE name='${name}' LIMIT 1`) === '1';

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    for (const [key, role] of [['staff', 'staff'], ['std', 'standard']]) {
        const email = `t2_${key}_${RUN}@pinakes.test`;
        ctx[`${key}Id`] = F.createUser(email, PASS, role, `T2${key.slice(0, 2).toUpperCase()}`);
        ctx.users.push(ctx[`${key}Id`]);
        ctx[key] = await F.login(request, email, PASS, `t2-${key}`);
    }
});

test.afterAll(async () => {
    if (ctx.periodical) {
        try {
            F.dbExec(`DELETE f FROM emeroteca_fascicoli f JOIN emeroteca_annate a ON a.id = f.annata_id WHERE a.testata_id=${ctx.periodical}`);
            F.dbExec(`DELETE FROM emeroteca_annate WHERE testata_id=${ctx.periodical}`);
            F.dbExec(`DELETE FROM emeroteca_abbonamenti WHERE testata_id=${ctx.periodical}`);
            F.dbExec(`DELETE FROM emeroteca_testate WHERE id=${ctx.periodical}`);
        } catch {}
    }
    try {
        const clubs = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM bookclub_clubs WHERE name LIKE '%${RUN}%'`);
        if (clubs && clubs !== 'NULL') {
            F.dbExec(`DELETE FROM bookclub_members WHERE club_id IN (${clubs})`);
            const wf = F.dbScalar(`SELECT GROUP_CONCAT(workflow_id) FROM bookclub_clubs WHERE id IN (${clubs}) AND workflow_id IS NOT NULL`);
            F.dbExec(`DELETE FROM bookclub_clubs WHERE id IN (${clubs})`);
            if (wf && wf !== 'NULL') { try { F.dbExec(`DELETE FROM bookclub_workflows WHERE id IN (${wf})`); } catch {} }
        }
    } catch {}
    try {
        const works = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM opere WHERE titolo_uniforme LIKE '%${RUN}%'`);
        if (works && works !== 'NULL') {
            F.dbExec(`UPDATE libri SET opera_id = NULL, espressione_id = NULL WHERE opera_id IN (${works})`);
            F.dbExec(`DELETE FROM espressioni WHERE opera_id IN (${works})`);
            F.dbExec(`DELETE FROM opere WHERE id IN (${works})`);
        }
    } catch {}
    for (const f of ctx.files) {
        if (/^\/uploads\/digital\/[^/]+$/.test(f)) {
            // Since #480 uploads land in storage/digital; older ones in public/uploads/digital.
            const name = path.basename(f);
            for (const dir of [path.join(PUBLIC, 'uploads/digital'), path.resolve(PUBLIC, '..', 'storage/digital')]) { try { fs.unlinkSync(path.join(dir, name)); } catch {} }
        }
    }
    F.deleteBooks(ctx.books);
    try { F.dbExec(`DELETE FROM log_modifiche WHERE utente_id=${ctx.staffId}`); } catch {}
    F.deleteUsers(ctx.users);
    F.restoreGate(ctx.gate);
});

test('health advertises the Tier 2 areas that are active', async ({ request }) => {
    const f = (await F.api(request, 'GET', '/health')).json.data.features;
    expect(f.staff_periodicals).toBe(active('emeroteca'));
    expect(f.staff_bookclub).toBe(active('book-club'));
    expect(f.staff_frbr).toBe(active('frbr-lrm'));
    expect(f.staff_digital_library).toBe(active('digital-library'));
});

test.describe('Periodicals desk', () => {
    test.beforeAll(() => { test.skip(!active('emeroteca'), 'emeroteca inactive'); });

    test('borrower 403; add a year and issues; receive and claim with the desk refusals', async ({ request }) => {
        expect((await F.api(request, 'GET', '/periodicals/staff/subscriptions', { token: ctx.std })).status).toBe(403);
        ctx.periodical = F.dbInsert(`INSERT INTO emeroteca_testate (titolo) VALUES ('Rivista ${RUN}')`);
        const y = await F.api(request, 'POST', `/periodicals/staff/${ctx.periodical}/years`, { token: ctx.staff, body: { year: 2026, volume: 'XII' } });
        expect(y.status, y.text.slice(0, 300)).toBe(201);
        expect(y.json.data).toMatchObject({ periodical_id: ctx.periodical, year: 2026, volume: 'XII' });
        const dupYear = await F.api(request, 'POST', `/periodicals/staff/${ctx.periodical}/years`, { token: ctx.staff, body: { year: 2026, volume: 'XII' } });
        expect(dupYear.status).toBe(422);
        expect((await F.api(request, 'POST', `/periodicals/staff/${ctx.periodical}/years`, { token: ctx.staff, body: { year: 1200 } })).status).toBe(422);

        const i1 = await F.api(request, 'POST', `/periodicals/staff/years/${y.json.data.id}/issues`, { token: ctx.staff, body: { number: '1', status: 'atteso' } });
        expect(i1.status, i1.text.slice(0, 300)).toBe(201);
        expect(i1.json.data).toMatchObject({ number: '1', status: 'atteso', year: 2026 });
        const rec = await F.api(request, 'POST', `/periodicals/staff/issues/${i1.json.data.id}/receive`, { token: ctx.staff });
        expect(rec.status, rec.text.slice(0, 300)).toBe(200);
        expect(rec.json.data.status).toBe('posseduto');
        const again = await F.api(request, 'POST', `/periodicals/staff/issues/${i1.json.data.id}/receive`, { token: ctx.staff });
        expect(again.status).toBe(409);
        expect(again.json.error.code).toBe('conflict');

        const i2 = await F.api(request, 'POST', `/periodicals/staff/years/${y.json.data.id}/issues`, { token: ctx.staff, body: { number: '2', status: 'atteso' } });
        const claim = await F.api(request, 'POST', `/periodicals/staff/issues/${i2.json.data.id}/claim`, { token: ctx.staff });
        expect(claim.status, claim.text.slice(0, 300)).toBe(200);
        expect(claim.json.data).toMatchObject({ status: 'reclamato', claims: 1 });
        expect((await F.api(request, 'POST', `/periodicals/staff/issues/${i1.json.data.id}/claim`, { token: ctx.staff })).status).toBe(409);
        expect((await F.api(request, 'POST', `/periodicals/staff/years/${y.json.data.id}/issues`, { token: ctx.staff, body: { number: '' } })).status).toBe(422);
        expect((await F.api(request, 'POST', '/periodicals/staff/issues/999999999/receive', { token: ctx.staff })).status).toBe(404);

        const scan = await F.api(request, 'GET', '/periodicals/staff/scan-lookup?code=9770000000017', { token: ctx.staff });
        expect(scan.status).toBe(200);
        expect(scan.json.data.match).toBe('none');
        expect((await F.api(request, 'GET', '/periodicals/staff/scan-lookup', { token: ctx.staff })).status).toBe(422);
    });

    test('subscriptions: create, partial PUT keeps the rest, invalid → 422', async ({ request }) => {
        const c = await F.api(request, 'POST', '/periodicals/staff/subscriptions', {
            token: ctx.staff, body: { periodical_id: ctx.periodical, supplier: `Fornitore ${RUN}`, cost: 49.9, currency: 'EUR', start_date: '2026-01-01', end_date: '2026-12-31', auto_renew: true },
        });
        expect(c.status, c.text.slice(0, 300)).toBe(201);
        expect(c.json.data).toMatchObject({ supplier: `Fornitore ${RUN}`, cost: 49.9, auto_renew: true, active: true });
        const u = await F.api(request, 'PUT', `/periodicals/staff/subscriptions/${c.json.data.id}`, { token: ctx.staff, body: { notes: 'Rinnovare a novembre' } });
        expect(u.status, u.text.slice(0, 300)).toBe(200);
        expect(u.json.data).toMatchObject({ notes: 'Rinnovare a novembre', supplier: `Fornitore ${RUN}`, end_date: '2026-12-31', auto_renew: true });
        const bad = await F.api(request, 'POST', '/periodicals/staff/subscriptions', { token: ctx.staff, body: { periodical_id: ctx.periodical, supplier: '' } });
        expect(bad.status).toBe(422);
        const list = await F.api(request, 'GET', `/periodicals/staff/subscriptions?periodical_id=${ctx.periodical}`, { token: ctx.staff });
        expect(list.json.data.map((s) => s.id)).toEqual([c.json.data.id]);
    });
});

test.describe('Book club management', () => {
    test.beforeAll(() => { test.skip(!active('book-club'), 'book-club inactive'); });

    test('club CRUD, workflow, members', async ({ request }) => {
        expect((await F.api(request, 'GET', '/bookclub/staff/clubs', { token: ctx.std })).status).toBe(403);
        const c = await F.api(request, 'POST', '/bookclub/staff/clubs', { token: ctx.staff, body: { name: `Club ${RUN}`, privacy: 'private', description: 'Desc' } });
        expect(c.status, c.text.slice(0, 300)).toBe(201);
        const id = c.json.data.id;
        expect(c.json.data).toMatchObject({ name: `Club ${RUN}`, privacy: 'private', active: true });
        expect(c.json.data.members.map((m) => m.user_id)).toContain(ctx.staffId);
        const u = await F.api(request, 'PUT', `/bookclub/staff/clubs/${id}`, { token: ctx.staff, body: { description: 'New desc' } });
        expect(u.status).toBe(200);
        expect(u.json.data).toMatchObject({ name: `Club ${RUN}`, description: 'New desc', privacy: 'private' });
        expect((await F.api(request, 'POST', '/bookclub/staff/clubs', { token: ctx.staff, body: { name: '' } })).status).toBe(422);

        const w = await F.api(request, 'PUT', `/bookclub/staff/clubs/${id}/workflow`, { token: ctx.staff, body: { states: [
            { key: 'idea', label: 'Idea', color: '#112233' }, { key: 'reading', label: 'In lettura', flags: { current: true } }, { key: 'done', label: 'Letto', flags: { archived: true } },
        ] } });
        expect(w.status, w.text.slice(0, 300)).toBe(200);
        expect(w.json.data.workflow.map((s) => s.key)).toEqual(['idea', 'reading', 'done']);
        expect((await F.api(request, 'PUT', `/bookclub/staff/clubs/${id}/workflow`, { token: ctx.staff, body: { states: [{ label: 'Only' }] } })).status).toBe(422);

        const m = await F.api(request, 'POST', `/bookclub/staff/clubs/${id}/members`, { token: ctx.staff, body: { user_id: ctx.stdId, role: 'member' } });
        expect(m.status, m.text.slice(0, 300)).toBe(201);
        expect(m.json.data).toMatchObject({ user_id: ctx.stdId, role: 'member', status: 'active' });
        const s = await F.api(request, 'PUT', `/bookclub/staff/clubs/${id}/members/${m.json.data.id}`, { token: ctx.staff, body: { status: 'suspended', role: 'moderator' } });
        expect(s.json.data).toMatchObject({ status: 'suspended', role: 'moderator' });
        expect((await F.api(request, 'POST', `/bookclub/staff/clubs/${id}/members`, { token: ctx.staff, body: { user_id: 999999999 } })).status).toBe(404);

        const d = await F.api(request, 'DELETE', `/bookclub/staff/clubs/${id}`, { token: ctx.staff });
        expect(d.json.data).toEqual({ id, deleted: true });
        expect((await F.api(request, 'GET', `/bookclub/staff/clubs/${id}`, { token: ctx.staff })).status).toBe(404);
    });
});

test.describe('FRBR works', () => {
    test.beforeAll(() => { test.skip(!active('frbr-lrm'), 'frbr-lrm inactive'); });

    test('work and expression CRUD; attach a title', async ({ request }) => {
        const w = await F.api(request, 'POST', '/staff/works', { token: ctx.staff, body: { uniform_title: `Opera ${RUN}`, original_language: 'ita', created_from: 1320 } });
        expect(w.status, w.text.slice(0, 300)).toBe(201);
        const id = w.json.data.id;
        expect(w.json.data).toMatchObject({ uniform_title: `Opera ${RUN}`, original_language: 'ita', created_from: 1320, expressions: [], editions: [] });
        expect((await F.api(request, 'POST', '/staff/works', { token: ctx.staff, body: { uniform_title: ' ' } })).status).toBe(422);
        const u = await F.api(request, 'PUT', `/staff/works/${id}`, { token: ctx.staff, body: { notes: 'Nota' } });
        expect(u.json.data).toMatchObject({ uniform_title: `Opera ${RUN}`, notes: 'Nota', created_from: 1320 });
        const s = await F.api(request, 'GET', `/staff/works?q=${encodeURIComponent(RUN)}`, { token: ctx.staff });
        expect(s.json.data.map((x) => x.id)).toEqual([id]);

        const e = await F.api(request, 'POST', `/staff/works/${id}/expressions`, { token: ctx.staff, body: { type: 'traduzione', language: 'eng', year: 1990 } });
        expect(e.status, e.text.slice(0, 300)).toBe(201);
        expect(e.json.data).toMatchObject({ work_id: id, type: 'traduzione', language: 'eng', year: 1990 });
        const eu = await F.api(request, 'PUT', `/staff/works/expressions/${e.json.data.id}`, { token: ctx.staff, body: { title: 'English' } });
        expect(eu.json.data).toMatchObject({ title: 'English', language: 'eng', year: 1990 });
        expect((await F.api(request, 'POST', `/staff/works/${id}/expressions`, { token: ctx.staff, body: { type: 'poem' } })).status).toBe(422);

        const book = (await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Edizione ${RUN}` } })).json.data.id;
        ctx.books.push(book);
        const a = await F.api(request, 'POST', `/staff/books/${book}/work`, { token: ctx.staff, body: { work_id: id, expression_id: e.json.data.id } });
        expect(a.status, a.text.slice(0, 300)).toBe(200);
        expect(a.json.data).toMatchObject({ book_id: book, work: { id }, expression_id: e.json.data.id });
        expect((await F.api(request, 'GET', `/staff/works/${id}`, { token: ctx.staff })).json.data.editions.map((x) => x.id)).toEqual([book]);
        // A malformed expression_id must never collapse to "clear": present
        // means null (clear) or a positive id, anything else is a 422.
        for (const bad of ['abc', '12x', -3, true, 0]) {
            const r = await F.api(request, 'POST', `/staff/books/${book}/work`, { token: ctx.staff, body: { work_id: id, expression_id: bad } });
            expect(r.status, `${JSON.stringify(bad)}: ${r.text.slice(0, 200)}`).toBe(422);
            expect(r.json.meta.field).toBe('expression_id');
        }
        const edition = () => (F.api(request, 'GET', `/staff/works/${id}`, { token: ctx.staff })).then((r) => r.json.data.editions.find((x) => x.id === book));
        expect((await edition()).expression_id).toBe(e.json.data.id);
        const asString = await F.api(request, 'POST', `/staff/books/${book}/work`, { token: ctx.staff, body: { work_id: id, expression_id: String(e.json.data.id) } });
        expect(asString.json.data.expression_id).toBe(e.json.data.id);
        const cleared = await F.api(request, 'POST', `/staff/books/${book}/work`, { token: ctx.staff, body: { work_id: id, expression_id: null } });
        expect(cleared.status, cleared.text.slice(0, 200)).toBe(200);
        expect(cleared.json.data.expression_id).toBeNull();
        const detach = await F.api(request, 'POST', `/staff/books/${book}/work`, { token: ctx.staff, body: { work_id: null } });
        expect(detach.json.data.work).toBeNull();

        expect((await F.api(request, 'DELETE', `/staff/works/expressions/${e.json.data.id}`, { token: ctx.staff })).status).toBe(200);
        expect((await F.api(request, 'DELETE', `/staff/works/expressions/${e.json.data.id}`, { token: ctx.staff })).status).toBe(404);
        expect((await F.api(request, 'DELETE', `/staff/works/${id}`, { token: ctx.staff })).status).toBe(200);
        expect((await F.api(request, 'GET', `/staff/works/${id}`, { token: ctx.staff })).status).toBe(404);
    });
});

test.describe('Digital attachments', () => {
    test.beforeAll(() => { test.skip(!active('digital-library'), 'digital-library inactive'); });

    test('a PDF is stored; a fake one is refused by the content check', async ({ request }) => {
        const book = (await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Digitale ${RUN}` } })).json.data.id;
        ctx.books.push(book);
        const r = await F.api(request, 'POST', `/staff/books/${book}/digital`, { token: ctx.staff, multipart: { file: { name: `libro_${RUN}.pdf`, mimeType: 'application/pdf', buffer: PDF }, digital_type: 'ebook' } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        // Attached to the title, and handed back as a link signed for the caller (#480): the
        // file lives outside the web root, and an unattached file opens only for operators.
        expect(r.json.data.attached).toBe(true);
        expect(r.json.data.file_url).toMatch(/\/uploads\/digital\/.+\.pdf\?.*pk_sig=/);
        expect(r.json.data.audio_url).toBeNull();
        const stored = new URL(r.json.data.file_url).pathname;
        ctx.files.push(stored);
        const attachments = JSON.parse(F.dbScalar(`SELECT digital_attachments FROM libri WHERE id=${book}`));
        expect(attachments.map(a => a.url)).toContain(stored);
        expect(F.dbScalar(`SELECT file_url FROM libri WHERE id=${book}`)).toBe(stored);
        // The app opens it with the signed link alone, no session: the same bytes.
        const opened = await request.get(r.json.data.file_url, { maxRedirects: 0 });
        expect(opened.status()).toBe(200);
        expect(Buffer.compare(await opened.body(), PDF)).toBe(0);
        const fake = await F.api(request, 'POST', `/staff/books/${book}/digital`, { token: ctx.staff, multipart: { file: { name: 'fake.pdf', mimeType: 'application/pdf', buffer: Buffer.from('not a pdf') }, digital_type: 'ebook' } });
        expect(fake.status).toBe(422);
        expect(fake.json.error.code).toBe('upload_rejected');
        expect((await F.api(request, 'POST', `/staff/books/${book}/digital`, { token: ctx.staff, multipart: { file: { name: 'x.pdf', mimeType: 'application/pdf', buffer: PDF }, digital_type: 'video' } })).status).toBe(422);
        expect((await F.api(request, 'POST', `/staff/books/${book}/digital`, { token: ctx.std, multipart: { file: { name: 'x.pdf', mimeType: 'application/pdf', buffer: PDF }, digital_type: 'ebook' } })).status).toBe(403);
    });
});
