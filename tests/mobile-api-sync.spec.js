// @ts-check
// Mobile API 1.6.0 — GET /api/v1/catalog/sync (offline catalogue delta feed).
//
// Contract under test:
//   - no `since` (or one older than 30 days / unparsable) → full_resync: true,
//     every visible title, no tombstones;
//   - `since` = the previous server_time → only what changed after it:
//     edits as `upsert`, soft deletes and de-listings as `delete`;
//   - a wanted title that never reached the catalogue is never announced;
//   - pages of one pass share the watermark carried in the cursor, keyset
//     order (changed_at, id) with no duplicates;
//   - any authenticated user may call it (no staff guard).
//
// Run: /tmp/run-e2e.sh tests/mobile-api-sync.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Sync1234Test!';
/** @type {Record<string, any>} */
const ctx = { books: [] };

const hasWanted = () => F.dbRows("SHOW COLUMNS FROM libri LIKE 'is_desiderata'").length === 1;
const hasCatalogued = () => F.dbRows("SHOW COLUMNS FROM libri LIKE 'catalogued_at'").length === 1;

function insertBook(title) {
    const id = F.dbInsert(`INSERT INTO libri (titolo, descrizione, numero_pagine, copie_totali, copie_disponibili, created_at, updated_at)
                           VALUES (${F.sqlStr(title)}, '<p>Prima riga &amp; seconda</p>${'x'.repeat(2500)}', 120, 0, 0, NOW(), NOW())`);
    ctx.books.push(id);
    return id;
}

/** Walk every page of one pass. */
async function pass(request, query) {
    const items = [];
    let cursor = null;
    let first = null;
    for (let guard = 0; guard < 500; guard++) {
        const q = new URLSearchParams(query);
        if (cursor) q.set('cursor', cursor);
        const r = await F.api(request, 'GET', `/catalog/sync?${q.toString()}`, { token: ctx.token });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        if (first === null) first = r.json.data;
        expect(r.json.data.server_time, 'one watermark for the whole pass').toBe(first.server_time);
        expect(r.json.data.full_resync).toBe(first.full_resync);
        items.push(...r.json.data.items);
        if (!r.json.meta.has_more) break;
        cursor = r.json.meta.next_cursor;
        expect(cursor).toBeTruthy();
    }
    return { items, server_time: first.server_time, full_resync: first.full_resync };
}

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    ctx.email = `sync_${RUN}@pinakes.test`;
    ctx.userId = F.createUser(ctx.email, PASS, 'standard', 'SYNC');
    ctx.token = await F.login(request, ctx.email, PASS, 'sync-dev');

    ctx.live = insertBook(`Sync vivo ${RUN}`);
    ctx.toDelete = insertBook(`Sync da eliminare ${RUN}`);
    ctx.toDelist = insertBook(`Sync da ritirare ${RUN}`);
    if (hasWanted()) {
        ctx.wantedNever = insertBook(`Sync mai catalogato ${RUN}`);
        F.dbExec(`UPDATE libri SET is_desiderata=1${hasCatalogued() ? ', catalogued_at=NULL' : ''} WHERE id=${ctx.wantedNever}`);
    }
    if (hasCatalogued()) {
        F.dbExec(`UPDATE libri SET catalogued_at=NOW() WHERE id IN (${ctx.live}, ${ctx.toDelete}, ${ctx.toDelist})`);
    }
    // The feed looks back 5 s behind `since` (late commits), so seed more
    // than that before the first pass's watermark.
    await F.sleep(6500);
});

test.afterAll(async () => {
    F.deleteBooks(ctx.books);
    F.deleteUsers([ctx.userId]);
    F.restoreGate(ctx.gate);
});

