// @ts-check
// Issue #480 — who may open each Digital Library attachment, and where the files live.
// Everything a person does goes through the real pages: the admin uploads and sets the
// visibility in the book form, a reader follows "Accedi per aprirlo", the librarian
// changes the default in the plugin settings. The database is only used to prepare
// readers and a loan, and to check the result.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ROOT = path.join(__dirname, '..');
const STORAGE = path.join(ROOT, 'storage/digital');
const LEGACY = path.join(ROOT, 'public/uploads/digital');
test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME, 'E2E credentials required');

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}
const mode = file => (fs.statSync(file).mode & 0o777).toString(8);
/** Files left in the pre-0.8.6 folder, as on an installation that has just been upgraded. */
function seedLegacy(prefix, count) {
  fs.mkdirSync(LEGACY, { recursive: true });
  const names = [];
  for (let i = 0; i < count; i++) {
    const name = `20250301130000_${prefix}-${String(i).padStart(5, '0')}.pdf`;
    fs.writeFileSync(path.join(LEGACY, name), `%PDF-1.4 ${prefix} ${i}`);
    names.push(name);
  }
  return names;
}
const inLegacy = prefix => (fs.existsSync(LEGACY) ? fs.readdirSync(LEGACY) : []).filter(f => f.includes(prefix)).length;

async function loginWith(page, email, password) {
  await page.locator('input[name=email]').fill(email);
  await page.locator('input[name=password]').fill(password);
  await page.locator('button[type=submit]').click();
}
async function loginAdmin(page) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (await page.locator('input[name=email]').isVisible()) {
    await loginWith(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASS);
    await page.waitForURL(u => !/accedi|login/.test(u.pathname));
  }
}
async function saveBook(page) {
  await page.locator('#bookForm button[type=submit]').click();
  const confirmation = page.locator('.swal2-confirm');
  if (await confirmation.isVisible({ timeout: 3000 }).catch(() => false)) await confirmation.click();
  await page.waitForURL(u => !/\/admin\/books\/(edit|create)/.test(u.pathname));
}

