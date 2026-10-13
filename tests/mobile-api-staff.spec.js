// @ts-check
// Mobile API 1.6.0 — staff content area (/api/v1/staff/*).
//
// What this proves, end to end against the real Apache instance:
//   - the role guard: every /staff route answers 403 `forbidden` to a borrower,
//     401 without a token, and stops working the moment an operator is demoted;
//   - books go through the admin pipeline (LibriController::store/update):
//     copies, collocation, activity log, duplicate 409 with meta.existing;
//   - a PUT of ONE field leaves every other libri column, every author /
//     publisher / contributor link and the secondary series untouched — the
//     single highest-risk piece, because update() resets absent form keys;
//   - copies, covers (multipart + own-host URL), soft delete with the
//     active-circulation guard; authors, publishers, genres, shelving.
//
// Run: /tmp/run-e2e.sh tests/mobile-api-staff.spec.js --config=tests/playwright.config.js --workers=1

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const F = require('./helpers/mobile-api-fixtures');

test.describe.configure({ mode: 'serial' });
test.skip(!F.ENV_READY, 'Missing E2E env: DB_* and ADMIN_EMAIL/ADMIN_PASS');

const RUN = `${Date.now()}_${process.pid}`;
const PASS = 'Staff1234Test!';
const COVERS_DIR = path.resolve(__dirname, '..', 'public', 'uploads', 'copertine');
// 1x1 transparent PNG.
const PNG_1PX = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const STUB_ISBN = '9780140328721'; // ScrapeController E2E stub fixture

/** @type {Record<string, any>} */
const ctx = { books: [], authors: [], publishers: [], genres: [], shelves: [], collane: [], files: [] };

test.beforeAll(async ({ browser, request }) => {
    const page = await browser.newPage();
    ctx.gate = await F.ensureMobileApiReady(page);
    await page.close();

    ctx.stdEmail = `staff_std_${RUN}@pinakes.test`;
    ctx.staffEmail = `staff_op_${RUN}@pinakes.test`;
    ctx.adminEmail = `staff_adm_${RUN}@pinakes.test`;
    ctx.stdId = F.createUser(ctx.stdEmail, PASS, 'standard', 'STFS');
    ctx.staffId = F.createUser(ctx.staffEmail, PASS, 'staff', 'STFO');
    ctx.adminId = F.createUser(ctx.adminEmail, PASS, 'admin', 'STFA');
    ctx.std = await F.login(request, ctx.stdEmail, PASS, 'staff-std');
    ctx.staff = await F.login(request, ctx.staffEmail, PASS, 'staff-op');
    ctx.admin = await F.login(request, ctx.adminEmail, PASS, 'staff-adm');
});

test.afterAll(async () => {
    F.deleteBooks(ctx.books);
    // Anything else this run may have created through the stub ISBN.
    const stray = F.dbScalar(`SELECT GROUP_CONCAT(id) FROM libri WHERE titolo LIKE '%${RUN}%'`);
    if (stray && stray !== 'NULL') F.deleteBooks(stray.split(',').map(Number));
    const authorIds = [...ctx.authors, ...F.dbScalar(`SELECT GROUP_CONCAT(id) FROM autori WHERE nome LIKE '%${RUN}%'`).split(',').map(Number)];
    const ids = authorIds.filter((n) => n > 0).join(',');
    if (ids) { try { F.dbExec(`DELETE FROM libri_autori WHERE autore_id IN (${ids})`); F.dbExec(`DELETE FROM autori WHERE id IN (${ids})`); } catch {} }
    const pubIds = [...ctx.publishers, ...F.dbScalar(`SELECT GROUP_CONCAT(id) FROM editori WHERE nome LIKE '%${RUN}%'`).split(',').map(Number)]
        .filter((n) => n > 0).join(',');
    if (pubIds) { try { F.dbExec(`DELETE FROM libri_editori WHERE editore_id IN (${pubIds})`); F.dbExec(`DELETE FROM editori WHERE id IN (${pubIds})`); } catch {} }
    try { F.dbExec(`DELETE FROM collane WHERE nome LIKE '%${RUN}%'`); } catch {}
    // Children first (FK), then roots.
    try { F.dbExec(`DELETE FROM generi WHERE nome LIKE '%${RUN}%' AND parent_id IS NOT NULL`); } catch {}
    try { F.dbExec(`DELETE FROM generi WHERE nome LIKE '%${RUN}%'`); } catch {}
    const shelfIds = ctx.shelves.filter((n) => n > 0).join(',');
    if (shelfIds) { try { F.dbExec(`DELETE FROM mensole WHERE scaffale_id IN (${shelfIds})`); F.dbExec(`DELETE FROM scaffali WHERE id IN (${shelfIds})`); } catch {} }
    for (const file of ctx.files) {
        try { fs.unlinkSync(path.join(COVERS_DIR, path.basename(file))); } catch {}
    }
    F.deleteUsers([ctx.stdId, ctx.staffId, ctx.adminId]);
    F.restoreGate(ctx.gate);
});

