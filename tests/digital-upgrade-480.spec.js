// @ts-check
// Issue #480 — Digital Library files uploaded on an older release, then the upgrade.
//
// Run twice by scripts/reinstall-test.sh (Test B), against the same sandbox install:
//   E2E_UPGRADE_PHASE=before  on the old release (files go to public/uploads/digital and
//                             anyone can download them), the admin uploads through the
//                             book form like a person would;
//   E2E_UPGRADE_PHASE=after   on the upgraded install: the same files, byte for byte, now
//                             in storage/digital, still served at the same addresses, and
//                             the per-file visibility of 0.8.6 enforced on them.
// The state between the two runs (book id, addresses, checksums) is a JSON file.
// The database is only used to prepare the book and a reader, never to upload or check.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ROOT = process.env.E2E_INSTALL_ROOT || path.join(__dirname, '..');
const PHASE = process.env.E2E_UPGRADE_PHASE || '';
const STATE = process.env.E2E_UPGRADE_STATE || path.join(ROOT, 'storage/tmp/digital-upgrade-480.json');
const STORAGE = path.join(ROOT, 'storage/digital');
const LEGACY = path.join(ROOT, 'public/uploads/digital');
test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME || !PHASE, 'E2E credentials and E2E_UPGRADE_PHASE required');

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}
const sha = buffer => crypto.createHash('sha256').update(buffer).digest('hex');

async function loginWith(page, email, password) {
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=password]').fill(password);
  await page.locator('button[type=submit]').click();
  await page.waitForURL(u => !/accedi|login/.test(u.pathname));
}
async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (await page.locator('input[name=email]').isVisible()) {
    await loginWith(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASS);
  }
}
async function saveBook(page) {
  await page.locator('#bookForm button[type=submit]').click();
  const confirmation = page.locator('.swal2-confirm');
  if (await confirmation.isVisible({ timeout: 3000 }).catch(() => false)) await confirmation.click();
  await page.waitForURL(u => !/\/admin\/books\/(edit|create)/.test(u.pathname));
}
/** The Uppy result alert after each upload: dismissed like a person would. */
async function dismissAlert(page) {
  const ok = page.locator('.swal2-confirm');
  if (await ok.isVisible({ timeout: 3000 }).catch(() => false)) await ok.click();
}

const pdf = fs.readFileSync(path.join(__dirname, 'fixtures/archive-test.pdf'));
// The audio uploader of the old release takes MP3, M4A or OGG: an ID3 header and eight
// silent MPEG-1 Layer III frames, which the server's content check reads as audio/mpeg.
const mp3 = Buffer.concat([Buffer.from('ID3\x03\x00\x00\x00\x00\x00\x00', 'latin1'),
  ...Array.from({ length: 8 }, () => Buffer.concat([Buffer.from([0xff, 0xfb, 0x90, 0x64]), Buffer.alloc(413)]))]);

