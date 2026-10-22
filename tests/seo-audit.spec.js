// @ts-check
// SEO audit of the public site, as a crawler sees it: status codes, <head>
// meta, canonical, JSON-LD and the published sitemap. Each block is a defect
// found on a live installation and fixed in the app:
//   404 page indexable with a canonical to the missing URL · pages past the
//   last one answering 200 (soft 404) · "/catalogo/" answering 404 · events
//   og:image pointing at a missing file, empty events page indexable · fixed
//   strings in JSON-LD ("Biblioteca Digitale") instead of the library's data ·
//   every service page sharing the site description · author biography/photo
//   and publisher website/address never reaching meta and schema · catalogue
//   page 2+ duplicating page 1's title · inLanguage as free text ("italiano")
//   · feed.xml in the sitemap, parent genres missing, events listed while the
//   events page is off · llms.txt blockquote broken by a line break.
//
// Fixtures are written to the DB and restored; settings are changed through
// the admin UI (it invalidates the caches), and restored the same way.
//
// Run: /tmp/run-e2e.sh tests/seo-audit.spec.js --config=tests/playwright.config.js --workers=1
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME, 'E2E credentials not configured');

const RUN = Date.now().toString(36);
const GENERIC_LIBRARY_SENTENCE = 'Biblioteca digitale con catalogo completo di libri disponibili per il prestito';

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}
function sqlq(s) { return s === null ? 'NULL' : "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }
function nullable(v) { return v === 'NULL' ? null : v; }

/** All JSON-LD objects of a page, flattened (top-level arrays and @graph). */
function jsonLd(html) {
  const out = [];
  for (const m of html.matchAll(/<script[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)) {
    const data = JSON.parse(m[1]);
    for (const item of Array.isArray(data) ? data : [data]) {
      out.push(item);
      if (Array.isArray(item['@graph'])) out.push(...item['@graph']);
    }
  }
  return out;
}
function head(html) {
  const pick = (re) => { const m = html.match(re); return m ? m[1] : null; };
  return {
    title: pick(/<title>([^<]*)<\/title>/),
    description: pick(/<meta name="description"\s+content="([^"]*)"/),
    robots: pick(/<meta name="robots" content="([^"]*)"/),
    canonical: pick(/<link rel="canonical"\s+href="([^"]*)"/),
    ogImage: pick(/<meta property="og:image" content="([^"]*)"/),
  };
}
function decode(s) {
  return String(s ?? '').replace(/&quot;/g, '"').replace(/&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
}

// ── Fixtures ────────────────────────────────────────────────────────────────
const fx = {};

test.beforeAll(() => {
  // An author (name with a space) and a publisher that both have a book in the
  // catalogue. A desiderata title (wanted, not held) is in no listing or sitemap.
  const hasDesiderata = db("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'libri' AND column_name = 'is_desiderata'") === '1';
  const book = db(`SELECT l.id, a.id, a.nome, e.id, e.nome FROM libri l
      JOIN libri_autori la ON la.libro_id = l.id AND la.ruolo = 'principale'
      JOIN autori a ON a.id = la.autore_id
      JOIN editori e ON e.id = l.editore_id
      WHERE l.deleted_at IS NULL ${hasDesiderata ? 'AND l.is_desiderata = 0' : ''} AND a.nome LIKE '% %' AND a.nome NOT LIKE '%/%' AND e.nome NOT LIKE '%/%'
      ORDER BY l.id LIMIT 1`).split('\t');
  expect(book.length, 'a visible book with author and publisher is needed').toBe(5);
  [fx.bookId, fx.authorId, fx.authorName, fx.publisherId, fx.publisherName] = book;

  const a = db(`SELECT COALESCE(biografia,'NULL'), COALESCE(foto,'NULL') FROM autori WHERE id=${fx.authorId}`).split('\t');
  fx.authorPrior = { biografia: nullable(a[0]), foto: nullable(a[1]) };
  const e = db(`SELECT COALESCE(sito_web,'NULL'), COALESCE(indirizzo,'NULL') FROM editori WHERE id=${fx.publisherId}`).split('\t');
  fx.publisherPrior = { sito_web: nullable(e[0]), indirizzo: nullable(e[1]) };
  const l = db(`SELECT COALESCE(lingua,'NULL'), COALESCE(genere_id,'NULL') FROM libri WHERE id=${fx.bookId}`).split('\t');
  fx.bookPrior = { lingua: nullable(l[0]), genere_id: nullable(l[1]) };

  fx.biography = `Biografia E2E ${RUN}: scrittrice e <strong>attivista</strong>, autrice di saggi sul lavoro di cura.`;
  db(`UPDATE autori SET biografia=${sqlq(`<p>${fx.biography}</p>`)}, foto='/uploads/autori/e2e-${RUN}.jpg' WHERE id=${fx.authorId}`);
  db(`UPDATE editori SET sito_web='https://editore-e2e-${RUN}.example.org', indirizzo='Via E2E 1\nRoma' WHERE id=${fx.publisherId}`);

  // A parent genre whose only book sits in its subgenre.
  fx.parentGenre = `E2E SEO Padre ${RUN}`;
  db(`INSERT INTO generi (nome, parent_id) VALUES (${sqlq(fx.parentGenre)}, NULL)`);
  fx.parentGenreId = db(`SELECT id FROM generi WHERE nome=${sqlq(fx.parentGenre)}`);
  db(`INSERT INTO generi (nome, parent_id) VALUES (${sqlq(`E2E SEO Figlio ${RUN}`)}, ${fx.parentGenreId})`);
  fx.childGenreId = db(`SELECT id FROM generi WHERE parent_id=${fx.parentGenreId}`);
  db(`UPDATE libri SET lingua='italiano, inglese', genere_id=${fx.childGenreId} WHERE id=${fx.bookId}`);

  fx.eventsSetting = db("SELECT COALESCE(MAX(setting_value),'NULL') FROM system_settings WHERE category='cms' AND setting_key='events_page_enabled'");
  fx.activeEvents = parseInt(db('SELECT COUNT(*) FROM events WHERE is_active = 1'), 10);
});

test.afterAll(() => {
  const p = fx.authorPrior, e = fx.publisherPrior, l = fx.bookPrior;
  if (p) db(`UPDATE autori SET biografia=${sqlq(p.biografia)}, foto=${sqlq(p.foto)} WHERE id=${fx.authorId}`);
  if (e) db(`UPDATE editori SET sito_web=${sqlq(e.sito_web)}, indirizzo=${sqlq(e.indirizzo)} WHERE id=${fx.publisherId}`);
  if (l) db(`UPDATE libri SET lingua=${sqlq(l.lingua)}, genere_id=${l.genere_id === null ? 'NULL' : l.genere_id} WHERE id=${fx.bookId}`);
  if (fx.parentGenreId) db(`DELETE FROM generi WHERE parent_id=${fx.parentGenreId} OR id=${fx.parentGenreId}`);
  db(`DELETE FROM events WHERE slug='e2e-seo-${RUN}'`);
  if (fx.eventsSetting === 'NULL') {
    db("DELETE FROM system_settings WHERE category='cms' AND setting_key='events_page_enabled'");
  } else if (fx.eventsSetting !== undefined) {
    db(`UPDATE system_settings SET setting_value=${sqlq(fx.eventsSetting)} WHERE category='cms' AND setting_key='events_page_enabled'`);
  }
});

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(() => !/accedi|login/.test(window.location.pathname), { timeout: 15000 });
}
async function regenerateSitemap(page) {
  await page.goto(`${BASE}/admin/settings?tab=advanced`);
  const button = page.locator('form[action$="/admin/settings/advanced/regenerate-sitemap"] button[type="submit"]');
  await Promise.all([page.waitForLoadState('networkidle'), button.click()]);
}
async function sitemapLocs(request) {
  const xml = await (await request.get(`${BASE}/sitemap.xml`)).text();
  return [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1].replace(/^https?:\/\/[^/]+/, ''));
}