// Every staff route, with a valid id where one is needed.
const STAFF_ROUTES = [
    ['GET', '/staff/lookups'], ['GET', `/staff/isbn/${STUB_ISBN}`], ['GET', '/staff/books'], ['POST', '/staff/books'],
    ['GET', '/staff/books/1'], ['PUT', '/staff/books/1'], ['DELETE', '/staff/books/1'],
    ['POST', '/staff/books/1/copies'], ['POST', '/staff/books/1/cover'],
    ['GET', '/staff/authors'], ['POST', '/staff/authors'], ['GET', '/staff/authors/1'], ['PUT', '/staff/authors/1'],
    ['GET', '/staff/publishers'], ['POST', '/staff/publishers'], ['GET', '/staff/publishers/1'], ['PUT', '/staff/publishers/1'],
    ['GET', '/staff/genres'], ['POST', '/staff/genres'], ['GET', '/staff/shelves'], ['POST', '/staff/shelves'],
    ['POST', '/staff/shelves/1/levels'], ['GET', '/staff/shelves/next-position?shelf_id=1&level_id=1'],
];

test.describe('Role guard', () => {
    test('a borrower gets 403 forbidden on every staff route, nothing is written', async ({ request }) => {
        const booksBefore = F.dbInt('SELECT COUNT(*) FROM libri');
        for (const [method, route] of STAFF_ROUTES) {
            const body = method === 'GET' ? undefined : { title: `Forbidden ${RUN}`, name: `Forbidden ${RUN}`, code: `F${RUN}`.slice(0, 20), quantity: 1, number: 9 };
            const r = await F.api(request, method, route, { token: ctx.std, body });
            expect(r.status, `${method} ${route}`).toBe(403);
            expect(r.json?.error?.code, `${method} ${route}`).toBe('forbidden');
        }
        expect(F.dbInt('SELECT COUNT(*) FROM libri')).toBe(booksBefore);
        expect(F.dbInt(`SELECT COUNT(*) FROM autori WHERE nome LIKE 'Forbidden ${RUN}%'`)).toBe(0);
    });

    test('no token → 401; staff and admin tokens are admitted', async ({ request }) => {
        expect((await F.api(request, 'GET', '/staff/lookups')).status).toBe(401);
        expect((await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff })).status).toBe(200);
        expect((await F.api(request, 'GET', '/staff/lookups', { token: ctx.admin })).status).toBe(200);
    });

    test('health advertises staff_catalog', async ({ request }) => {
        const r = await F.api(request, 'GET', '/health');
        expect(r.status).toBe(200);
        expect(r.json.data.features.staff_catalog).toBe(true);
    });
});

test.describe('Lookups and ISBN import', () => {
    test('GET /staff/lookups → 200 + ETag, then 304', async ({ request }) => {
        const r = await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff });
        expect(r.status).toBe(200);
        const d = r.json.data;
        for (const key of ['languages', 'media_types', 'formats', 'acquisition_types', 'contributor_roles', 'copy_states']) {
            expect(Array.isArray(d[key]), key).toBe(true);
        }
        expect(d.media_types.map((m) => m.value)).toEqual(expect.arrayContaining(['libro', 'disco', 'altro']));
        expect(d.contributor_roles.map((m) => m.value)).toEqual(['principale', 'co-autore', 'traduttore', 'illustratore', 'curatore', 'colorista']);
        expect(d.copy_states.find((c) => c.value === 'prestato').creatable).toBe(false);
        const etag = r.headers.etag;
        expect(etag).toBeTruthy();
        const again = await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff, extraHeaders: { 'If-None-Match': etag } });
        expect(again.status).toBe(304);
    });

    test('invalid identifiers → 422 validation_error', async ({ request }) => {
        for (const bad of ['123', '9780140328722', 'abcdefghij']) {
            const r = await F.api(request, 'GET', `/staff/isbn/${bad}`, { token: ctx.staff });
            expect(r.status, bad).toBe(422);
            expect(r.json.error.code).toBe('validation_error');
        }
    });

    test('stub ISBN → preview, then existing after the book is created', async ({ request }) => {
        const probe = await F.api(request, 'GET', `/staff/isbn/978-0-14-032872-1`, { token: ctx.staff });
        test.skip(probe.status !== 200 || probe.json?.data?.preview?.title !== 'Fantastic Mr. Fox',
            'scraper E2E stub (PINAKES_E2E_SCRAPER_STUB=1) not enabled on this instance');
        const d = probe.json.data;
        expect(d.identifier).toMatchObject({ isbn13: STUB_ISBN, isbn10: '0140328726', kind: 'isbn' });
        expect(d.found).toBe(true);
        expect(d.preview.authors).toEqual(['Roald Dahl']);
        expect(d.preview.publisher).toBe('Puffin Books');
        expect(d.preview.year).toBe(2007);
        expect(d.preview.cover_url).toMatch(/^https?:\/\/.+\/uploads\/copertine\/placeholder\.jpg$/);

        // A record already carrying this ISBN (e.g. left by another suite) must be reported.
        const preexisting = F.dbInt(`SELECT id FROM libri WHERE deleted_at IS NULL AND (isbn13='${STUB_ISBN}' OR ean='${STUB_ISBN}' OR isbn10='0140328726') ORDER BY id LIMIT 1`);
        if (preexisting > 0) {
            expect(d.existing.id).toBe(preexisting);
            return;
        }
        expect(d.existing).toBeNull();

        const created = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: {
            title: `${d.preview.title} ${RUN}`, isbn13: d.preview.isbn13, isbn10: d.preview.isbn10,
            authors_new: d.preview.authors, publishers_new: [d.preview.publisher], year: d.preview.year,
            cover_url: d.preview.cover_url, copies: 1,
        } });
        expect(created.status, created.text.slice(0, 300)).toBe(201);
        ctx.books.push(created.json.data.id);
        expect(created.json.data.cover_url).toMatch(/placeholder\.jpg$/);

        const after = await F.api(request, 'GET', `/staff/isbn/${STUB_ISBN}`, { token: ctx.staff });
        expect(after.json.data.existing.id).toBe(created.json.data.id);
        expect(after.json.data.existing.copies_total).toBe(1);
    });
});

