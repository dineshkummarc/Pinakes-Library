// @ts-check
// Mobile API 1.7.0 — staff work on plugin content: desiderata (donation
// proposals) and archives (units, authority records, attachments,
// publication). Each block skips when its plugin is inactive.
//
// What this proves, end to end against the real Apache instance:
//   - desiderata: the proposal list, accept / reject with the offer_closed
//     refusal, receipt that creates the copy and clears the request flag,
//     the direct receipt and its open_offers / not_wanted refusals, delete;
//   - archives: the Archives plugin's own validation answered as 422 with
//     the field, duplicate reference 409, partial PUT, the publish switch
//     hiding / showing the unit on the public /archives, cover and document
//     upload (and a rejected MIME), authority CRUD with attach / detach.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-staff-plugins.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const REF = `MAPI-${String(process.pid).slice(-5)}-${String(Date.now()).slice(-6)}`;
const PASS = 'Plug1234Test!';
const PUBLIC = path.resolve(__dirname, '..', 'public');
const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
/** @type {Record<string, any>} */
const ctx = { users: [], books: [] };

const active = (name) => F.dbScalar(`SELECT is_active FROM plugins WHERE name='${name}' LIMIT 1`) === '1';

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    for (const [key, role] of [['staff', 'staff'], ['std', 'standard']]) {
        const email = `plug_${key}_${RUN}@pinakes.test`;
        ctx[`${key}Id`] = F.createUser(email, PASS, role, `PLG${key.slice(0, 2).toUpperCase()}`);
        ctx.users.push(ctx[`${key}Id`]);
        ctx[key] = await F.login(request, email, PASS, `plug-${key}`);
    }
});

test.afterAll(async () => {
    try {
        const units = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM archival_units WHERE reference_code LIKE '${REF}%'`);
        if (units && units !== 'NULL') {
            const files = F.dbScalar(`SELECT GROUP_CONCAT(file_path SEPARATOR '|') FROM archival_unit_files WHERE unit_id IN (${units})`);
            const covers = F.dbScalar(`SELECT GROUP_CONCAT(cover_image_path SEPARATOR '|') FROM archival_units WHERE id IN (${units})`);
            for (const p of [...(files && files !== 'NULL' ? files.split('|') : []), ...(covers && covers !== 'NULL' ? covers.split('|') : [])]) {
                if (/^\/uploads\/archives\/(covers|documents)\/[^/]+$/.test(p)) { try { fs.unlinkSync(path.join(PUBLIC, p)); } catch {} }
            }
            F.dbExec(`DELETE FROM archival_unit_files WHERE unit_id IN (${units})`);
            F.dbExec(`DELETE FROM archival_unit_authority WHERE archival_unit_id IN (${units})`);
            F.dbExec(`DELETE FROM archival_units WHERE id IN (${units})`);
        }
    } catch {}
    try { F.dbExec(`DELETE FROM authority_records WHERE authorised_form LIKE '%${RUN}%'`); } catch {}
    try { F.dbExec(`DELETE FROM desiderata_offers WHERE title LIKE '%${RUN}%' OR mobile_user_id IN (${ctx.users.join(',')})`); } catch {}
    const books = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM libri WHERE titolo LIKE '%${RUN}%'`);
    if (books && books !== 'NULL') {
        try { F.dbExec(`DELETE FROM desiderata_offers WHERE book_id IN (${books}) OR received_book_id IN (${books})`); } catch {}
        F.deleteBooks(books.split(',').map(Number));
    }
    try { F.dbExec(`DELETE FROM admin_notifications WHERE message LIKE '%${RUN}%'`); } catch {}
    F.deleteUsers(ctx.users);
    F.restoreGate(ctx.gate);
});