// ── Not found, soft 404, trailing slash ─────────────────────────────────────
test('404 page: noindex, its own title, no canonical to the missing URL', async ({ request }) => {
  const res = await request.get(`${BASE}/pagina-inesistente-${RUN}`);
  expect(res.status()).toBe(404);
  const h = head(await res.text());
  expect(h.robots).toContain('noindex');
  expect(h.canonical, 'a missing page declares no canonical').toBeNull();
  expect(decode(h.title)).not.toBe(decode(head(await (await request.get(`${BASE}/`)).text()).title));
});

test('pages past the last one answer 404 instead of an empty 200', async ({ request }) => {
  expect((await request.get(`${BASE}/catalogo?page=99999`)).status()).toBe(404);
  const author = `${BASE}/autore/${encodeURIComponent(fx.authorName)}`;
  expect((await request.get(author)).status()).toBe(200);
  expect((await request.get(`${author}?page=99999`)).status()).toBe(404);
  expect((await request.get(`${BASE}/editore/${encodeURIComponent(fx.publisherName)}?page=99999`)).status()).toBe(404);
  expect((await request.get(`${BASE}/genere/${encodeURIComponent(fx.parentGenre)}?page=99999`)).status()).toBe(404);
});

test('trailing slash answers 301 to the address without it', async ({ request }) => {
  for (const [from, to] of [['/catalogo/', '/catalogo'], ['/catalogo/?page=2', '/catalogo?page=2'], ['/contatti/', '/contatti']]) {
    const res = await request.get(`${BASE}${from}`, { maxRedirects: 0 });
    expect(res.status(), from).toBe(301);
    expect(res.headers()['location'], from).toMatch(new RegExp(`${to.replace(/[?]/g, '\\?')}$`));
  }
  const evil = await request.get(`${BASE}//evil.example/`, { maxRedirects: 0 });
  expect(evil.headers()['location'] ?? '', 'never a protocol-relative Location').not.toMatch(/^\/\//);
});

// ── Catalogue ───────────────────────────────────────────────────────────────
test('catalogue: each page has its own title/description, schema names the library', async ({ request }) => {
  const p1 = await (await request.get(`${BASE}/catalogo`)).text();
  const res2 = await request.get(`${BASE}/catalogo?page=2`);
  test.skip(res2.status() === 404, 'the local catalogue has a single page');
  const p2 = await res2.text();
  const [h1, h2] = [head(p1), head(p2)];
  expect(h2.title).not.toBe(h1.title);
  expect(h2.description).not.toBe(h1.description);
  expect(h2.canonical).toMatch(/\/catalogo\?page=2$/);
  expect(decode(h1.description).length).toBeLessThanOrEqual(160);
  const collection = jsonLd(p1).find((o) => o['@type'] === 'CollectionPage');
  expect(collection?.isPartOf?.name).toBeTruthy();
  expect(collection.isPartOf.name).not.toBe('Biblioteca Digitale');
});

// ── Book page ───────────────────────────────────────────────────────────────
test('book: inLanguage is BCP 47, the Library entity carries no generic sentence', async ({ request }) => {
  const res = await request.get(`${BASE}/libro/${fx.bookId}`);
  expect(res.status()).toBe(200);
  const html = await res.text();
  const book = jsonLd(html).find((o) => o['@type'] === 'Book' || (Array.isArray(o['@type']) && o['@type'].includes('Book')));
  expect(book, 'Book JSON-LD present').toBeTruthy();
  expect(book.inLanguage).toEqual(['it', 'en']);
  const library = jsonLd(html).find((o) => o['@type'] === 'Library');
  expect(library?.description ?? '').not.toBe(GENERIC_LIBRARY_SENTENCE);
  expect(html).not.toMatch(/href="[^"]*\/editore\/[^"]*\+[^"]*"/);
});

// ── Author and publisher archives ───────────────────────────────────────────
test('author archive: biography and photo reach meta, og:image and Person', async ({ request }) => {
  const html = await (await request.get(`${BASE}/autore/${encodeURIComponent(fx.authorName)}`)).text();
  const h = head(html);
  expect(decode(h.description)).toContain(`Biografia E2E ${RUN}: scrittrice e attivista`);
  expect(h.ogImage).toMatch(new RegExp(`^https?://[^/]+/uploads/autori/e2e-${RUN}\\.jpg$`));
  const person = jsonLd(html).find((o) => o['@type'] === 'CollectionPage')?.mainEntity;
  expect(person?.['@type']).toBe('Person');
  expect(person.description).toContain(`Biografia E2E ${RUN}`);
  expect(person.image).toMatch(/^https?:\/\//);
  // Internal links use the canonical encoding (%20), not "+".
  expect(html).not.toMatch(/href="[^"]*\/(autore|editore|genere)\/[^"?]*\+[^"]*"/);
});

test('publisher archive: Organization with website and one-line address', async ({ request }) => {
  const html = await (await request.get(`${BASE}/editore/${encodeURIComponent(fx.publisherName)}`)).text();
  const org = jsonLd(html).find((o) => o['@type'] === 'CollectionPage')?.mainEntity;
  expect(org?.['@type']).toBe('Organization');
  expect(org.url).toBe(`https://editore-e2e-${RUN}.example.org`);
  expect(org.address).toBe('Via E2E 1 Roma');
});

// ── Service pages ───────────────────────────────────────────────────────────
test('contact, privacy and cookies pages each describe themselves', async ({ request }) => {
  const descriptions = [];
  for (const path of ['/', '/contatti', '/privacy', '/cookies']) {
    const res = await request.get(`${BASE}${path}`);
    expect(res.status(), path).toBe(200);
    descriptions.push(decode(head(await res.text()).description));
  }
  expect(new Set(descriptions).size, descriptions.join(' | ')).toBe(descriptions.length);
});

// ── Events ──────────────────────────────────────────────────────────────────
test('events listing: og:image exists, empty listing is noindex', async ({ request }) => {
  const res = await request.get(`${BASE}/eventi`);
  test.skip(res.status() === 404, 'events page disabled on this install');
  const h = head(await res.text());
  expect(h.ogImage).toBeTruthy();
  expect((await request.get(h.ogImage)).status(), h.ogImage).toBe(200);
  if (fx.activeEvents === 0) expect(h.robots).toContain('noindex');
  else expect(h.robots ?? '').not.toContain('noindex');
});

// ── Emeroteca ───────────────────────────────────────────────────────────────
test('emeroteca issue: own description and its cover as og:image', async ({ request }) => {
  let row = '';
  try {
    row = db("SELECT f.id, f.copertina_url FROM emeroteca_fascicoli f WHERE f.stato <> 'scartato' AND f.copertina_url LIKE '/uploads/%' ORDER BY f.id LIMIT 1");
  } catch { row = ''; }
  test.skip(row === '', 'no emeroteca issue with a cover');
  const [issueId, cover] = row.split('\t');
  const res = await request.get(`${BASE}/emeroteca/fascicolo/${issueId}`);
  expect(res.status()).toBe(200);
  const h = head(await res.text());
  expect(decode(h.description)).not.toBe(decode(h.title).replace(/ – .*$/, '').replace(/ — Emeroteca$/, ''));
  expect(decode(h.description).length).toBeGreaterThan(30);
  expect(h.ogImage).toMatch(new RegExp(`${cover.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`));
});

// ── Sitemap and llms.txt (admin-driven) ─────────────────────────────────────
test('sitemap: no feed.xml, parent genres in, events follow the events page switch', async ({ page, request }) => {
  await login(page);
  db(`INSERT INTO events (title, slug, content, event_date, is_active) VALUES ('Evento E2E ${RUN}', 'e2e-seo-${RUN}', '<p>x</p>', CURDATE(), 1)`);
  db("INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('cms','events_page_enabled','1') ON DUPLICATE KEY UPDATE setting_value='1'");

  await regenerateSitemap(page);
  let locs = await sitemapLocs(request);
  expect(locs).not.toContain('/feed.xml');
  expect(locs).toContain(`/genere/${encodeURIComponent(fx.parentGenre)}`);
  expect(locs).toContain('/eventi');
  expect(locs).toContain(`/eventi/e2e-seo-${RUN}`);
  for (const loc of locs.filter((l) => /^\/(editore|genere)\//.test(l)).slice(0, 3)) {
    const xml = await (await request.get(`${BASE}/sitemap.xml`)).text();
    expect(xml, `lastmod for ${loc}`).toMatch(new RegExp(`${loc.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}</loc><lastmod>`));
  }

  db("UPDATE system_settings SET setting_value='0' WHERE category='cms' AND setting_key='events_page_enabled'");
  await regenerateSitemap(page);
  locs = await sitemapLocs(request);
  expect(locs.some((l) => l === '/eventi' || l.startsWith('/eventi/')), 'events page off → no event URLs').toBe(false);
});

test('llms.txt and home schema keep a multi-line site description on one line', async ({ page, request }) => {
  const prior = {
    footer: db("SELECT COALESCE(MAX(setting_value),'') FROM system_settings WHERE category='app' AND setting_key='footer_description'"),
    llms: db("SELECT COALESCE(MAX(setting_value),'0') FROM system_settings WHERE category='seo' AND setting_key='llms_txt_enabled'"),
  };
  await login(page);
  const saveFooter = async (value) => {
    await page.goto(`${BASE}/admin/settings`);
    const field = page.locator('#footer_description');
    await field.fill(value);
    await Promise.all([page.waitForLoadState('networkidle'), field.evaluate((el) => el.closest('form')?.requestSubmit())]);
  };
  const saveLlms = async (on) => {
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    const box = page.locator('#llms_txt_enabled');
    if (on) await box.check({ force: true }); else await box.uncheck({ force: true });
    await Promise.all([page.waitForLoadState('networkidle'), box.evaluate((el) => el.closest('form')?.requestSubmit())]);
  };
  try {
    await saveFooter(`Riga uno E2E ${RUN}\nRiga due`);
    await saveLlms(true);

    const llms = await (await request.get(`${BASE}/llms.txt`)).text();
    const quote = llms.split('\n').find((line) => line.startsWith('> '));
    expect(quote).toContain(`Riga uno E2E ${RUN} Riga due`);

    const library = jsonLd(await (await request.get(`${BASE}/`)).text()).find((o) => o['@type'] === 'Library');
    expect(library, 'home publishes the Library entity').toBeTruthy();
    expect(library.description).toBe(`Riga uno E2E ${RUN} Riga due`);
  } finally {
    await saveFooter(prior.footer);
    await saveLlms(prior.llms === '1');
  }
});