test.describe.serial(`Digital Library across the upgrade (#480) — ${PHASE}`, () => {
  test.skip(PHASE !== 'before', 'before-upgrade phase only');

  /** @type {{bookId:number, files:Record<string,{url:string,sha:string,size:number}>}} */
  const state = { bookId: 0, files: {} };

  test('the digital-library plugin is active on the old release', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/plugins`);
    const id = db("SELECT id FROM plugins WHERE name='digital-library'");
    expect(id, 'digital-library registered').not.toBe('');
    if (db("SELECT is_active FROM plugins WHERE name='digital-library'") !== '1') {
      // The card of this plugin, found by its title like a person would.
      const card = page.locator('[data-plugin-id]').filter({ has: page.getByRole('heading', { name: /Digital Library/ }) }).first();
      await expect(card).toBeVisible();
      await card.getByRole('button', { name: 'Attiva plugin' }).click();
      const activated = page.waitForResponse(r => r.url().includes(`/admin/plugins/${id}/activate`));
      await page.locator('.swal2-confirm:visible').click();
      const response = await activated;
      const body = await response.json().catch(() => ({}));
      expect(response.status(), JSON.stringify(body)).toBe(200);
      expect(body.success, JSON.stringify(body)).toBe(true);
      await page.locator('.swal2-confirm:visible').click();
    }
    await expect.poll(() => db("SELECT is_active FROM plugins WHERE name='digital-library'")).toBe('1');
  });

  test('the admin uploads two PDFs and an audiobook through the book form', async ({ page }) => {
    const title = `Upgrade480-${Date.now()}`;
    db(`INSERT INTO libri (titolo, created_at, updated_at) VALUES ('${title}', NOW(), NOW())`);
    state.bookId = Number(db(`SELECT id FROM libri WHERE titolo='${title}' AND deleted_at IS NULL`));
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/books/edit/${state.bookId}`);

    await page.locator('#upload-ebook-btn').click();
    await page.locator('#ebook-uploader input[type=file]').setInputFiles([
      { name: 'upgrade-480-public.pdf', mimeType: 'application/pdf', buffer: pdf },
      { name: 'upgrade-480-readers.pdf', mimeType: 'application/pdf', buffer: pdf },
    ]);
    const rows = page.locator('[data-attachment-row]');
    await expect(rows).toHaveCount(2, { timeout: 20000 });
    await dismissAlert(page);

    await page.locator('#upload-audio-btn').click();
    await page.locator('#audio-uploader input[type=file]').setInputFiles(
      { name: 'upgrade-480-audio.mp3', mimeType: 'audio/mpeg', buffer: mp3 });
    await expect(rows).toHaveCount(3, { timeout: 20000 });
    await dismissAlert(page);

    const labels = ['Public edition', 'Readers edition', 'Audiobook'];
    for (const [i, label] of labels.entries()) {
      await rows.nth(i).locator('input[name$="[label]"]').fill(label);
      const url = await rows.nth(i).locator('input[name$="[url]"]').inputValue();
      const buffer = i === 2 ? mp3 : pdf;
      state.files[label] = { url, sha: sha(buffer), size: buffer.length };
    }
    await saveBook(page);

    // The old release keeps them in the public uploads folder, as uploaded.
    for (const { url, sha: digest } of Object.values(state.files)) {
      expect(url).toMatch(/^\/uploads\/digital\/[A-Za-z0-9._-]+\.(pdf|mp3)$/);
      const file = path.join(LEGACY, path.basename(url));
      expect(fs.existsSync(file), `${url} in public/uploads/digital`).toBe(true);
      expect(sha(fs.readFileSync(file))).toBe(digest);
    }
    const saved = JSON.parse(db(`SELECT digital_attachments FROM libri WHERE id=${state.bookId}`) || '[]');
    expect(saved.map(a => a.label)).toEqual(labels);
    fs.mkdirSync(path.dirname(STATE), { recursive: true });
    fs.writeFileSync(STATE, JSON.stringify(state, null, 2));
  });

  test('on the old release an anonymous visitor sees and downloads every file', async ({ page, request }) => {
    await page.goto(`${BASE}/libro/${state.bookId}`);
    const list = page.locator('.digital-attachments-list');
    for (const label of Object.keys(state.files)) await expect(list).toContainText(label);
    for (const { url, sha: digest, size } of Object.values(state.files)) {
      const response = await request.get(`${BASE}${url}`, { maxRedirects: 0 });
      expect(response.status(), url).toBe(200);
      expect(sha(await response.body())).toBe(digest);
      const range = await request.get(`${BASE}${url}`, { maxRedirects: 0, headers: { Range: 'bytes=0-99' } });
      expect(range.status(), `${url} with Range`).toBe(206);
      expect((await range.body()).length).toBe(Math.min(100, size));
    }
  });
});

