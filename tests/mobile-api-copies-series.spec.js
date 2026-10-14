// @ts-check
// Mobile API 1.7.0 — staff content tools: per-copy management, series,
// duplicate merges, label PDFs and label settings.
//
// What this proves, end to end against the real Apache instance:
//   - a copy with an explicit inventory code (409 duplicate with
//     meta.existing), status/notes edits, the refusals with stable codes
//     (copy_on_loan, copy_held, copy_in_circulation, copy_has_history) and the
//     "available again" edit that records the return of the open loan;
//   - series by id: create (parent auto-created by name), tree, detail, PUT
//     merged over the row, rename (409 then merge_if_exists), assign, order,
//     remove, parent work (and its empty-series refusal), merge, delete counts;
//   - author duplicate groups and author / publisher / genre merges (with
//     the descendant refusal);
//   - label PDFs (409 no_copies) and the label settings round trip.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-copies-series.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Copy1234Test!';
/** @type {Record<string, any>} */
const ctx = { books: [], users: [] };

async function staffBook(request, title, copies) {
    const r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `${title} ${RUN}`, ...(copies > 0 ? { copies } : {}) } });
    expect(r.status, r.text.slice(0, 300)).toBe(201);
    ctx.books.push(r.json.data.id);
    return r.json.data.id;
}
const copiesOf = (bookId) => F.dbRows(`SELECT id, numero_inventario, stato FROM copie WHERE libro_id=${bookId} ORDER BY id`);

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();
    ctx.staffEmail = `cs_op_${RUN}@pinakes.test`;
    ctx.staffId = F.createUser(ctx.staffEmail, PASS, 'staff', 'CSOP');
    ctx.users.push(ctx.staffId);
    ctx.staff = await F.login(request, ctx.staffEmail, PASS, 'cs-op');
    const borrowerEmail = `cs_b_${RUN}@pinakes.test`;
    ctx.borrowerId = F.createUser(borrowerEmail, PASS, 'standard', 'CSBR');
    ctx.users.push(ctx.borrowerId);
    ctx.borrower = await F.login(request, borrowerEmail, PASS, 'cs-b');
    ctx.labels = (await F.api(request, 'GET', '/staff/settings/labels', { token: ctx.staff })).json.data;
    ctx.today = (await F.api(request, 'GET', '/staff/circulation/summary', { token: ctx.staff })).json.data.today;
});

test.afterAll(async ({ request }) => {
    if (ctx.labels) {
        await F.api(request, 'PUT', '/staff/settings/labels', { token: ctx.staff, body: ctx.labels });
    }
    const stray = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM libri WHERE titolo LIKE '%${RUN}%'`);
    F.deleteBooks([...ctx.books, ...(stray && stray !== 'NULL' ? stray.split(',').map(Number) : [])]);
    try { F.dbExec(`DELETE v FROM volumi v LEFT JOIN libri l ON l.id = v.opera_id WHERE l.id IS NULL`); } catch {}
    try { F.dbExec(`DELETE FROM collane WHERE nome LIKE '%${RUN}%' AND parent_id IS NOT NULL`); } catch {}
    try { F.dbExec(`DELETE FROM collane WHERE nome LIKE '%${RUN}%'`); } catch {}
    for (const table of ['autori', 'editori']) {
        const link = table === 'autori' ? ['libri_autori', 'autore_id'] : ['libri_editori', 'editore_id'];
        const ids = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM ${table} WHERE nome LIKE '%${RUN}%'`);
        if (ids && ids !== 'NULL') {
            try { F.dbExec(`DELETE FROM ${link[0]} WHERE ${link[1]} IN (${ids})`); } catch {}
            try { F.dbExec(`DELETE FROM ${table} WHERE id IN (${ids})`); } catch {}
        }
    }
    try { F.dbExec(`DELETE FROM generi WHERE nome LIKE '%${RUN}%' AND parent_id IS NOT NULL`); } catch {}
    try { F.dbExec(`DELETE FROM generi WHERE nome LIKE '%${RUN}%'`); } catch {}
    try { F.dbExec(`DELETE FROM admin_notifications WHERE message LIKE '%${RUN}%'`); } catch {}
    F.deleteUsers(ctx.users);
    F.restoreGate(ctx.gate);
});

