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
const path = require('path');
const { flushCache } = require('./helpers/flush-cache');

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
  // Strip only the final newline: trim() would also eat a trailing tab, i.e.
  // the last column when it is an empty string, and shift the split below.
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).replace(/\r?\n$/, '');
}
function sqlq(s) { return s === null ? 'NULL' : "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }

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

function invalidateSitemapCache() {
  // The test regenerates public/sitemap.xml while its fixture genre exists;
  // once the fixture is gone the published file would list a 404.
  execFileSync('php', ['-r', 'require $argv[1]; App\\Support\\SitemapCache::invalidate("SEO E2E fixture cleanup");',
    path.join(__dirname, '..', 'vendor', 'autoload.php')], { timeout: 10000 });
}
async function invalidateBookCache() {
  execFileSync('php', ['-r', 'require $argv[1]; App\\Support\\ContentCache::booksChanged();',
    path.join(__dirname, '..', 'vendor', 'autoload.php')], { timeout: 10000 });
  // With APCu the page cache lives in the web server's memory, out of reach
  // of the CLI bump above: flush it from inside the app process too.
  await flushCache();
}

test.beforeAll(async () => {
  // The spec creates every record it reads: which titles, authors and
  // publishers survive the specs that run before it depends on the CI shard.
  // An author and a publisher whose names contain a space (URL encoding), a
  // book in a subgenre whose parent has no book of its own.
  fx.authorName = `E2E Autrice ${RUN}`;
  fx.publisherName = `E2E Editore ${RUN}`;
  fx.biography = `Biografia E2E ${RUN}: scrittrice e <strong>attivista</strong>, autrice di saggi sul lavoro di cura.`;
  db(`INSERT INTO autori (nome, biografia, foto) VALUES (${sqlq(fx.authorName)}, ${sqlq(`<p>${fx.biography}</p>`)}, '/uploads/autori/e2e-${RUN}.jpg')`);
  fx.authorId = db(`SELECT id FROM autori WHERE nome=${sqlq(fx.authorName)}`);
  db(`INSERT INTO editori (nome, sito_web, indirizzo) VALUES (${sqlq(fx.publisherName)}, 'https://editore-e2e-${RUN}.example.org', 'Via E2E 1\nRoma')`);
  fx.publisherId = db(`SELECT id FROM editori WHERE nome=${sqlq(fx.publisherName)}`);

  fx.parentGenre = `E2E SEO Padre ${RUN}`;
  db(`INSERT INTO generi (nome, parent_id) VALUES (${sqlq(fx.parentGenre)}, NULL)`);
  fx.parentGenreId = db(`SELECT id FROM generi WHERE nome=${sqlq(fx.parentGenre)}`);
  db(`INSERT INTO generi (nome, parent_id) VALUES (${sqlq(`E2E SEO Figlio ${RUN}`)}, ${fx.parentGenreId})`);
  fx.childGenreId = db(`SELECT id FROM generi WHERE parent_id=${fx.parentGenreId}`);

  const title = `E2E SEO Libro ${RUN}`;
  db(`INSERT INTO libri (titolo, editore_id, genere_id, lingua, copie_totali, copie_disponibili, stato)
      VALUES (${sqlq(title)}, ${fx.publisherId}, ${fx.childGenreId}, 'italiano, inglese', 1, 1, 'disponibile')`);
  fx.bookId = db(`SELECT id FROM libri WHERE titolo=${sqlq(title)} AND deleted_at IS NULL`);
  db(`INSERT INTO libri_autori (libro_id, autore_id, ruolo) VALUES (${fx.bookId}, ${fx.authorId}, 'principale')`);
  await invalidateBookCache();

  fx.eventsSetting = db("SELECT COALESCE(MAX(setting_value),'NULL') FROM system_settings WHERE category='cms' AND setting_key='events_page_enabled'");
  fx.activeEvents = parseInt(db('SELECT COUNT(*) FROM events WHERE is_active = 1'), 10);
});

test.afterAll(async () => {
  if (fx.bookId) {
    db(`DELETE FROM libri_autori WHERE libro_id=${fx.bookId}`);
    db(`DELETE FROM libri WHERE id=${fx.bookId}`);
  }
  if (fx.authorId) db(`DELETE FROM autori WHERE id=${fx.authorId}`);
  if (fx.publisherId) db(`DELETE FROM editori WHERE id=${fx.publisherId}`);
  if (fx.parentGenreId) {
    // Delete children first: MariaDB can otherwise visit a child already
    // removed by the parent's ON DELETE CASCADE within the same DELETE.
    db(`DELETE FROM generi WHERE parent_id=${fx.parentGenreId}`);
    db(`DELETE FROM generi WHERE id=${fx.parentGenreId}`);
  }
  await invalidateBookCache();
  db(`DELETE FROM events WHERE slug='e2e-seo-${RUN}'`);
  if (fx.eventsSetting === 'NULL') {
    db("DELETE FROM system_settings WHERE category='cms' AND setting_key='events_page_enabled'");
  } else if (fx.eventsSetting !== undefined) {
    db(`UPDATE system_settings SET setting_value=${sqlq(fx.eventsSetting)} WHERE category='cms' AND setting_key='events_page_enabled'`);
  }
  invalidateSitemapCache();
});

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(() => !/accedi|login/.test(window.location.pathname), undefined, { timeout: 15000 });
}
/** Submit a settings form and wait for its POST answer and the page it redirects to. */
async function submitAndSettle(page, postPath, submit) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'load' }),
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith(postPath)),
    submit(),
  ]);
}
async function regenerateSitemap(page) {
  await page.goto(`${BASE}/admin/settings?tab=advanced`);
  const button = page.locator('form[action$="/admin/settings/advanced/regenerate-sitemap"] button[type="submit"]');
  await submitAndSettle(page, '/admin/settings/advanced/regenerate-sitemap', () => button.click());
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
    await submitAndSettle(page, '/admin/settings/general', () => field.evaluate((el) => el.closest('form')?.requestSubmit()));
  };
  const saveLlms = async (on) => {
    await page.goto(`${BASE}/admin/settings?tab=advanced`);
    const box = page.locator('#llms_txt_enabled');
    if (on) await box.check({ force: true }); else await box.uncheck({ force: true });
    await submitAndSettle(page, '/admin/settings/advanced', () => box.evaluate((el) => el.closest('form')?.requestSubmit()));
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