test.describe('Books', () => {
    test('shelf + level fixtures (POST /staff/shelves, /levels, 409 duplicates)', async ({ request }) => {
        const code = `Z${RUN.slice(-8)}`;
        const s = await F.api(request, 'POST', '/staff/shelves', { token: ctx.staff, body: { code, name: `Shelf ${RUN}`, order: 3 } });
        expect(s.status, s.text).toBe(201);
        expect(s.json.data).toMatchObject({ code, name: `Shelf ${RUN}`, order: 3, levels: [] });
        ctx.shelfId = s.json.data.id;
        ctx.shelves.push(ctx.shelfId);
        const dup = await F.api(request, 'POST', '/staff/shelves', { token: ctx.staff, body: { code } });
        expect(dup.status).toBe(409);
        expect(dup.json.error.code).toBe('duplicate');
        expect(dup.json.meta.existing.id).toBe(ctx.shelfId);

        const l = await F.api(request, 'POST', `/staff/shelves/${ctx.shelfId}/levels`, { token: ctx.staff, body: { number: 2, description: 'Top' } });
        expect(l.status, l.text).toBe(201);
        expect(l.json.data).toMatchObject({ number: 2, description: 'Top' });
        ctx.levelId = l.json.data.id;
        expect((await F.api(request, 'POST', `/staff/shelves/${ctx.shelfId}/levels`, { token: ctx.staff, body: { number: 2 } })).status).toBe(409);
        expect((await F.api(request, 'POST', '/staff/shelves/999999999/levels', { token: ctx.staff, body: { number: 1 } })).status).toBe(404);

        const list = await F.api(request, 'GET', '/staff/shelves', { token: ctx.staff });
        const mine = list.json.data.find((x) => x.id === ctx.shelfId);
        expect(mine.levels).toEqual([{ id: ctx.levelId, number: 2, description: 'Top', order: 0 }]);

        const next = await F.api(request, 'GET', `/staff/shelves/next-position?shelf_id=${ctx.shelfId}&level_id=${ctx.levelId}`, { token: ctx.staff });
        expect(next.json.data).toMatchObject({ position: 1, level_number: 2, shelf_code: code });
        expect((await F.api(request, 'GET', `/staff/shelves/next-position?shelf_id=${ctx.shelfId}&level_id=999999`, { token: ctx.staff })).status).toBe(422);
    });

    test('create a manual record without ISBN (sheet music): copies, collocation, activity log', async ({ request }) => {
        const r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: {
            title: `Sonata manuale ${RUN}`, subtitle: 'per pianoforte', media_type: 'altro', format: 'spartito',
            copies: 2, shelf_id: ctx.shelfId, level_id: ctx.levelId, position: 0,
            authors_new: [`Compositore ${RUN}`], publishers_new: [`Edizioni ${RUN}`],
            genre_new: `Musica ${RUN}`, subgenre_new: `Sonate ${RUN}`, series: `Serie ${RUN}`, series_number: '4',
            contributors: { editor_ids: [], editors_new: [`Curatrice ${RUN}`] },
            language: 'italiano', pages: 48, year: 1999, price: 12.5, acquisition_date: '2026-01-15', notes: 'Prima nota',
        } });
        expect(r.status, r.text.slice(0, 400)).toBe(201);
        const b = r.json.data;
        ctx.books.push(b.id);
        ctx.manualId = b.id;
        expect(b).toMatchObject({ title: `Sonata manuale ${RUN}`, subtitle: 'per pianoforte', media_type: 'altro', format: 'spartito',
            copies_total: 2, copies_available: 2, pages: 48, year: 1999, price: 12.5, isbn13: null, series: `Serie ${RUN}` });
        expect(b.location).toMatchObject({ shelf_id: ctx.shelfId, level_id: ctx.levelId, level_number: 2, position: 1 });
        expect(b.location.label).toMatch(/-2-01$/);
        expect(b.copies).toHaveLength(2);
        expect(b.authors.map((a) => [a.name, a.role])).toEqual(expect.arrayContaining([[`Compositore ${RUN}`, 'principale'], [`Curatrice ${RUN}`, 'curatore']]));
        expect(b.publishers.map((p) => p.name)).toEqual([`Edizioni ${RUN}`]);
        expect(b.genre?.name).toBe(`Musica ${RUN}`);
        expect(b.subgenre?.name).toBe(`Sonate ${RUN}`);
        expect(F.dbInt(`SELECT COUNT(*) FROM copie WHERE libro_id=${b.id}`)).toBe(2);
        expect(F.dbScalar(`SELECT collocazione FROM libri WHERE id=${b.id}`)).toBe(b.location.label);
        expect(F.dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${b.id} AND azione='inserimento'`)).toBe(1);
        expect(F.dbInt(`SELECT utente_id FROM log_modifiche WHERE tabella='libri' AND record_id=${b.id} AND azione='inserimento'`)).toBe(ctx.staffId);

        const next = await F.api(request, 'GET', `/staff/shelves/next-position?shelf_id=${ctx.shelfId}&level_id=${ctx.levelId}`, { token: ctx.staff });
        expect(next.json.data.position).toBe(2);
        const keep = await F.api(request, 'GET', `/staff/shelves/next-position?shelf_id=${ctx.shelfId}&level_id=${ctx.levelId}&book_id=${b.id}`, { token: ctx.staff });
        expect(keep.json.data.position).toBe(1);
    });

    test('validation: title required, unknown references, bad identifiers → 422 with meta.field', async ({ request }) => {
        const cases = [
            [{ subtitle: 'x' }, 'title'],
            [{ title: 'x', isbn13: '9780000000000' }, 'isbn13'],
            [{ title: 'x', author_ids: [999999999] }, 'author_ids'],
            [{ title: 'x', copies: 0 }, 'copies'],
            [{ title: 'x', media_type: 'vinile' }, 'media_type'],
            [{ title: 'x', shelf_id: ctx.shelfId }, 'level_id'],
            [{ title: 'x', acquisition_date: '2026-02-30' }, 'acquisition_date'],
        ];
        const before = F.dbInt('SELECT COUNT(*) FROM libri');
        for (const [body, field] of cases) {
            const r = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body });
            expect(r.status, JSON.stringify(body)).toBe(422);
            expect(r.json.error.code).toBe('validation_error');
            expect(r.json.meta.field).toBe(field);
        }
        expect(F.dbInt('SELECT COUNT(*) FROM libri')).toBe(before);
    });

    test('same ISBN twice → 201 then 409 duplicate with meta.existing', async ({ request }) => {
        const isbn = F.uniqueIsbn13();
        ctx.isbn = isbn;
        const first = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Duplicato ${RUN}`, isbn13: isbn } });
        expect(first.status, first.text.slice(0, 300)).toBe(201);
        ctx.books.push(first.json.data.id);
        ctx.dupId = first.json.data.id;
        const second = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Duplicato bis ${RUN}`, ean: isbn } });
        expect(second.status).toBe(409);
        expect(second.json.error.code).toBe('duplicate');
        expect(second.json.meta.existing).toMatchObject({ id: ctx.dupId, isbn13: isbn, copies_total: 1 });
        expect(F.dbInt(`SELECT COUNT(*) FROM libri WHERE titolo='Duplicato bis ${RUN}'`)).toBe(0);
    });

    test('PUT of ONE field leaves every other column and every link untouched', async ({ request }) => {
        const id = ctx.manualId;
        // Things the app never sends but update() would reset if not re-posted.
        const columns = F.dbRows('SHOW COLUMNS FROM libri').map((c) => c.Field);
        const extra = ['traduttore=traduttore'];
        if (columns.includes('peso')) extra.push('peso=1.25');
        if (columns.includes('dimensioni')) extra.push("dimensioni='21x30'");
        if (columns.includes('review')) extra.push("review='LT review kept'");
        if (columns.includes('barcode')) extra.push(`barcode='BC${RUN.slice(-8)}'`);
        if (columns.includes('file_url')) extra.push("file_url='/uploads/digital/keep.pdf'");
        F.dbExec(`UPDATE libri SET ${extra.join(', ')} WHERE id=${id}`);
        const secondary = F.dbInsert(`INSERT INTO collane (nome, tipo) VALUES ('Altra serie ${RUN}', 'serie')`);
        F.dbExec(`INSERT INTO libri_collane (libro_id, collana_id, numero_serie, tipo_appartenenza, is_principale) VALUES (${id}, ${secondary}, NULL, 'secondaria', 0)`);
        // A second publisher and a co-author through the API itself.
        const coAuthor = F.dbInsert(`INSERT INTO autori (nome, created_at, updated_at) VALUES ('Coautore ${RUN}', NOW(), NOW())`);
        ctx.authors.push(coAuthor);
        F.dbExec(`INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (${id}, ${coAuthor}, 'co-autore')`);
        const pub2 = F.dbInsert(`INSERT INTO editori (nome, created_at, updated_at) VALUES ('Secondo editore ${RUN}', NOW(), NOW())`);
        ctx.publishers.push(pub2);
        F.dbExec(`INSERT INTO libri_editori (libro_id, editore_id, ordine) VALUES (${id}, ${pub2}, 1)`);

        const snapshot = () => ({
            row: F.dbRows(`SELECT * FROM libri WHERE id=${id}`)[0],
            authors: F.dbRows(`SELECT autore_id, ruolo, ordine_credito FROM libri_autori WHERE libro_id=${id} ORDER BY autore_id, ruolo`),
            publishers: F.dbRows(`SELECT editore_id, ordine FROM libri_editori WHERE libro_id=${id} ORDER BY editore_id`),
            series: F.dbRows(`SELECT collana_id, numero_serie, tipo_appartenenza, is_principale FROM libri_collane WHERE libro_id=${id} ORDER BY collana_id`),
            copies: F.dbRows(`SELECT id, numero_inventario, stato, note FROM copie WHERE libro_id=${id} ORDER BY id`),
        });
        const before = snapshot();
        expect(before.authors.length).toBe(3);
        expect(before.series.length).toBe(2);

        const r = await F.api(request, 'PUT', `/staff/books/${id}`, { token: ctx.staff, body: { subtitle: 'per pianoforte a quattro mani' } });
        expect(r.status, r.text.slice(0, 400)).toBe(200);
        expect(r.json.data.subtitle).toBe('per pianoforte a quattro mani');

        const after = snapshot();
        const changed = Object.keys(before.row).filter((k) => before.row[k] !== after.row[k]);
        // updated_at moves; the search index is rebuilt from the same data.
        expect(changed.filter((k) => !['sottotitolo', 'updated_at', 'search_index'].includes(k)), 'unexpected column changes').toEqual([]);
        expect(after.row.sottotitolo).toBe('per pianoforte a quattro mani');
        expect(after.authors).toEqual(before.authors);
        expect(after.publishers).toEqual(before.publishers);
        expect(after.series).toEqual(before.series);
        expect(after.copies).toEqual(before.copies);
        expect(F.dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${id} AND azione='aggiornamento'`)).toBe(1);
    });

    test('PUT: authors_new / publishers_new alone add to the current links instead of replacing them', async ({ request }) => {
        const id = ctx.manualId;
        const before = await F.api(request, 'GET', `/staff/books/${id}`, { token: ctx.staff });
        expect(before.status).toBe(200);
        const creatorNames = (book) => book.authors.filter((a) => ['principale', 'co-autore'].includes(a.role)).map((a) => a.name);
        const oldCreators = creatorNames(before.json.data);
        const oldPublishers = before.json.data.publishers.map((p) => p.name);
        expect(oldCreators.length).toBeGreaterThan(0);

        const r = await F.api(request, 'PUT', `/staff/books/${id}`, { token: ctx.staff, body: {
            authors_new: [`Autore aggiunto ${RUN}`], publishers_new: [`Editore aggiunto ${RUN}`],
        } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(creatorNames(r.json.data)).toEqual(expect.arrayContaining([...oldCreators, `Autore aggiunto ${RUN}`]));
        expect(r.json.data.publishers.map((p) => p.name)).toEqual(expect.arrayContaining([...oldPublishers, `Editore aggiunto ${RUN}`]));
    });

    test('PUT: explicit null clears, relation lists replace, unknown book → 404', async ({ request }) => {
        const id = ctx.manualId;
        const r = await F.api(request, 'PUT', `/staff/books/${id}`, { token: ctx.staff, body: {
            notes: null, publisher_ids: [], authors_new: [`Nuovo autore ${RUN}`], author_ids: [],
        } });
        expect(r.status, r.text.slice(0, 300)).toBe(200);
        expect(r.json.data.notes).toBeNull();
        expect(r.json.data.publishers).toEqual([]);
        const creators = r.json.data.authors.filter((a) => ['principale', 'co-autore'].includes(a.role)).map((a) => a.name);
        expect(creators).toEqual([`Nuovo autore ${RUN}`]);
        // The curator was not mentioned, so it stays.
        expect(r.json.data.authors.some((a) => a.role === 'curatore')).toBe(true);
        expect((await F.api(request, 'PUT', '/staff/books/999999999', { token: ctx.staff, body: { title: 'x' } })).status).toBe(404);
        expect((await F.api(request, 'PUT', `/staff/books/${id}`, { token: ctx.staff, body: { title: '  ' } })).status).toBe(422);
        // Changing the ISBN to one another title carries → 409.
        const dup = await F.api(request, 'PUT', `/staff/books/${id}`, { token: ctx.staff, body: { isbn13: ctx.isbn } });
        expect(dup.status).toBe(409);
        expect(dup.json.meta.existing.id).toBe(ctx.dupId);
    });

    test('GET /staff/books lists wanted titles too; /catalog/search does not', async ({ request }) => {
        const hasWanted = F.dbRows("SHOW COLUMNS FROM libri LIKE 'is_desiderata'").length === 1;
        if (hasWanted) F.dbExec(`UPDATE libri SET is_desiderata=1 WHERE id=${ctx.dupId}`);
        const r = await F.api(request, 'GET', `/staff/books?q=${encodeURIComponent('Duplicato ' + RUN)}`, { token: ctx.staff });
        expect(r.status).toBe(200);
        const row = r.json.data.find((x) => x.id === ctx.dupId);
        expect(row, 'staff list includes the title').toBeTruthy();
        expect(row.is_wanted).toBe(hasWanted);
        expect(row.updated_at).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
        expect(r.json.meta).toMatchObject({ has_more: false });
        const etag = r.headers.etag;
        expect((await F.api(request, 'GET', `/staff/books?q=${encodeURIComponent('Duplicato ' + RUN)}`, { token: ctx.staff, extraHeaders: { 'If-None-Match': etag } })).status).toBe(304);
        if (hasWanted) {
            const pub = await F.api(request, 'GET', `/catalog/search?q=${encodeURIComponent('Duplicato ' + RUN)}`, { token: ctx.std });
            expect(pub.json.data.some((x) => x.id === ctx.dupId), 'public catalogue hides the wanted title').toBe(false);
            expect(pub.json.data[0]?.is_wanted, 'borrower rows keep the public shape').toBeUndefined();
            F.dbExec(`UPDATE libri SET is_desiderata=0 WHERE id=${ctx.dupId}`);
        }
        const detail = await F.api(request, 'GET', `/staff/books/${ctx.manualId}`, { token: ctx.staff });
        expect(detail.status).toBe(200);
        expect(detail.json.data.id).toBe(ctx.manualId);
        expect((await F.api(request, 'GET', `/staff/books/${ctx.manualId}`, { token: ctx.staff, extraHeaders: { 'If-None-Match': detail.headers.etag } })).status).toBe(304);
        expect((await F.api(request, 'GET', '/staff/books/999999999', { token: ctx.staff })).status).toBe(404);
    });

    test('POST /copies adds copies with -C{n} codes and recalculates the counters', async ({ request }) => {
        const id = ctx.manualId;
        const r = await F.api(request, 'POST', `/staff/books/${id}/copies`, { token: ctx.staff, body: { quantity: 2, notes: 'Dono 100%' } });
        expect(r.status, r.text.slice(0, 300)).toBe(201);
        expect(r.json.data).toMatchObject({ copies_total: 4, copies_available: 4 });
        expect(r.json.data.created).toHaveLength(2);
        for (const c of r.json.data.created) expect(c.inventory_number).toMatch(/-C\d+$/);
        expect(F.dbInt(`SELECT copie_totali FROM libri WHERE id=${id}`)).toBe(4);
        expect(F.dbScalar(`SELECT note FROM copie WHERE id=${r.json.data.created[0].id}`)).toBe('Dono 100%');
        const repair = await F.api(request, 'POST', `/staff/books/${id}/copies`, { token: ctx.staff, body: { quantity: 1, status: 'manutenzione' } });
        expect(repair.status).toBe(201);
        expect(repair.json.data.copies_available).toBe(4);
        for (const body of [{ quantity: 0 }, { quantity: 101 }, { quantity: 1, status: 'prestato' }]) {
            expect((await F.api(request, 'POST', `/staff/books/${id}/copies`, { token: ctx.staff, body })).status, JSON.stringify(body)).toBe(422);
        }
        expect((await F.api(request, 'POST', '/staff/books/999999999/copies', { token: ctx.staff, body: { quantity: 1 } })).status).toBe(404);
    });

    test('cover: multipart PNG upload, then own-host URL replaces it and retires the file', async ({ request }) => {
        const id = ctx.manualId;
        const up = await F.api(request, 'POST', `/staff/books/${id}/cover`, {
            token: ctx.staff, multipart: { cover: { name: 'cover.png', mimeType: 'image/png', buffer: PNG_1PX } },
        });
        expect(up.status, up.text.slice(0, 300)).toBe(200);
        const stored = F.dbScalar(`SELECT copertina_url FROM libri WHERE id=${id}`);
        expect(stored).toMatch(/^\/uploads\/copertine\/libro_\d+_[a-f0-9]+\.png$/);
        ctx.files.push(stored);
        expect(fs.existsSync(path.join(COVERS_DIR, path.basename(stored)))).toBe(true);
        expect(up.json.data.cover_url.endsWith(stored)).toBe(true);

        const byUrl = await F.api(request, 'POST', `/staff/books/${id}/cover`, { token: ctx.staff, body: { cover_url: `${F.BASE}/uploads/copertine/placeholder.jpg` } });
        expect(byUrl.status, byUrl.text.slice(0, 300)).toBe(200);
        const second = F.dbScalar(`SELECT copertina_url FROM libri WHERE id=${id}`);
        ctx.files.push(second);
        expect(second).toMatch(/^\/uploads\/copertine\/libro_\d+_[a-f0-9]+\.jpg$/);
        expect(fs.existsSync(path.join(COVERS_DIR, path.basename(stored))), 'previous upload retired').toBe(false);
        expect(fs.existsSync(path.join(COVERS_DIR, 'placeholder.jpg')), 'placeholder untouched').toBe(true);

        const notImage = await F.api(request, 'POST', `/staff/books/${id}/cover`, {
            token: ctx.staff, multipart: { cover: { name: 'x.png', mimeType: 'image/png', buffer: Buffer.from('<?php echo 1; ?>') } },
        });
        expect(notImage.status).toBe(422);
        expect((await F.api(request, 'POST', `/staff/books/${id}/cover`, { token: ctx.staff, body: { cover_url: 'ftp://example.com/x.jpg' } })).status).toBe(422);
        expect((await F.api(request, 'POST', `/staff/books/${id}/cover`, { token: ctx.staff, body: {} })).status).toBe(422);
        expect(F.dbScalar(`SELECT copertina_url FROM libri WHERE id=${id}`)).toBe(second);
    });

    test('DELETE: 409 with an active loan, then soft delete nulls the identifiers, 2nd call 404', async ({ request }) => {
        const id = ctx.dupId;
        const loan = F.dbInsert(`INSERT INTO prestiti (libro_id, utente_id, copia_id, data_prestito, data_scadenza, stato, attivo, origine)
                                 VALUES (${id}, ${ctx.stdId}, NULL, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1, 'diretto')`);
        const blocked = await F.api(request, 'DELETE', `/staff/books/${id}`, { token: ctx.staff });
        expect(blocked.status).toBe(409);
        expect(blocked.json.error.code).toBe('conflict');
        expect(blocked.json.meta.reason).toBe('active_circulation');
        expect(F.dbScalar(`SELECT deleted_at FROM libri WHERE id=${id}`)).toBe('NULL');
        F.dbExec(`DELETE FROM prestiti WHERE id=${loan}`);

        const ok = await F.api(request, 'DELETE', `/staff/books/${id}`, { token: ctx.staff });
        expect(ok.status, ok.text).toBe(200);
        expect(ok.json.data).toEqual({ id, deleted: true });
        const row = F.dbRows(`SELECT deleted_at, isbn13, ean, isbn10 FROM libri WHERE id=${id}`)[0];
        expect(row.deleted_at).not.toBe('NULL');
        expect([row.isbn13, row.ean, row.isbn10]).toEqual(['NULL', 'NULL', 'NULL']);
        expect(F.dbInt(`SELECT COUNT(*) FROM log_modifiche WHERE tabella='libri' AND record_id=${id} AND azione='cancellazione'`)).toBe(1);
        expect((await F.api(request, 'DELETE', `/staff/books/${id}`, { token: ctx.staff })).status).toBe(404);
        expect((await F.api(request, 'GET', `/staff/books/${id}`, { token: ctx.staff })).status).toBe(404);
        // The ISBN is free again.
        const reuse = await F.api(request, 'POST', '/staff/books', { token: ctx.staff, body: { title: `Riuso ISBN ${RUN}`, isbn13: ctx.isbn } });
        expect(reuse.status).toBe(201);
        ctx.books.push(reuse.json.data.id);
    });
});