const ROUTES = [
    ['GET', '/staff/copies/1'], ['PUT', '/staff/copies/1'], ['DELETE', '/staff/copies/1'],
    ['GET', '/staff/series'], ['POST', '/staff/series'], ['GET', '/staff/series/suggest?q=ab'], ['GET', '/staff/series/1'],
    ['PUT', '/staff/series/1'], ['DELETE', '/staff/series/1'], ['POST', '/staff/series/1/rename'], ['POST', '/staff/series/1/merge'],
    ['POST', '/staff/series/1/books'], ['PUT', '/staff/series/1/books/1'], ['DELETE', '/staff/series/1/books/1'],
    ['POST', '/staff/series/1/parent-work'], ['GET', '/staff/authors/duplicates'], ['POST', '/staff/authors/merge'],
    ['POST', '/staff/publishers/merge'], ['POST', '/staff/genres/1/merge'], ['GET', '/staff/books/1/labels/book.pdf'],
    ['GET', '/staff/books/1/labels/copies.pdf'], ['GET', '/staff/settings/labels'], ['PUT', '/staff/settings/labels'],
];

test('a borrower gets 403 on every content-tool route; health advertises the areas', async ({ request }) => {
    for (const [method, route] of ROUTES) {
        const r = await F.api(request, method, route, { token: ctx.borrower, body: method === 'GET' ? undefined : { name: `X ${RUN}`, ids: [1, 2], target_id: 2 } });
        expect(r.status, `${method} ${route}`).toBe(403);
    }
    const f = (await F.api(request, 'GET', '/health')).json.data.features;
    expect(f.staff_copies).toBe(true);
    expect(f.staff_merges).toBe(true);
    expect(f.staff_labels).toBe(true);
    expect(typeof f.staff_series).toBe('boolean');
    const lookups = (await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff })).json.data;
    expect(lookups.copy_states.find((s) => s.value === 'manutenzione').editable).toBe(true);
    expect(lookups.copy_states.find((s) => s.value === 'prestato').editable).toBe(false);
    expect(lookups.series_types.map((t) => t.value)).toEqual(expect.arrayContaining(['serie', 'universo']));
    expect(lookups.return_outcomes.map((t) => t.value)).toEqual(['restituito', 'manutenzione', 'in_restauro', 'perso', 'danneggiato']);
});