test.describe.serial('Digital Library access control (#480)', () => {
  const RUN = Date.now();
  const marker = `Digital480-${RUN}`;
  const password = `Pk480-${RUN}-x`;
  const reader = { email: `reader-480-${RUN}@example.test` };
  const borrower = { email: `borrower-480-${RUN}@example.test` };
  // One attachment per visibility, in this order in the form.
  const rows = [
    { label: 'Public edition', visibility: 'public' },
    { label: 'Readers edition', visibility: 'users' },
    { label: 'Staff notes', visibility: 'staff' },
    { label: 'Loan edition', visibility: 'loan' },
    { label: 'Default edition', visibility: 'inherit' },
  ];
  const files = {}; // visibility → /uploads/digital/<name>
  const legacyName = `20250301120000_legacy480${RUN}.pdf`;
  let bookId;
  let pluginWasActive;
  let savedSettings;

  test.beforeAll(() => {
    pluginWasActive = db("SELECT is_active FROM plugins WHERE name='digital-library'");
    db("UPDATE plugins SET is_active=1 WHERE name='digital-library'");
    savedSettings = db("SELECT CONCAT(setting_key,'=',COALESCE(setting_value,'')) FROM system_settings WHERE category='digital_library' AND setting_key IN ('default_visibility','show_locked_entries')");
    db("DELETE FROM system_settings WHERE category='digital_library' AND setting_key IN ('default_visibility','show_locked_entries')");
    db(`INSERT INTO libri (titolo, created_at, updated_at) VALUES ('${marker}', NOW(), NOW())`);
    bookId = Number(db(`SELECT id FROM libri WHERE titolo='${marker}'`));
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], { encoding: 'utf8' });
    for (const [person, code] of [[reader, 'R'], [borrower, 'B']]) {
      db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, email_verificata, privacy_accettata) VALUES ('D480${code}${RUN % 1000000}', 'Digital', '${code === 'R' ? 'Reader' : 'Borrower'}', '${person.email}', '${hash}', 'attivo', 'standard', 1, 1)`);
      person.id = Number(db(`SELECT id FROM utenti WHERE email='${person.email}'`));
    }
    db(`INSERT INTO prestiti (libro_id, copia_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (${bookId}, NULL, ${borrower.id}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1)`);
  });

  test.afterAll(() => {
    db(`DELETE FROM prestiti WHERE libro_id=${bookId}`);
    db(`DELETE FROM utenti WHERE id IN (${reader.id || 0}, ${borrower.id || 0})`);
    db(`DELETE FROM digital_library_files WHERE libro_id=${bookId}`);
    db(`DELETE FROM libri WHERE id=${bookId}`);
    db("DELETE FROM system_settings WHERE category='digital_library' AND setting_key IN ('default_visibility','show_locked_entries')");
    for (const line of savedSettings ? savedSettings.split('\n') : []) {
      const [key, value] = line.split('=');
      db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('digital_library', '${key}', '${value}')`);
    }
    if (pluginWasActive !== '') db(`UPDATE plugins SET is_active=${Number(pluginWasActive)} WHERE name='digital-library'`);
    for (const url of [...Object.values(files), `/uploads/digital/${legacyName}`]) {
      for (const dir of [STORAGE, LEGACY]) fs.rmSync(path.join(dir, path.basename(url)), { force: true });
    }
    for (const dir of [STORAGE, LEGACY]) {
      for (const file of fs.existsSync(dir) ? fs.readdirSync(dir) : []) {
        if (file.includes(`bulk480${RUN}`)) fs.rmSync(path.join(dir, file), { force: true });
      }
    }
  });

  test('the admin uploads five PDFs and chooses who may open each one', async ({ page }) => {
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await page.locator('#upload-ebook-btn').click();
    const buffer = fs.readFileSync(path.join(__dirname, 'fixtures/archive-test.pdf'));
    await page.locator('#ebook-uploader input[type=file]').setInputFiles(
      rows.map((row, i) => ({ name: `access-480-${i}.pdf`, mimeType: 'application/pdf', buffer })));
    const formRows = page.locator('[data-attachment-row]');
    await expect(formRows).toHaveCount(rows.length, { timeout: 20000 });
    const confirm = page.locator('.swal2-confirm');
    if (await confirm.isVisible()) await confirm.click();
    for (const [i, row] of rows.entries()) {
      const formRow = formRows.nth(i);
      await formRow.locator('input[name$="[label]"]').fill(row.label);
      await formRow.locator('select[name$="[visibility]"]').selectOption(row.visibility);
      files[row.visibility] = await formRow.locator('input[name$="[url]"]').inputValue();
    }
    await saveBook(page);

    const saved = JSON.parse(db(`SELECT digital_attachments FROM libri WHERE id=${bookId}`));
    expect(saved.map(a => a.visibility)).toEqual(rows.map(r => r.visibility));
    // The files are outside the web root, readable, not executable.
    for (const url of Object.values(files)) {
      const name = path.basename(url);
      expect(url).toMatch(/^\/uploads\/digital\/[A-Za-z0-9._-]+\.pdf$/);
      expect(fs.existsSync(path.join(STORAGE, name)), `${name} in storage/digital`).toBe(true);
      expect(fs.existsSync(path.join(LEGACY, name)), `${name} not in public/uploads/digital`).toBe(false);
      expect(mode(path.join(STORAGE, name))).toBe('644');
    }
    // The form shows the choice again, with the library default spelled out.
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await expect(formRows.nth(2).locator('select[name$="[visibility]"]')).toHaveValue('staff');
    await expect(formRows.nth(4).locator('select[name$="[visibility]"] option[value=inherit]')).toContainText('Chiunque');
  });

  test('an anonymous visitor sees what is open, what needs signing in, and nothing of the staff file', async ({ page, request }) => {
    await page.goto(`${BASE}/libro/${bookId}`);
    const list = page.locator('.digital-attachments-list');
    await expect(list).toContainText('Public edition');
    await expect(list).toContainText('Default edition');
    await expect(list).not.toContainText('Staff notes');
    await expect(list.locator('.digital-attachment--locked')).toHaveCount(2);
    await expect(list.locator('.digital-attachment--locked', { hasText: 'Readers edition' }).locator('.digital-attachment__login')).toBeVisible();
    // Whether a visitor has the book on loan is known only once they sign in: signing in comes first.
    await expect(list.locator('.digital-attachment--locked', { hasText: 'Loan edition' }).locator('.digital-attachment__login')).toBeVisible();
    // No locked card hands out the file address.
    for (const v of ['users', 'staff', 'loan']) {
      await expect(page.locator(`a[href*="${path.basename(files[v])}"]`)).toHaveCount(0);
    }

    const get = (url, headers = {}) => request.get(`${BASE}${url}`, { maxRedirects: 0, headers });
    const open = await get(files.public);
    expect(open.status()).toBe(200);
    expect(open.headers()['content-type']).toContain('application/pdf');
    expect((await get(files.inherit)).status()).toBe(200);
    const toLogin = await get(files.users);
    expect(toLogin.status()).toBe(302);
    expect(toLogin.headers()['location']).toContain('return_url=');
    expect((await get(files.users, { Accept: 'application/json' })).status()).toBe(401);
    expect((await get(files.staff)).status()).toBe(403);
    expect((await get(files.loan)).status()).toBe(302);
  });

  test('"Accedi per aprirlo" signs the reader in and the reader then opens the file', async ({ page }) => {
    await page.goto(`${BASE}/libro/${bookId}`);
    const bookPath = new URL(page.url()).pathname;
    await page.locator('.digital-attachment--locked', { hasText: 'Readers edition' }).locator('.digital-attachment__login').click();
    await expect(page.locator('input[name=email]')).toBeVisible();
    expect(new URL(page.url()).searchParams.get('return_url')).toBe(bookPath);
    await loginWith(page, reader.email, password);
    // Back on the book, where the card is now a link.
    await page.waitForURL(u => u.pathname === bookPath);
    expect((await page.request.get(`${BASE}${files.users}`, { maxRedirects: 0 })).status()).toBe(200);
    expect((await page.request.get(`${BASE}${files.staff}`, { maxRedirects: 0 })).status()).toBe(403);
    expect((await page.request.get(`${BASE}${files.loan}`, { maxRedirects: 0 })).status()).toBe(403);
    await page.goto(`${BASE}/libro/${bookId}`);
    const list = page.locator('.digital-attachments-list');
    await expect(list.locator('.digital-attachment--locked')).toHaveCount(1);
    await expect(list.locator('.digital-attachment--locked')).toContainText('Loan edition');
    await expect(list.locator('.digital-attachment--locked')).toContainText('Per chi ha il libro in prestito');
  });

  test('opening a signed-in-only file while signed out comes back to that file after signing in', async ({ page }) => {
    const response = await page.request.get(`${BASE}${files.users}`, { maxRedirects: 0 });
    const login = new URL(response.headers()['location'], BASE);
    expect(decodeURIComponent(login.searchParams.get('return_url') || '')).toContain(files.users);
    await page.goto(login.href);
    await loginWith(page, reader.email, password);
    // The browser lands on the file (a PDF viewer or a download), not on the login page.
    await expect.poll(async () => (await page.request.get(`${BASE}${files.users}`, { maxRedirects: 0 })).status()).toBe(200);
  });

  test('the reader who has the book on loan opens the loan edition', async ({ page }) => {
    await page.goto(`${BASE}/libro/${bookId}`);
    await page.locator('.digital-attachment--locked', { hasText: 'Readers edition' }).locator('.digital-attachment__login').click();
    await loginWith(page, borrower.email, password);
    await page.waitForURL(u => !/accedi|login/.test(u.pathname));
    expect((await page.request.get(`${BASE}${files.loan}`, { maxRedirects: 0 })).status()).toBe(200);
    expect((await page.request.get(`${BASE}${files.staff}`, { maxRedirects: 0 })).status()).toBe(403);
    await page.goto(`${BASE}/libro/${bookId}`);
    await expect(page.locator('.digital-attachments-list .digital-attachment--locked')).toHaveCount(0);
    await expect(page.locator('.digital-attachments-list')).not.toContainText('Staff notes');
  });

  test('staff open every file, the staff-only one included', async ({ page }) => {
    await loginAdmin(page);
    for (const url of Object.values(files)) {
      expect((await page.request.get(`${BASE}${url}`, { maxRedirects: 0 })).status(), url).toBe(200);
    }
  });

  test('the library default decides the attachments left on "Predefinita"', async ({ page, request }) => {
    await loginAdmin(page);
    const pluginId = db("SELECT id FROM plugins WHERE name='digital-library'");
    await page.goto(`${BASE}/admin/plugins/${pluginId}/settings`);
    await page.locator('#default_visibility').selectOption('users');
    await page.locator('button[name=save_digital_library_settings]').click();
    await expect(page.getByRole('status').filter({ hasText: 'Impostazioni salvate' })).toBeVisible();
    await expect(page.locator('#default_visibility')).toHaveValue('users');
    expect((await request.get(`${BASE}${files.inherit}`, { maxRedirects: 0 })).status()).toBe(302);
    expect((await request.get(`${BASE}${files.public}`, { maxRedirects: 0 })).status()).toBe(200);

    // Without the locked cards, an anonymous visitor sees only what they can open.
    await page.locator('input[name=show_locked_entries]').uncheck();
    await page.locator('button[name=save_digital_library_settings]').click();
    await expect(page.locator('input[name=show_locked_entries]')).not.toBeChecked();
    const visitor = await page.context().browser().newPage();
    await visitor.goto(`${BASE}/libro/${bookId}`);
    // One file left to show: the classic single-file buttons, no locked card, no trace of the others.
    await expect(visitor.locator(`a[href*="${path.basename(files.public)}"]`).first()).toBeVisible();
    await expect(visitor.locator('.digital-attachment--locked')).toHaveCount(0);
    for (const v of ['users', 'staff', 'loan', 'inherit']) {
      await expect(visitor.locator(`[href*="${path.basename(files[v])}"], [src*="${path.basename(files[v])}"]`)).toHaveCount(0);
    }
    await expect(visitor.locator('body')).not.toContainText('Readers edition');
    await visitor.close();
  });

  test('a file still in the old public folder is moved out of it on the next visit, intact', async ({ page, request }) => {
    // The state an installation is in right after upgrading: the file where Pinakes
    // used to keep it, listed by a record.
    const bytes = fs.readFileSync(path.join(__dirname, 'fixtures/archive-test.pdf'));
    fs.mkdirSync(LEGACY, { recursive: true });
    fs.writeFileSync(path.join(LEGACY, legacyName), bytes);
    await loginAdmin(page);
    await page.goto(`${BASE}/admin/books/edit/${bookId}`);
    await page.locator('#add-digital-attachment').click();
    const row = page.locator('[data-attachment-row]').last();
    await row.locator('input[name$="[url]"]').fill(`/uploads/digital/${legacyName}`);
    await row.locator('input[name$="[label]"]').fill('Legacy edition');
    await row.locator('select[name$="[visibility]"]').selectOption('public');
    await saveBook(page);

    const response = await request.get(`${BASE}/uploads/digital/${legacyName}`, { maxRedirects: 0 });
    expect(response.status()).toBe(200);
    expect(Buffer.compare(await response.body(), bytes)).toBe(0);
    await expect.poll(() => fs.existsSync(path.join(STORAGE, legacyName))).toBe(true);
    expect(fs.existsSync(path.join(LEGACY, legacyName))).toBe(false);
    expect(Buffer.compare(fs.readFileSync(path.join(STORAGE, legacyName)), bytes)).toBe(0);
    expect(mode(path.join(STORAGE, legacyName))).toBe('644');
    // No deny rule in the old folder: Apache would apply it before the rewrite to Pinakes.
    const legacyRule = path.join(LEGACY, '.htaccess');
    expect(fs.existsSync(legacyRule) ? fs.readFileSync(legacyRule, 'utf8') : '').not.toContain('Require all denied');
    // The settings page counts nothing left to move.
    const pluginId = db("SELECT id FROM plugins WHERE name='digital-library'");
    await page.goto(`${BASE}/admin/plugins/${pluginId}/settings`);
    await expect(page.locator('body')).toContainText('(public/uploads/digital): 0');
  });

  test('"Sposta ora" moves at once what the page visits have not moved yet', async ({ page }) => {
    // A large library: each page visit moves at most 200 files, so after signing in and
    // opening the settings some are still in the old folder and the button is offered.
    const prefix = `bulk480${RUN}a`;
    const names = seedLegacy(prefix, 2500);
    await loginAdmin(page);
    const pluginId = db("SELECT id FROM plugins WHERE name='digital-library'");
    await page.goto(`${BASE}/admin/plugins/${pluginId}/settings`);
    const left = Number((await page.locator('body').innerText()).match(/\(public\/uploads\/digital\): (\d+)/)[1]);
    expect(left).toBeGreaterThan(0);
    expect(left).toBeLessThan(2500); // the visits did move some on their own
    await page.locator('button[name=move_digital_files]').click();
    await expect(page.getByRole('status').filter({ hasText: 'file spostati nella cartella protetta' })).toBeVisible();
    await expect(page.locator('body')).toContainText('(public/uploads/digital): 0');
    await expect(page.locator('button[name=move_digital_files]')).toHaveCount(0);
    expect(inLegacy(prefix)).toBe(0);
    for (const [i, name] of names.entries()) {
      if (i % 250 !== 0) continue; // a sample: every file is checked by the count above
      expect(fs.readFileSync(path.join(STORAGE, name), 'utf8')).toBe(`%PDF-1.4 ${prefix} ${i}`);
      expect(mode(path.join(STORAGE, name))).toBe('644');
    }
  });

  test('the maintenance pass moves every file, past the page-visit limit', async () => {
    // What the daily cron runs at the end of MaintenanceService::runAll(), without the
    // rest of the maintenance (loan and reservation updates, e-mails) on this database.
    const prefix = `bulk480${RUN}b`;
    seedLegacy(prefix, 600);
    expect(inLegacy(prefix)).toBe(600); // nothing has moved them yet
    const script = `require 'vendor/autoload.php';
      $db = new mysqli('localhost', getenv('E2E_DB_USER'), getenv('E2E_DB_PASS'), getenv('E2E_DB_NAME'), 0, getenv('E2E_DB_SOCKET') ?: null);
      (new App\\Support\\HookManager($db))->doAction('maintenance.after_run');`;
    execFileSync('php', ['-r', script], { cwd: ROOT, env: process.env, encoding: 'utf8' });
    expect(inLegacy(prefix)).toBe(0);
    expect(fs.readdirSync(STORAGE).filter(f => f.includes(prefix)).length).toBe(600);
  });
});