test.describe('Authors, publishers, genres', () => {
    test('authors: create, 409 duplicate, partial update keeps the photo, search, detail', async ({ request }) => {
        const name = `Ada Autrice ${RUN}`;
        const c = await F.api(request, 'POST', '/staff/authors', { token: ctx.staff, body: {
            name, pseudonym: `Pseudo ${RUN}`, born: '1901-02-03', nationality: 'Italiana', biography: '<b>Bio</b> semplice', website: 'example.org',
        } });
        expect(c.status, c.text).toBe(201);
        const id = c.json.data.id;
        ctx.authors.push(id);
        expect(c.json.data).toMatchObject({ name, born: '1901-02-03', died: null, nationality: 'Italiana', biography: 'Bio semplice', website: 'https://example.org' });

        const dup = await F.api(request, 'POST', '/staff/authors', { token: ctx.staff, body: { name } });
        expect(dup.status).toBe(409);
        expect(dup.json.meta.existing).toEqual({ id, name });

        F.dbExec(`UPDATE autori SET foto='/uploads/autori/keep-${RUN}.jpg' WHERE id=${id}`);
        const u = await F.api(request, 'PUT', `/staff/authors/${id}`, { token: ctx.staff, body: { died: '1980-12-31', biography: null } });
        expect(u.status, u.text).toBe(200);
        expect(u.json.data).toMatchObject({ name, died: '1980-12-31', biography: null, pseudonym: `Pseudo ${RUN}`, born: '1901-02-03' });
        expect(F.dbScalar(`SELECT foto FROM autori WHERE id=${id}`)).toBe(`/uploads/autori/keep-${RUN}.jpg`);
        expect(u.json.data.photo_url).toMatch(new RegExp(`/uploads/autori/keep-${RUN}\\.jpg$`));

        expect((await F.api(request, 'PUT', `/staff/authors/${id}`, { token: ctx.staff, body: { born: '31/12/1900' } })).status).toBe(422);
        expect((await F.api(request, 'PUT', `/staff/authors/${id}`, { token: ctx.staff, body: { gnd_id: 'nope' } })).status).toBe(422);
        expect((await F.api(request, 'POST', '/staff/authors', { token: ctx.staff, body: { name: ' ' } })).status).toBe(422);
        expect((await F.api(request, 'GET', '/staff/authors/999999999', { token: ctx.staff })).status).toBe(404);

        const s = await F.api(request, 'GET', `/staff/authors?q=${encodeURIComponent('Autrice ' + RUN)}&limit=5`, { token: ctx.staff });
        expect(s.status).toBe(200);
        expect(s.json.data).toEqual([{ id, name, pseudonym: `Pseudo ${RUN}`, label: `Pseudo ${RUN} (${name})`, born: '1901-02-03', died: '1980-12-31' }]);
        const byPseudo = await F.api(request, 'GET', `/staff/authors?q=${encodeURIComponent('Pseudo ' + RUN)}`, { token: ctx.staff });
        expect(byPseudo.json.data.map((a) => a.id)).toEqual([id]);
        const d = await F.api(request, 'GET', `/staff/authors/${id}`, { token: ctx.staff });
        expect(d.json.data.id).toBe(id);
    });

    test('publishers: create, 409 duplicate, partial update keeps hidden fields', async ({ request }) => {
        const name = `Editrice ${RUN}`;
        const c = await F.api(request, 'POST', '/staff/publishers', { token: ctx.staff, body: { name, email: 'info@example.org', phone: '0123' } });
        expect(c.status, c.text).toBe(201);
        const id = c.json.data.id;
        ctx.publishers.push(id);
        expect(c.json.data).toMatchObject({ name, email: 'info@example.org', phone: '0123', website: null });
        const dup = await F.api(request, 'POST', '/staff/publishers', { token: ctx.staff, body: { name } });
        expect(dup.status).toBe(409);
        expect(dup.json.meta.existing.id).toBe(id);
        F.dbExec(`UPDATE editori SET referente_nome='Referente ${RUN}' WHERE id=${id}`);
        const u = await F.api(request, 'PUT', `/staff/publishers/${id}`, { token: ctx.staff, body: { website: 'https://example.org/' } });
        expect(u.status).toBe(200);
        expect(u.json.data).toMatchObject({ name, website: 'https://example.org/', email: 'info@example.org', phone: '0123' });
        expect(F.dbScalar(`SELECT referente_nome FROM editori WHERE id=${id}`)).toBe(`Referente ${RUN}`);
        expect((await F.api(request, 'PUT', `/staff/publishers/${id}`, { token: ctx.staff, body: { email: 'not-an-email' } })).status).toBe(422);
        const s = await F.api(request, 'GET', `/staff/publishers?q=${encodeURIComponent(name)}`, { token: ctx.staff });
        expect(s.json.data).toEqual([{ id, name }]);
        expect((await F.api(request, 'GET', `/staff/publishers/${id}`, { token: ctx.staff })).json.data.id).toBe(id);
        expect((await F.api(request, 'GET', '/staff/publishers/999999999', { token: ctx.staff })).status).toBe(404);
    });

    test('genres: find-or-create (created flag), child under parent, tree + ETag', async ({ request }) => {
        const root = await F.api(request, 'POST', '/staff/genres', { token: ctx.staff, body: { name: `Radice ${RUN}` } });
        expect(root.status, root.text).toBe(201);
        expect(root.json.data).toMatchObject({ name: `Radice ${RUN}`, parent_id: null, created: true });
        const again = await F.api(request, 'POST', '/staff/genres', { token: ctx.staff, body: { name: `Radice ${RUN}` } });
        expect(again.status).toBe(201);
        expect(again.json.data).toMatchObject({ id: root.json.data.id, created: false });
        const child = await F.api(request, 'POST', '/staff/genres', { token: ctx.staff, body: { name: `Figlio ${RUN}`, parent_id: root.json.data.id } });
        expect(child.json.data).toMatchObject({ parent_id: root.json.data.id, created: true });
        expect((await F.api(request, 'POST', '/staff/genres', { token: ctx.staff, body: { name: 'x', parent_id: 999999999 } })).status).toBe(422);
        expect((await F.api(request, 'POST', '/staff/genres', { token: ctx.staff, body: { name: '' } })).status).toBe(422);

        const tree = await F.api(request, 'GET', '/staff/genres', { token: ctx.staff });
        expect(tree.status).toBe(200);
        const node = tree.json.data.find((g) => g.id === root.json.data.id);
        expect(node.children).toEqual([{ id: child.json.data.id, name: `Figlio ${RUN}`, parent_id: root.json.data.id, children: [] }]);
        expect((await F.api(request, 'GET', '/staff/genres', { token: ctx.staff, extraHeaders: { 'If-None-Match': tree.headers.etag } })).status).toBe(304);
    });
});

test.describe('Revocation', () => {
    test('demoting the operator closes the staff area on the very next request', async ({ request }) => {
        F.dbExec(`UPDATE utenti SET tipo_utente='standard' WHERE id=${ctx.staffId}`);
        try {
            const r = await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff });
            expect(r.status).toBe(403);
            expect(r.json.error.code).toBe('forbidden');
        } finally {
            F.dbExec(`UPDATE utenti SET tipo_utente='staff' WHERE id=${ctx.staffId}`);
        }
        expect((await F.api(request, 'GET', '/staff/lookups', { token: ctx.staff })).status).toBe(200);
    });
});