test.describe('Desiderata', () => {
    test.beforeAll(() => { test.skip(!active('desiderata'), 'desiderata plugin inactive'); });

    const wanted = (title) => F.dbInsert(`INSERT INTO libri (titolo, is_desiderata, copie_totali, copie_disponibili, created_at, updated_at) VALUES (${F.sqlStr(`${title} ${RUN}`)}, 1, 0, 0, NOW(), NOW())`);
    const offer = (title, bookId) => F.dbInsert(`INSERT INTO desiderata_offers (book_id, donor_name, donor_email, title, notes, status) VALUES (${bookId || 'NULL'}, 'Donor ${RUN}', 'donor@example.invalid', ${F.sqlStr(`${title} ${RUN}`)}, '', 'pending')`);

    test('a borrower gets 403; the proposal from the app is listed as source=mobile', async ({ request }) => {
        for (const [m, r] of [['GET', '/staff/desiderata/offers'], ['POST', '/staff/desiderata/offers/1/accept'], ['GET', '/staff/desiderata/wanted'], ['POST', '/staff/desiderata/wanted/1/receive']]) {
            expect((await F.api(request, m, r, { token: ctx.std, body: m === 'GET' ? undefined : {} })).status, `${m} ${r}`).toBe(403);
        }
        const sub = await F.api(request, 'POST', '/desiderata/offers', {
            token: ctx.std, body: { submission_id: crypto.randomUUID(), title: `App proposal ${RUN}`, consent: true },
        });
        expect([200, 201], sub.text.slice(0, 200)).toContain(sub.status);
        ctx.appOffer = sub.json.data.id;
        const list = await F.api(request, 'GET', `/staff/desiderata/offers?q=${encodeURIComponent(RUN)}`, { token: ctx.staff });
        expect(list.status).toBe(200);
        const row = list.json.data.find((o) => o.id === ctx.appOffer);
        expect(row).toMatchObject({ status: 'pending', source: 'mobile', title: `App proposal ${RUN}`, book: null });
        expect((await F.api(request, 'GET', '/staff/desiderata/offers?status=nope', { token: ctx.staff })).status).toBe(422);
        expect((await F.api(request, 'GET', '/health')).json.data.features.staff_desiderata).toBe(true);
    });

    test('accept is repeatable, reject closes, a closed offer → 409 offer_closed', async ({ request }) => {
        const a = await F.api(request, 'POST', `/staff/desiderata/offers/${ctx.appOffer}/accept`, { token: ctx.staff });
        expect(a.status, a.text.slice(0, 200)).toBe(200);
        expect(a.json.data.status).toBe('accepted');
        expect((await F.api(request, 'POST', `/staff/desiderata/offers/${ctx.appOffer}/accept`, { token: ctx.staff })).status).toBe(200);
        const r = await F.api(request, 'POST', `/staff/desiderata/offers/${ctx.appOffer}/reject`, { token: ctx.staff });
        expect(r.json.data.status).toBe('rejected');
        const again = await F.api(request, 'POST', `/staff/desiderata/offers/${ctx.appOffer}/reject`, { token: ctx.staff });
        expect(again.status).toBe(409);
        expect(again.json.error.code).toBe('offer_closed');
        expect((await F.api(request, 'POST', `/staff/desiderata/offers/${ctx.appOffer}/receive`, { token: ctx.staff, body: {} })).status).toBe(409);
        expect((await F.api(request, 'POST', '/staff/desiderata/offers/999999999/accept', { token: ctx.staff })).status).toBe(404);
    });

    test('receive a linked proposal: copy created, request closed; direct receipt refuses while offers are open', async ({ request }) => {
        const bookId = wanted('Wanted linked');
        ctx.books.push(bookId);
        const offerId = offer('Linked proposal', bookId);
        const list = await F.api(request, 'GET', `/staff/desiderata/wanted?q=${encodeURIComponent('Wanted linked ' + RUN)}`, { token: ctx.staff });
        expect(list.status).toBe(200);
        expect(list.json.data.find((b) => b.id === bookId)?.open_offers).toBe(1);
        const direct = await F.api(request, 'POST', `/staff/desiderata/wanted/${bookId}/receive`, { token: ctx.staff });
        expect(direct.status).toBe(409);
        expect(direct.json.meta.reason).toBe('open_offers');
        const r = await F.api(request, 'POST', `/staff/desiderata/offers/${offerId}/receive`, { token: ctx.staff, body: {} });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data).toMatchObject({ status: 'received', book: { id: bookId }, received_book: { id: bookId } });
        expect(r.json.data.copy_id).toBeGreaterThan(0);
        expect(F.dbInt(`SELECT COUNT(*) FROM copie WHERE libro_id=${bookId}`)).toBe(1);
        expect(F.dbInt(`SELECT is_desiderata FROM libri WHERE id=${bookId}`)).toBe(0);
    });

    test('an unlinked proposal needs received_book_id; delete erases it', async ({ request }) => {
        const offerId = offer('Unlinked proposal');
        const missing = await F.api(request, 'POST', `/staff/desiderata/offers/${offerId}/receive`, { token: ctx.staff, body: {} });
        expect(missing.status).toBe(422);
        expect(missing.json.meta.field).toBe('received_book_id');
        expect((await F.api(request, 'POST', `/staff/desiderata/offers/${offerId}/receive`, { token: ctx.staff, body: { received_book_id: 999999999 } })).status).toBe(404);
        const target = wanted('Receiving record');
        ctx.books.push(target);
        const ok = await F.api(request, 'POST', `/staff/desiderata/offers/${offerId}/receive`, { token: ctx.staff, body: { received_book_id: target } });
        expect(ok.status, ok.text.slice(0, 300)).toBe(200);
        expect(ok.json.data.received_book.id).toBe(target);
        const d = await F.api(request, 'DELETE', `/staff/desiderata/offers/${offerId}`, { token: ctx.staff });
        expect(d.status).toBe(200);
        expect(d.json.data).toEqual({ id: offerId, deleted: true });
        expect((await F.api(request, 'DELETE', `/staff/desiderata/offers/${offerId}`, { token: ctx.staff })).status).toBe(404);
    });

    test('direct receipt of a requested title; a second one → 409 not_wanted', async ({ request }) => {
        const bookId = wanted('Wanted direct');
        ctx.books.push(bookId);
        const r = await F.api(request, 'POST', `/staff/desiderata/wanted/${bookId}/receive`, { token: ctx.staff });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data.book_id).toBe(bookId);
        expect(r.json.data.copy_id).toBeGreaterThan(0);
        expect(r.json.data.offer_id).toBeGreaterThan(0);
        const again = await F.api(request, 'POST', `/staff/desiderata/wanted/${bookId}/receive`, { token: ctx.staff });
        expect(again.status).toBe(409);
        expect(again.json.meta.reason).toBe('not_wanted');
    });
});