test('first pass without since is a full resync that includes the seeded titles', async ({ request }) => {
    const p = await pass(request, { limit: '200' });
    expect(p.full_resync).toBe(true);
    expect(p.server_time).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    const ids = p.items.map((i) => i.id);
    expect(new Set(ids).size, 'no duplicates').toBe(ids.length);
    expect(p.items.every((i) => i.change === 'upsert'), 'a full resync carries no tombstones').toBe(true);
    const live = p.items.find((i) => i.id === ctx.live);
    expect(live, 'seeded live title present').toBeTruthy();
    expect(live.book).toMatchObject({ id: ctx.live, title: `Sync vivo ${RUN}`, pages: 120, isbn10: null, ean: null, is_wanted: false, copies_total: 0 });
    expect(live.book.description.startsWith('Prima riga & seconda')).toBe(true);
    expect(live.book.description.length).toBeLessThanOrEqual(2000);
    expect(live.book.cover_url).toMatch(/^https?:\/\//);
    if (ctx.wantedNever) expect(ids).not.toContain(ctx.wantedNever);
    const visible = F.dbInt(`SELECT COUNT(*) FROM libri WHERE deleted_at IS NULL${hasWanted() ? ' AND is_desiderata = 0' : ''}`);
    expect(p.items.length, 'every visible title, once').toBe(visible);
    ctx.watermark = p.server_time;
});

test('since = server_time returns nothing for untouched titles', async ({ request }) => {
    const p = await pass(request, { since: ctx.watermark });
    expect(p.full_resync).toBe(false);
    const mine = p.items.filter((i) => ctx.books.includes(i.id));
    expect(mine).toEqual([]);
});

test('edit → upsert, soft delete → delete, de-listing → delete, never-catalogued request → silent', async ({ request }) => {
    await F.sleep(1100);
    F.dbExec(`UPDATE libri SET titolo=${F.sqlStr(`Sync vivo modificato ${RUN}`)} WHERE id=${ctx.live}`);
    F.dbExec(`UPDATE libri SET deleted_at=NOW(), isbn10=NULL, isbn13=NULL, ean=NULL WHERE id=${ctx.toDelete}`);
    if (hasWanted() && hasCatalogued()) {
        F.dbExec(`UPDATE libri SET is_desiderata=1 WHERE id=${ctx.toDelist}`);
    }
    if (ctx.wantedNever) {
        F.dbExec(`UPDATE libri SET deleted_at=NOW() WHERE id=${ctx.wantedNever}`);
    }

    const p = await pass(request, { since: ctx.watermark });
    expect(p.full_resync).toBe(false);
    const byId = Object.fromEntries(p.items.map((i) => [i.id, i]));
    expect(byId[ctx.live]?.change).toBe('upsert');
    expect(byId[ctx.live].book.title).toBe(`Sync vivo modificato ${RUN}`);
    expect(byId[ctx.toDelete]).toMatchObject({ change: 'delete' });
    expect(byId[ctx.toDelete].book).toBeUndefined();
    if (hasWanted() && hasCatalogued()) {
        expect(byId[ctx.toDelist], 'a withdrawn title is a deletion for the app').toMatchObject({ change: 'delete' });
    }
    if (ctx.wantedNever) {
        expect(byId[ctx.wantedNever], 'a request nobody was given is never announced').toBeUndefined();
    }
    // Keyset order of the pass.
    const keys = p.items.map((i) => `${i.changed_at}#${String(i.id).padStart(10, '0')}`);
    expect(keys).toEqual([...keys].sort());
    ctx.watermark2 = p.server_time;
});

test('limit=1 pages share the watermark and never repeat or skip a title', async ({ request }) => {
    const paged = await pass(request, { limit: '1' });
    const whole = await pass(request, { limit: '200' });
    expect(paged.full_resync).toBe(true);
    expect(paged.items.map((i) => i.id)).toEqual(whole.items.map((i) => i.id));
    const r = await F.api(request, 'GET', '/catalog/sync?limit=1', { token: ctx.token });
    expect(r.json.meta).toMatchObject({ count: 1, has_more: true });
    expect(typeof r.json.meta.next_cursor).toBe('string');
});

test('since older than 30 days or unparsable → full resync', async ({ request }) => {
    const old = F.dbScalar("SELECT DATE_FORMAT(NOW() - INTERVAL 40 DAY, '%Y-%m-%d %H:%i:%s')");
    for (const since of [old, 'yesterday', '2026-13-45 99:00:00']) {
        const r = await F.api(request, 'GET', `/catalog/sync?limit=5&since=${encodeURIComponent(since)}`, { token: ctx.token });
        expect(r.status).toBe(200);
        expect(r.json.data.full_resync, since).toBe(true);
        expect(r.json.data.items.every((i) => i.change === 'upsert')).toBe(true);
    }
    expect((await F.api(request, 'GET', '/catalog/sync')).status, 'token required').toBe(401);
    expect((await F.api(request, 'GET', '/health')).json.data.features.catalog_sync).toBe(true);
});

test('author and publisher renames reach the next delta, though only the linked rows changed', async ({ request }) => {
    const staffEmail = `sync_staff_${RUN}@pinakes.test`;
    ctx.staffUser = F.createUser(staffEmail, PASS, 'staff', 'SYNCST');
    const staff = await F.login(request, staffEmail, PASS, 'sync-staff');
    try {
        const created = await F.api(request, 'POST', '/staff/books', { token: staff, body: {
            title: `Sync rinomina ${RUN}`, authors_new: [`Autrice ${RUN}`], publishers_new: [`Casa ${RUN}`],
        } });
        expect(created.status, created.text.slice(0, 300)).toBe(201);
        const book = created.json.data;
        ctx.books.push(book.id);
        const authorId = book.authors[0].id;
        const publisherId = book.publishers[0].id;

        // Let the book's own write fall out of the look-back window.
        await F.sleep(6500);
        const base = await pass(request, { since: F.dbScalar("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')") });
        expect(base.items.find((i) => i.id === book.id), 'untouched before the rename').toBeUndefined();

        const ra = await F.api(request, 'PUT', `/staff/authors/${authorId}`, { token: staff, body: { name: `Autrice rinominata ${RUN}` } });
        expect(ra.status, ra.text.slice(0, 300)).toBe(200);
        const afterAuthor = await pass(request, { since: base.server_time });
        const hitA = afterAuthor.items.find((i) => i.id === book.id);
        expect(hitA?.change).toBe('upsert');
        expect(hitA.book.author).toContain(`Autrice rinominata ${RUN}`);

        await F.sleep(6500);
        const base2 = await pass(request, { since: F.dbScalar("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')") });
        expect(base2.items.find((i) => i.id === book.id)).toBeUndefined();
        const rp = await F.api(request, 'PUT', `/staff/publishers/${publisherId}`, { token: staff, body: { name: `Casa rinominata ${RUN}` } });
        expect(rp.status, rp.text.slice(0, 300)).toBe(200);
        const afterPublisher = await pass(request, { since: base2.server_time });
        const hitP = afterPublisher.items.find((i) => i.id === book.id);
        expect(hitP?.change).toBe('upsert');
        expect(hitP.book.publisher).toBe(`Casa rinominata ${RUN}`);
    } finally {
        try { F.dbExec(`DELETE FROM libri_autori WHERE autore_id IN (SELECT id FROM (SELECT id FROM autori WHERE nome LIKE '%${RUN}%') x)`); } catch {}
        try { F.dbExec(`DELETE FROM autori WHERE nome LIKE '%${RUN}%'`); } catch {}
        try { F.dbExec(`DELETE FROM libri_editori WHERE editore_id IN (SELECT id FROM (SELECT id FROM editori WHERE nome LIKE '%${RUN}%') x)`); } catch {}
        try { F.dbExec(`UPDATE libri SET editore_id = NULL WHERE editore_id IN (SELECT id FROM (SELECT id FROM editori WHERE nome LIKE '%${RUN}%') x)`); } catch {}
        try { F.dbExec(`DELETE FROM editori WHERE nome LIKE '%${RUN}%'`); } catch {}
        F.deleteUsers([ctx.staffUser]);
    }
});