test.describe('Copies', () => {
    test('add a copy with an explicit code; a taken code → 409 with meta.existing', async ({ request }) => {
        ctx.bookCopies = await staffBook(request, 'Copies main', 1);
        const code = `INV-${RUN}`;
        const r = await F.api(request, 'POST', `/staff/books/${ctx.bookCopies}/copies`, { token: ctx.staff, body: { quantity: 1, inventory_number: ` ${code}\u0007 `, notes: 'Shelf 3' } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data.created).toEqual([{ id: expect.any(Number), inventory_number: code }]);
        expect(r.json.data.copies_total).toBe(2);
        ctx.codedCopy = r.json.data.created[0].id;
        const dup = await F.api(request, 'POST', `/staff/books/${ctx.bookCopies}/copies`, { token: ctx.staff, body: { quantity: 1, inventory_number: code } });
        expect(dup.status).toBe(409);
        expect(dup.json.error.code).toBe('duplicate');
        expect(dup.json.meta.existing).toEqual({ id: ctx.codedCopy, inventory_number: code, book_id: ctx.bookCopies });
        const many = await F.api(request, 'POST', `/staff/books/${ctx.bookCopies}/copies`, { token: ctx.staff, body: { quantity: 2, inventory_number: `X-${RUN}` } });
        expect(many.status).toBe(422);
        expect(many.json.meta.field).toBe('inventory_number');
        expect(copiesOf(ctx.bookCopies).length).toBe(2);
    });

    test('GET and PUT a copy: status + notes; loan states are refused', async ({ request }) => {
        const g = await F.api(request, 'GET', `/staff/copies/${ctx.codedCopy}`, { token: ctx.staff });
        expect(g.status).toBe(200);
        expect(g.json.data).toMatchObject({ id: ctx.codedCopy, status: 'disponibile', notes: 'Shelf 3', held: false, deletable: false, on_loan: null });
        expect(g.json.data.book.id).toBe(ctx.bookCopies);
        const u = await F.api(request, 'PUT', `/staff/copies/${ctx.codedCopy}`, { token: ctx.staff, body: { status: 'manutenzione' } });
        expect(u.status, u.text.slice(0, 300)).toBe(200);
        expect(u.json.data.status).toBe('manutenzione');
        expect(u.json.data.notes).toBe('Shelf 3');
        expect(u.json.data.deletable).toBe(true);
        const n = await F.api(request, 'PUT', `/staff/copies/${ctx.codedCopy}`, { token: ctx.staff, body: { notes: 'Rebound' } });
        expect(n.json.data).toMatchObject({ status: 'manutenzione', notes: 'Rebound' });
        for (const status of ['prestato', 'prenotato', 'rubato']) {
            const bad = await F.api(request, 'PUT', `/staff/copies/${ctx.codedCopy}`, { token: ctx.staff, body: { status } });
            expect(bad.status, status).toBe(422);
        }
        expect((await F.api(request, 'GET', '/staff/copies/999999999', { token: ctx.staff })).status).toBe(404);
    });

    test('a copy out on loan: edits → 409 copy_on_loan, "available" records the return', async ({ request }) => {
        const otherCode = copiesOf(ctx.bookCopies).find((c) => c.id !== String(ctx.codedCopy)).numero_inventario;
        const loan = await F.api(request, 'POST', '/staff/loans', { token: ctx.staff, body: { user_id: ctx.borrowerId, book_id: ctx.bookCopies, copy_code: otherCode, immediate: true } });
        expect(loan.status, loan.text.slice(0, 300)).toBe(201);
        const copyId = loan.json.data.copy.id;
        const out = await F.api(request, 'GET', `/staff/copies/${copyId}`, { token: ctx.staff });
        expect(out.json.data).toMatchObject({ status: 'prestato', held: true, deletable: false });
        expect(out.json.data.on_loan.loan_id).toBe(loan.json.data.id);
        const refused = await F.api(request, 'PUT', `/staff/copies/${copyId}`, { token: ctx.staff, body: { status: 'perso' } });
        expect(refused.status).toBe(409);
        expect(refused.json.error.code).toBe('copy_on_loan');
        const del = await F.api(request, 'DELETE', `/staff/copies/${copyId}`, { token: ctx.staff });
        expect(del.status).toBe(409);
        expect(del.json.error.code).toBe('copy_held');
        const back = await F.api(request, 'PUT', `/staff/copies/${copyId}`, { token: ctx.staff, body: { status: 'disponibile' } });
        expect(back.status, back.text.slice(0, 300)).toBe(200);
        expect(back.json.data.status).toBe('disponibile');
        expect(F.dbScalar(`SELECT stato FROM prestiti WHERE id=${loan.json.data.id}`)).toBe('restituito');
        ctx.returnedCopy = copyId;
    });

    test('delete rules: in circulation → 409, with loan history → 409, a clean out-of-circulation copy → 200 then 404', async ({ request }) => {
        const inCirc = await F.api(request, 'DELETE', `/staff/copies/${ctx.returnedCopy}`, { token: ctx.staff });
        expect(inCirc.status).toBe(409);
        expect(inCirc.json.error.code).toBe('copy_in_circulation');
        expect(inCirc.json.meta.reason).toBe('copy_in_circulation');
        await F.api(request, 'PUT', `/staff/copies/${ctx.returnedCopy}`, { token: ctx.staff, body: { status: 'perso' } });
        const history = await F.api(request, 'DELETE', `/staff/copies/${ctx.returnedCopy}`, { token: ctx.staff });
        expect(history.status).toBe(409);
        expect(history.json.error.code).toBe('copy_has_history');
        const ok = await F.api(request, 'DELETE', `/staff/copies/${ctx.codedCopy}`, { token: ctx.staff });
        expect(ok.status, ok.text.slice(0, 300)).toBe(200);
        expect(ok.json.data).toEqual({ id: ctx.codedCopy, deleted: true });
        expect((await F.api(request, 'DELETE', `/staff/copies/${ctx.codedCopy}`, { token: ctx.staff })).status).toBe(404);
    });
});

test.describe('Labels', () => {
    test('book and copy label PDFs; a title without copies → 409 no_copies', async ({ request }) => {
        const book = await F.api(request, 'GET', `/staff/books/${ctx.bookCopies}/labels/book.pdf`, { token: ctx.staff });
        expect(book.status).toBe(200);
        expect(book.headers['content-type']).toContain('application/pdf');
        expect(book.text.startsWith('%PDF-')).toBe(true);
        const copies = await F.api(request, 'GET', `/staff/books/${ctx.bookCopies}/labels/copies.pdf`, { token: ctx.staff });
        expect(copies.status).toBe(200);
        expect(copies.text.startsWith('%PDF-')).toBe(true);
        const one = await F.api(request, 'GET', `/staff/books/${ctx.bookCopies}/labels/copies.pdf?copy_id=${ctx.returnedCopy}`, { token: ctx.staff });
        expect(one.status).toBe(200);
        expect((await F.api(request, 'GET', `/staff/books/${ctx.bookCopies}/labels/copies.pdf?copy_id=999999999`, { token: ctx.staff })).status).toBe(404);
        const empty = await staffBook(request, 'Labels empty', 0);
        // A title created from the app gets one copy by default: take it away.
        F.dbExec(`DELETE FROM copie WHERE libro_id=${empty}`);
        const none = await F.api(request, 'GET', `/staff/books/${empty}/labels/copies.pdf`, { token: ctx.staff });
        expect(none.status).toBe(409);
        expect(none.json.error.code).toBe('no_copies');
        expect((await F.api(request, 'GET', '/staff/books/999999999/labels/book.pdf', { token: ctx.staff })).status).toBe(404);
    });

    test('label settings: partial PUT keeps the rest, out-of-range → 422, ETag', async ({ request }) => {
        const r = await F.api(request, 'PUT', '/staff/settings/labels', { token: ctx.staff, body: { width: 50, height: 30, show_dewey: false } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data).toMatchObject({ width: 50, height: 30, show_dewey: false, show_title: ctx.labels.show_title });
        const bad = await F.api(request, 'PUT', '/staff/settings/labels', { token: ctx.staff, body: { width: 5 } });
        expect(bad.status).toBe(422);
        expect(bad.json.meta.field).toBe('width');
        const g = await F.api(request, 'GET', '/staff/settings/labels', { token: ctx.staff });
        expect(g.json.data.width).toBe(50);
        expect((await F.api(request, 'GET', '/staff/settings/labels', { token: ctx.staff, extraHeaders: { 'If-None-Match': g.headers.etag } })).status).toBe(304);
    });
});

test.describe('Series', () => {
    test('create with a parent by name, duplicate 409, tree and detail', async ({ request }) => {
        const r = await F.api(request, 'POST', '/staff/series', {
            token: ctx.staff,
            body: { name: `Saga ${RUN}`, type: 'ciclo', parent_name: `Universe ${RUN}`, group: 'G1', cycle: 'C1', cycle_order: 2, description: 'Desc', complete: true },
        });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        ctx.saga = r.json.data.id;
        expect(r.json.data).toMatchObject({ name: `Saga ${RUN}`, type: 'ciclo', parent_name: `Universe ${RUN}`, group: 'G1', cycle: 'C1', cycle_order: 2, complete: true, description: 'Desc', books: [] });
        ctx.universe = r.json.data.parent_id;
        expect(ctx.universe).toBeGreaterThan(0);
        const dup = await F.api(request, 'POST', '/staff/series', { token: ctx.staff, body: { name: `Saga ${RUN}` } });
        expect(dup.status).toBe(409);
        expect(dup.json.meta.existing).toEqual({ id: ctx.saga, name: `Saga ${RUN}` });
        const tree = await F.api(request, 'GET', `/staff/series?q=${encodeURIComponent(RUN)}`, { token: ctx.staff });
        expect(tree.status).toBe(200);
        const root = tree.json.data.find((s) => s.id === ctx.universe);
        expect(root.children.map((c) => c.id)).toEqual([ctx.saga]);
        const flat = await F.api(request, 'GET', `/staff/series?flat=1&q=${encodeURIComponent(RUN)}`, { token: ctx.staff });
        expect(flat.json.data.map((s) => s.id).sort()).toEqual([ctx.saga, ctx.universe].sort());
        expect((await F.api(request, 'GET', '/staff/series', { token: ctx.staff, extraHeaders: { 'If-None-Match': (await F.api(request, 'GET', '/staff/series', { token: ctx.staff })).headers.etag } })).status).toBe(304);
        const parent = await F.api(request, 'GET', `/staff/series/${ctx.universe}`, { token: ctx.staff });
        expect(parent.json.data.children.map((c) => c.id)).toEqual([ctx.saga]);
        // field=parent suggests universes (the auto-created parent is one).
        const sug = await F.api(request, 'GET', `/staff/series/suggest?field=parent&q=${encodeURIComponent('Universe ' + RUN)}`, { token: ctx.staff });
        expect(sug.json.data).toContain(`Universe ${RUN}`);
        expect((await F.api(request, 'GET', '/staff/series/suggest?field=nope&q=ab', { token: ctx.staff })).status).toBe(422);
    });

    test('PUT merges over the row; a different name → 422', async ({ request }) => {
        const r = await F.api(request, 'PUT', `/staff/series/${ctx.saga}`, { token: ctx.staff, body: { description: 'New desc' } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data).toMatchObject({ description: 'New desc', type: 'ciclo', group: 'G1', cycle: 'C1', cycle_order: 2, complete: true, parent_id: ctx.universe });
        const bad = await F.api(request, 'PUT', `/staff/series/${ctx.saga}`, { token: ctx.staff, body: { name: 'Other' } });
        expect(bad.status).toBe(422);
    });

    test('assign books, number them, remove one, parent work', async ({ request }) => {
        ctx.vol1 = await staffBook(request, 'Saga vol one', 0);
        ctx.vol2 = await staffBook(request, 'Saga vol two', 0);
        const a = await F.api(request, 'POST', `/staff/series/${ctx.saga}/books`, { token: ctx.staff, body: { book_ids: [ctx.vol1, ctx.vol2] } });
        expect(a.status, a.text.slice(0, 300)).toBe(200);
        expect(a.json.data).toEqual({ assigned: 2 });
        const n = await F.api(request, 'PUT', `/staff/series/${ctx.saga}/books/${ctx.vol2}`, { token: ctx.staff, body: { series_number: '2' } });
        expect(n.status, n.text.slice(0, 300)).toBe(200);
        const v2 = n.json.data.books.find((b) => b.id === ctx.vol2);
        expect(v2).toMatchObject({ series_number: '2', is_primary: true });
        expect(n.json.data.book_count).toBe(2);
        expect((await F.api(request, 'PUT', `/staff/series/${ctx.universe}/books/${ctx.vol2}`, { token: ctx.staff, body: { series_number: '9' } })).status).toBe(404);
        expect((await F.api(request, 'POST', `/staff/series/${ctx.saga}/books`, { token: ctx.staff, body: { book_ids: [999999999] } })).status).toBe(404);

        const pw = await F.api(request, 'POST', `/staff/series/${ctx.saga}/parent-work`, { token: ctx.staff, body: { title: `Saga complete ${RUN}` } });
        expect(pw.status, pw.text.slice(0, 300)).toBe(201);
        ctx.books.push(pw.json.data.book_id);
        expect(F.dbInt(`SELECT COUNT(*) FROM volumi WHERE opera_id=${pw.json.data.book_id}`)).toBe(2);
        const emptyParent = await F.api(request, 'POST', `/staff/series/${ctx.universe}/parent-work`, { token: ctx.staff, body: { title: `Nothing ${RUN}` } });
        expect(emptyParent.status).toBe(409);
        expect(emptyParent.json.meta.reason).toBe('empty_series');

        const rm = await F.api(request, 'DELETE', `/staff/series/${ctx.saga}/books/${ctx.vol1}`, { token: ctx.staff });
        expect(rm.status).toBe(200);
        expect(rm.json.data.books.map((b) => b.id)).not.toContain(ctx.vol1);
        expect((await F.api(request, 'DELETE', `/staff/series/${ctx.saga}/books/${ctx.vol1}`, { token: ctx.staff })).status).toBe(200);
    });

    test('rename to an existing name → 409, then merge_if_exists merges; merge and delete report counts', async ({ request }) => {
        const other = await F.api(request, 'POST', '/staff/series', { token: ctx.staff, body: { name: `Other ${RUN}` } });
        expect(other.status).toBe(201);
        const r = await F.api(request, 'POST', `/staff/series/${ctx.saga}/rename`, { token: ctx.staff, body: { name: `Other ${RUN}` } });
        expect(r.status).toBe(409);
        expect(r.json.meta.existing.id).toBe(other.json.data.id);
        const plain = await F.api(request, 'POST', `/staff/series/${ctx.saga}/rename`, { token: ctx.staff, body: { name: `Saga renamed ${RUN}` } });
        expect(plain.status, plain.text.slice(0, 300)).toBe(200);
        expect(plain.json.data).toMatchObject({ id: ctx.saga, name: `Saga renamed ${RUN}` });
        const merged = await F.api(request, 'POST', `/staff/series/${ctx.saga}/rename`, { token: ctx.staff, body: { name: `Other ${RUN}`, merge_if_exists: true } });
        expect(merged.status, merged.text.slice(0, 300)).toBe(200);
        expect(merged.json.meta.merged).toBe(true);
        expect(merged.json.data.id).toBe(other.json.data.id);
        expect(merged.json.data.books.map((b) => b.id)).toContain(ctx.vol2);

        const third = await F.api(request, 'POST', '/staff/series', { token: ctx.staff, body: { name: `Third ${RUN}` } });
        await F.api(request, 'POST', `/staff/series/${third.json.data.id}/books`, { token: ctx.staff, body: { book_ids: [ctx.vol1] } });
        const m = await F.api(request, 'POST', `/staff/series/${third.json.data.id}/merge`, { token: ctx.staff, body: { target_id: other.json.data.id } });
        expect(m.status, m.text.slice(0, 300)).toBe(200);
        expect(m.json.data).toEqual({ target: { id: other.json.data.id, name: `Other ${RUN}` }, books_moved: 1 });
        expect((await F.api(request, 'POST', `/staff/series/${third.json.data.id}/merge`, { token: ctx.staff, body: { target_id: other.json.data.id } })).status).toBe(404);
        expect((await F.api(request, 'POST', `/staff/series/${other.json.data.id}/merge`, { token: ctx.staff, body: { target_id: other.json.data.id } })).status).toBe(422);

        const d = await F.api(request, 'DELETE', `/staff/series/${ctx.universe}`, { token: ctx.staff });
        expect(d.status).toBe(200);
        expect(d.json.data).toMatchObject({ id: ctx.universe, deleted: true, books_updated: 0 });
        const del = await F.api(request, 'DELETE', `/staff/series/${other.json.data.id}`, { token: ctx.staff });
        expect(del.json.data.books_updated).toBe(3);
        expect((await F.api(request, 'GET', `/staff/series/${other.json.data.id}`, { token: ctx.staff })).status).toBe(404);
        expect(F.dbScalar(`SELECT COALESCE(collana, '') FROM libri WHERE id=${ctx.vol2}`)).toBe('');
    });
});

test.describe('Merges', () => {
    test('author duplicate groups, merge with a survivor and a new name; merged-away ids → 409', async ({ request }) => {
        const a = F.dbInsert(`INSERT INTO autori (nome) VALUES ('Zed Dupl ${RUN}')`);
        const b = F.dbInsert(`INSERT INTO autori (nome) VALUES ('Dupl ${RUN}, Zed')`);
        F.dbExec(`INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (${ctx.vol2}, ${b}, 'principale')`);
        const groups = await F.api(request, 'GET', '/staff/authors/duplicates', { token: ctx.staff });
        expect(groups.status).toBe(200);
        const group = groups.json.data.find((g) => g.some((x) => x.id === a));
        expect(group.map((x) => x.id).sort()).toEqual([a, b].sort());
        expect(group.find((x) => x.id === b).books_count).toBe(1);
        const m = await F.api(request, 'POST', '/staff/authors/merge', { token: ctx.staff, body: { ids: [a, b], primary_id: a, new_name: `Zed Merged ${RUN}` } });
        expect(m.status, m.text.slice(0, 300)).toBe(200);
        expect(m.json.data).toEqual({ primary_id: a });
        expect(F.dbScalar(`SELECT nome FROM autori WHERE id=${a}`)).toBe(`Zed Merged ${RUN}`);
        expect(F.dbInt(`SELECT COUNT(*) FROM libri_autori WHERE libro_id=${ctx.vol2} AND autore_id=${a}`)).toBe(1);
        const again = await F.api(request, 'POST', '/staff/authors/merge', { token: ctx.staff, body: { ids: [a, b] } });
        expect(again.status).toBe(409);
        expect(again.json.error.code).toBe('merge_error');
        expect(again.json.meta.missing).toEqual([b]);
        expect((await F.api(request, 'POST', '/staff/authors/merge', { token: ctx.staff, body: { ids: [a] } })).status).toBe(422);
    });

    test('publisher merge', async ({ request }) => {
        const p1 = (await F.api(request, 'POST', '/staff/publishers', { token: ctx.staff, body: { name: `Pub One ${RUN}` } })).json.data.id;
        const p2 = (await F.api(request, 'POST', '/staff/publishers', { token: ctx.staff, body: { name: `Pub Two ${RUN}` } })).json.data.id;
        const m = await F.api(request, 'POST', '/staff/publishers/merge', { token: ctx.staff, body: { ids: [p1, p2] } });
        expect(m.status, m.text.slice(0, 300)).toBe(200);
        expect(m.json.data.primary_id).toBe(Math.min(p1, p2));
        expect(F.dbInt(`SELECT COUNT(*) FROM editori WHERE id=${Math.max(p1, p2)}`)).toBe(0);
    });

    test('genre merge moves children; into a descendant → 409', async ({ request }) => {
        const g = async (name, parent) => (await F.api(request, 'POST', '/staff/genres', { token: ctx.staff, body: { name: `${name} ${RUN}`, ...(parent ? { parent_id: parent } : {}) } })).json.data.id;
        const src = await g('GSrc');
        const child = await g('GChild', src);
        const dst = await g('GDst');
        const loop = await F.api(request, 'POST', `/staff/genres/${src}/merge`, { token: ctx.staff, body: { target_id: child } });
        expect(loop.status).toBe(409);
        expect(loop.json.meta.reason).toBe('descendant');
        const m = await F.api(request, 'POST', `/staff/genres/${src}/merge`, { token: ctx.staff, body: { target_id: dst } });
        expect(m.status, m.text.slice(0, 300)).toBe(200);
        expect(m.json.data).toEqual({ target_id: dst, books_updated: 0, children_moved: 1 });
        expect(F.dbInt(`SELECT parent_id FROM generi WHERE id=${child}`)).toBe(dst);
        expect((await F.api(request, 'POST', `/staff/genres/${src}/merge`, { token: ctx.staff, body: { target_id: dst } })).status).toBe(404);
    });
});