test.describe('Archives', () => {
    test.beforeAll(() => { test.skip(!active('archives'), 'archives plugin inactive'); });

    test('lookups (ETag) and a borrower gets 403', async ({ request }) => {
        const l = await F.api(request, 'GET', '/staff/archives/lookups', { token: ctx.staff });
        expect(l.status).toBe(200);
        expect(l.json.data.levels.map((x) => x.value)).toEqual(['fonds', 'series', 'file', 'item']);
        expect(l.json.data.authority_roles.map((x) => x.value)).toContain('creator');
        expect((await F.api(request, 'GET', '/staff/archives/lookups', { token: ctx.staff, extraHeaders: { 'If-None-Match': l.headers.etag } })).status).toBe(304);
        for (const [m, r] of [['GET', '/staff/archives'], ['POST', '/staff/archives'], ['GET', '/staff/archives/authorities'], ['DELETE', '/staff/archives/1']]) {
            expect((await F.api(request, m, r, { token: ctx.std, body: m === 'GET' ? undefined : {} })).status, `${m} ${r}`).toBe(403);
        }
    });

    test('validation comes from the Archives plugin: 422 with the field', async ({ request }) => {
        const cases = [
            [{ level: 'fonds', constructed_title: 'X' }, 'reference_code'],
            [{ reference_code: `${REF}-v`, level: 'shelf', constructed_title: 'X' }, 'level'],
            [{ reference_code: `${REF}-v`, level: 'fonds' }, 'constructed_title'],
            [{ reference_code: `${REF}-v`, level: 'fonds', constructed_title: 'X', date_start: 1900, date_end: 1800 }, 'date_end'],
            [{ reference_code: `${REF}-v`, level: 'fonds', constructed_title: 'X', parent_id: 999999999 }, 'parent_id'],
        ];
        for (const [body, field] of cases) {
            const r = await F.api(request, 'POST', '/staff/archives', { token: ctx.staff, body });
            expect(r.status, `${field}: ${r.text.slice(0, 200)}`).toBe(422);
            expect(r.json.meta.field).toBe(field);
        }
        expect(F.dbInt(`SELECT COUNT(*) FROM archival_units WHERE reference_code LIKE '${REF}%'`)).toBe(0);
    });

    test('create unpublished → hidden from the public list; publish → visible; duplicate → 409; partial PUT', async ({ request }) => {
        const r = await F.api(request, 'POST', '/staff/archives', {
            token: ctx.staff, body: { reference_code: `${REF}-1`, level: 'fonds', constructed_title: `Fondo ${RUN}`, date_start: 1901, published: false, extent: '3 boxes' },
        });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        ctx.unit = r.json.data.id;
        expect(r.json.data).toMatchObject({ reference_code: `${REF}-1`, level: 'fonds', published: false });
        expect(r.json.data.fields.extent).toBe('3 boxes');
        expect((await F.api(request, 'GET', `/archives/${ctx.unit}`, { token: ctx.std })).status).toBe(404);
        const hidden = await F.api(request, 'GET', `/staff/archives?published=0&q=${encodeURIComponent(REF)}`, { token: ctx.staff });
        expect(hidden.json.data.map((u) => u.id)).toContain(ctx.unit);
        expect(hidden.json.data.find((u) => u.id === ctx.unit).published).toBe(false);

        const dup = await F.api(request, 'POST', '/staff/archives', { token: ctx.staff, body: { reference_code: `${REF}-1`, level: 'series', constructed_title: 'Other' } });
        expect(dup.status).toBe(409);
        expect(dup.json.meta.existing).toEqual({ id: ctx.unit, reference_code: `${REF}-1` });

        const p = await F.api(request, 'PUT', `/staff/archives/${ctx.unit}`, { token: ctx.staff, body: { published: true, scope_content: 'Letters' } });
        expect(p.status, p.text.slice(0, 300)).toBe(200);
        expect(p.json.data).toMatchObject({ published: true, title: `Fondo ${RUN}` });
        expect(p.json.data.fields).toMatchObject({ extent: '3 boxes', scope_content: 'Letters', date_start: 1901 });
        expect((await F.api(request, 'GET', `/archives/${ctx.unit}`, { token: ctx.std })).status).toBe(200);
        const self = await F.api(request, 'PUT', `/staff/archives/${ctx.unit}`, { token: ctx.staff, body: { parent_id: ctx.unit } });
        expect(self.status).toBe(422);
        expect(self.json.meta.field).toBe('parent_id');
    });

    test('cover and document upload, a rejected MIME, removal', async ({ request }) => {
        const c = await F.api(request, 'POST', `/staff/archives/${ctx.unit}/cover`, { token: ctx.staff, multipart: { cover: { name: 'c.png', mimeType: 'image/png', buffer: PNG_1PX } } });
        expect(c.status, c.text.slice(0, 300)).toBe(200);
        expect(c.json.data.cover_url).toMatch(/\/uploads\/archives\/covers\//);
        const bad = await F.api(request, 'POST', `/staff/archives/${ctx.unit}/cover`, { token: ctx.staff, multipart: { cover: { name: 'c.png', mimeType: 'image/png', buffer: Buffer.from('not an image') } } });
        expect(bad.status).toBe(422);
        expect(bad.json.error.code).toBe('upload_rejected');
        const d = await F.api(request, 'POST', `/staff/archives/${ctx.unit}/documents`, { token: ctx.staff, multipart: { document: { name: `doc_${RUN}.pdf`, mimeType: 'application/pdf', buffer: PDF } } });
        expect(d.status, d.text.slice(0, 300)).toBe(201);
        expect(d.json.data.files.length).toBe(1);
        const fileId = d.json.data.files[0].id;
        expect(d.json.data.files[0].mime).toBe('application/pdf');
        const badDoc = await F.api(request, 'POST', `/staff/archives/${ctx.unit}/documents`, { token: ctx.staff, multipart: { document: { name: 'x.exe', mimeType: 'application/octet-stream', buffer: Buffer.from('MZ binary') } } });
        expect(badDoc.status).toBe(422);
        const rm = await F.api(request, 'DELETE', `/staff/archives/${ctx.unit}/documents/${fileId}`, { token: ctx.staff });
        expect(rm.status).toBe(200);
        expect(rm.json.data.files).toEqual([]);
        expect((await F.api(request, 'DELETE', `/staff/archives/${ctx.unit}/documents/${fileId}`, { token: ctx.staff })).status).toBe(404);
        const rc = await F.api(request, 'DELETE', `/staff/archives/${ctx.unit}/cover`, { token: ctx.staff });
        expect(rc.status).toBe(200);
        expect(rc.json.data.cover_url).toBeNull();
    });

    test('authority records: validation, CRUD, attach / detach', async ({ request }) => {
        const bad = await F.api(request, 'POST', '/staff/archives/authorities', { token: ctx.staff, body: { type: 'person' } });
        expect(bad.status).toBe(422);
        expect(bad.json.meta.field).toBe('authorised_form');
        const a = await F.api(request, 'POST', '/staff/archives/authorities', { token: ctx.staff, body: { type: 'person', authorised_form: `Rossi, Maria ${RUN}`, dates_of_existence: '1900-1980' } });
        expect(a.status, a.text.slice(0, 300)).toBe(201);
        ctx.authority = a.json.data.id;
        expect(a.json.data).toMatchObject({ type: 'person', authorised_form: `Rossi, Maria ${RUN}`, units: [], authors: [] });
        const u = await F.api(request, 'PUT', `/staff/archives/authorities/${ctx.authority}`, { token: ctx.staff, body: { history: 'Teacher' } });
        expect(u.status).toBe(200);
        expect(u.json.data).toMatchObject({ history: 'Teacher', dates_of_existence: '1900-1980' });
        const list = await F.api(request, 'GET', `/staff/archives/authorities?type=person&q=${encodeURIComponent(RUN)}`, { token: ctx.staff });
        expect(list.json.data.map((x) => x.id)).toEqual([ctx.authority]);

        expect((await F.api(request, 'POST', `/staff/archives/${ctx.unit}/authorities`, { token: ctx.staff, body: { authority_id: ctx.authority, role: 'pope' } })).status).toBe(422);
        const at = await F.api(request, 'POST', `/staff/archives/${ctx.unit}/authorities`, { token: ctx.staff, body: { authority_id: ctx.authority, role: 'creator' } });
        expect(at.status).toBe(200);
        expect(at.json.data.authorities).toEqual([{ id: ctx.authority, type: 'person', name: `Rossi, Maria ${RUN}`, role: 'creator' }]);
        expect((await F.api(request, 'GET', `/staff/archives/authorities/${ctx.authority}`, { token: ctx.staff })).json.data.units)
            .toEqual([{ id: ctx.unit, title: `Fondo ${RUN}`, role: 'creator' }]);
        const de = await F.api(request, 'DELETE', `/staff/archives/${ctx.unit}/authorities/${ctx.authority}`, { token: ctx.staff });
        expect(de.json.data.authorities).toEqual([]);
    });

    test('delete unit and authority; a second delete → 404', async ({ request }) => {
        const d = await F.api(request, 'DELETE', `/staff/archives/${ctx.unit}`, { token: ctx.staff });
        expect(d.status).toBe(200);
        expect(d.json.data).toEqual({ id: ctx.unit, deleted: true });
        expect((await F.api(request, 'DELETE', `/staff/archives/${ctx.unit}`, { token: ctx.staff })).status).toBe(404);
        expect((await F.api(request, 'GET', `/staff/archives/${ctx.unit}`, { token: ctx.staff })).status).toBe(404);
        const a = await F.api(request, 'DELETE', `/staff/archives/authorities/${ctx.authority}`, { token: ctx.staff });
        expect(a.status).toBe(200);
        expect((await F.api(request, 'DELETE', `/staff/archives/authorities/${ctx.authority}`, { token: ctx.staff })).status).toBe(404);
    });
});