test.describe.serial('Digital Library across the upgrade (#480) — after', () => {
  test.skip(PHASE !== 'after', 'after-upgrade phase only');

  const RUN = Date.now();
  const password = `Pk480u-${RUN}-x`;
  const reader = { email: `upgrade-reader-${RUN}@example.test`, id: 0 };
  /** @type {{bookId:number, files:Record<string,{url:string,sha:string,size:number}>}} */
  let state;

  test.beforeAll(() => {
    state = JSON.parse(fs.readFileSync(STATE, 'utf8'));
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], { encoding: 'utf8' });
    db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES ('U480${RUN % 1000000}', 'Upgrade', 'Reader', '${reader.email}', '${hash}', 'attivo', 'standard', 1, 1)`);
    reader.id = Number(db(`SELECT id FROM utenti WHERE email='${reader.email}'`));
  });

  test('the uploaded files moved to storage/digital, byte for byte, at the same addresses', async ({ page }) => {
    // The first page under the new code finishes whatever the upgrade left to move.
    await page.goto(`${BASE}/libro/${state.bookId}`);
    for (const [label, { url, sha: digest }] of Object.entries(state.files)) {
      const name = path.basename(url);
      expect(fs.existsSync(path.join(STORAGE, name)), `${label} in storage/digital`).toBe(true);
      expect(sha(fs.readFileSync(path.join(STORAGE, name)))).toBe(digest);
      expect(fs.existsSync(path.join(LEGACY, name)), `${label} no longer in public/uploads/digital`).toBe(false);
    }
    const saved = JSON.parse(db(`SELECT digital_attachments FROM libri WHERE id=${state.bookId}`) || '[]');
    expect(saved.map(a => a.url)).toEqual(Object.values(state.files).map(f => f.url));
  });

  test('with the default left as it was, anonymous visitors still open every file', async ({ page, request }) => {
    await page.goto(`${BASE}/libro/${state.bookId}`);
    const list = page.locator('.digital-attachments-list');
    for (const label of Object.keys(state.files)) await expect(list).toContainText(label);
    await expect(list.locator('.digital-attachment--locked')).toHaveCount(0);
    await expect(list.locator('audio source')).toHaveCount(1);
    for (const { url, sha: digest, size } of Object.values(state.files)) {
      const response = await request.get(`${BASE}${url}`, { maxRedirects: 0 });
      expect(response.status(), url).toBe(200);
      expect(sha(await response.body())).toBe(digest);
      const range = await request.get(`${BASE}${url}`, { maxRedirects: 0, headers: { Range: 'bytes=0-99' } });
      expect(range.status(), `${url} with Range`).toBe(206);
      expect(range.headers()['content-encoding'] || '').not.toContain('gzip');
      expect((await range.body()).length).toBe(Math.min(100, size));
    }
  });

  test('the admin limits one file to signed-in readers and one to staff on the upgraded form', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/books/edit/${state.bookId}`);
    const rows = page.locator('[data-attachment-row]');
    await expect(rows).toHaveCount(3);
    await rows.nth(1).locator('select[name$="[visibility]"]').selectOption('users');
    await rows.nth(2).locator('select[name$="[visibility]"]').selectOption('staff');
    await saveBook(page);
    const saved = JSON.parse(db(`SELECT digital_attachments FROM libri WHERE id=${state.bookId}`));
    expect(saved.map(a => a.visibility)).toEqual(['inherit', 'users', 'staff']);
  });

  test('an anonymous visitor is sent to sign in for the readers file and refused the staff one', async ({ page, request }) => {
    const get = (url, headers = {}) => request.get(`${BASE}${url}`, { maxRedirects: 0, headers });
    const { 'Public edition': open, 'Readers edition': readers, Audiobook: staff } = state.files;
    expect((await get(open.url)).status()).toBe(200);
    const toLogin = await get(readers.url);
    expect(toLogin.status()).toBe(302);
    expect(toLogin.headers()['location']).toContain('return_url=');
    expect((await get(staff.url)).status()).toBe(403);
    // Another case of the folder name is no way around the check, nor is a dot segment.
    for (const variant of [readers.url.replace('/digital/', '/Digital/'), readers.url.replace('/uploads/', '/uploads/./')]) {
      const response = await request.get(`${BASE}${variant}`, { maxRedirects: 0 });
      const served = response.status() === 200 && sha(await response.body()) === readers.sha;
      expect(served, `${variant} must not serve the file`).toBe(false);
    }
    await page.goto(`${BASE}/libro/${state.bookId}`);
    const list = page.locator('.digital-attachments-list');
    await expect(list).not.toContainText('Audiobook');
    await expect(list.locator('.digital-attachment--locked', { hasText: 'Readers edition' })).toBeVisible();
    await expect(page.locator(`a[href*="${path.basename(readers.url)}"]`)).toHaveCount(0);
  });

  test('a signed-in reader opens the readers file but not the staff one; the admin opens both', async ({ page, browser }) => {
    const { 'Readers edition': readers, Audiobook: staff } = state.files;
    await page.goto(`${BASE}/libro/${state.bookId}`);
    const bookPath = new URL(page.url()).pathname;
    await page.locator('.digital-attachment--locked', { hasText: 'Readers edition' }).locator('.digital-attachment__login').click();
    await loginWith(page, reader.email, password);
    await page.waitForURL(u => u.pathname === bookPath);
    const asReader = await page.request.get(`${BASE}${readers.url}`, { maxRedirects: 0 });
    expect(asReader.status()).toBe(200);
    expect(sha(await asReader.body())).toBe(readers.sha);
    expect((await page.request.get(`${BASE}${staff.url}`, { maxRedirects: 0 })).status()).toBe(403);

    const admin = await browser.newPage();
    await loginAdmin(admin);
    const asStaff = await admin.request.get(`${BASE}${staff.url}`, { maxRedirects: 0 });
    expect(asStaff.status()).toBe(200);
    expect(sha(await asStaff.body())).toBe(staff.sha);
    await admin.close();
  });

  test.afterAll(() => {
    if (reader.id) db(`DELETE FROM utenti WHERE id=${reader.id}`);
  });
});
