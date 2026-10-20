// @ts-check
// Issue #480 — every kind of account, as a person uses Pinakes. Each one signs in through
// the login page, opens the book page, downloads the PDFs and plays (and seeks in) the
// audiobooks it may open, and is refused the others, also when it follows a link someone
// shared. The administrator hands access out through the real admin pages (the roles
// matrix, the user form) and takes it back while the reader is listening. The database
// only prepares the book, the files, the accounts and a loan, and checks the result.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const ROOT = path.join(__dirname, '..');
const STORAGE = path.join(ROOT, 'storage/digital');
test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_NAME, 'E2E credentials required');

function db(sql) {
  const args = ['-u', process.env.E2E_DB_USER, process.env.E2E_DB_NAME, '-N', '-B', '-e', sql];
  if (process.env.E2E_DB_HOST) {
    args.unshift('-h', process.env.E2E_DB_HOST, '-P', process.env.E2E_DB_PORT || '3306');
  } else if (process.env.E2E_DB_SOCKET) args.unshift('-S', process.env.E2E_DB_SOCKET);
  return execFileSync('mysql', args, { encoding: 'utf8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS } }).trim();
}

/** Four seconds of a quiet tone, 8 kHz mono PCM: long enough to seek in. */
function wav(seconds = 4, rate = 8000) {
  const samples = seconds * rate;
  const data = Buffer.alloc(samples * 2);
  for (let i = 0; i < samples; i++) data.writeInt16LE(Math.round(Math.sin(i / 8) * 2000), i * 2);
  const head = Buffer.alloc(44);
  head.write('RIFF', 0); head.writeUInt32LE(36 + data.length, 4); head.write('WAVE', 8);
  head.write('fmt ', 12); head.writeUInt32LE(16, 16); head.writeUInt16LE(1, 20); head.writeUInt16LE(1, 22);
  head.writeUInt32LE(rate, 24); head.writeUInt32LE(rate * 2, 28); head.writeUInt16LE(2, 32); head.writeUInt16LE(16, 34);
  head.write('data', 36); head.writeUInt32LE(data.length, 40);
  return Buffer.concat([head, data]);
}

async function signIn(page, email, password) {
  await page.goto(`${BASE}/admin/dashboard`);
  if (await page.locator('input[name=email]').isVisible()) {
    await page.locator('input[name=email]').fill(email);
    await page.locator('input[name=password]').fill(password);
    await page.locator('button[type=submit]').click();
    await page.waitForURL(u => !/accedi|login/.test(u.pathname));
  }
}

/** Save the user form as an admin does: fields this installation requires get a value first. */
async function saveUserForm(page) {
  const required = page.locator('#user-form [required]');
  for (let i = 0; i < await required.count(); i++) {
    const field = required.nth(i);
    if (!(await field.isVisible()) || (await field.inputValue()) !== '') continue;
    const type = await field.getAttribute('type');
    const tag = await field.evaluate(el => el.tagName.toLowerCase());
    if (tag === 'select') await field.selectOption({ index: 1 });
    else await field.fill(type === 'date' ? '1990-05-17' : type === 'tel' ? '+39 0123 456789' : 'Via Roma 1, Torino');
  }
  await page.locator('#user-form button[type=submit]').click();
  await page.waitForURL(u => !/\/admin\/users\/edit\//.test(u.pathname));
}

test.describe.serial('Digital Library: what each account may read and play (#480)', () => {
  const RUN = Date.now();
  const tag = `roles480${RUN}`;
  const password = `Pk480-${RUN}-r`;
  const pdf = fs.readFileSync(path.join(__dirname, 'fixtures/archive-test.pdf'));
  const audio = wav();
  // One PDF and one audiobook per visibility.
  const files = {};
  for (const v of ['public', 'users', 'staff', 'loan']) {
    files[`pdf-${v}`] = { name: `${tag}_${v}.pdf`, label: `PDF ${v}`, kind: 'ebook', visibility: v, bytes: pdf };
    files[`audio-${v}`] = { name: `${tag}_${v}.wav`, label: `Audio ${v}`, kind: 'audio', visibility: v, bytes: audio };
  }
  const people = {};
  let bookId;
  let roleId;
  let saved;

  test.beforeAll(() => {
    saved = {
      plugin: db("SELECT is_active FROM plugins WHERE name='digital-library'"),
      settings: db("SELECT CONCAT(setting_key,'=',COALESCE(setting_value,'')) FROM system_settings WHERE category='digital_library' AND setting_key IN ('default_visibility','show_locked_entries')"),
    };
    db("UPDATE plugins SET is_active=1 WHERE name='digital-library'");
    db("DELETE FROM system_settings WHERE category='digital_library' AND setting_key IN ('default_visibility','show_locked_entries')");
    fs.mkdirSync(STORAGE, { recursive: true });
    for (const f of Object.values(files)) fs.writeFileSync(path.join(STORAGE, f.name), f.bytes);
    const list = Object.values(files).map(f => ({ url: `/uploads/digital/${f.name}`, label: f.label, kind: f.kind, visibility: f.visibility }));
    const json = JSON.stringify(list).replace(/'/g, "''");
    bookId = Number(db(`INSERT INTO libri (titolo, digital_attachments, file_url, audio_url, created_at, updated_at) VALUES ('${tag}', '${json}', '/uploads/digital/${files['pdf-public'].name}', '/uploads/digital/${files['audio-public'].name}', NOW(), NOW()); SELECT LAST_INSERT_ID();`));
    const hash = execFileSync('php', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', password], { encoding: 'utf8' });
    const preset = name => db(`SELECT id FROM ruoli WHERE nome='${name}'`) || 'NULL';
    const accounts = {
      reader: ['standard', 'NULL'],
      borrower: ['standard', 'NULL'],
      cataloguer: ['standard', preset('Catalogatore')],
      archivist: ['standard', preset('Archivista')],
      conductor: ['premium', 'NULL'], // gets a role through the admin pages
      granted: ['standard', 'NULL'],  // gets the individual grant through the admin pages
      staff: ['staff', 'NULL'],
    };
    for (const [who, [type, role]] of Object.entries(accounts)) {
      const email = `${who}-${tag}@example.test`;
      const id = Number(db(`INSERT INTO utenti (codice_tessera, nome, cognome, email, password, stato, tipo_utente, ruolo_id, email_verificata, privacy_accettata) VALUES ('R${who.slice(0, 3)}${RUN % 100000000}', '${who}', 'Test', '${email}', '${hash}', 'attivo', '${type}', ${role}, 1, 1); SELECT LAST_INSERT_ID();`));
      people[who] = { id, email };
    }
    db(`INSERT INTO prestiti (libro_id, utente_id, data_prestito, data_scadenza, stato, attivo) VALUES (${bookId}, ${people.borrower.id}, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), 'in_corso', 1)`);
  });

  test.afterAll(() => {
    db(`DELETE FROM prestiti WHERE libro_id=${bookId}`);
    db(`DELETE FROM digital_library_files WHERE libro_id=${bookId}`);
    db(`DELETE FROM libri WHERE id=${bookId}`);
    db(`DELETE FROM utenti WHERE email LIKE '%-${tag}@example.test'`);
    db(`DELETE FROM ruoli WHERE nome='Maestro ${tag}'`);
    db("DELETE FROM system_settings WHERE category='digital_library' AND setting_key IN ('default_visibility','show_locked_entries')");
    for (const line of saved.settings ? saved.settings.split('\n') : []) {
      const [key, value] = line.split('=');
      db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('digital_library', '${key}', '${value}')`);
    }
    if (saved.plugin !== '') db(`UPDATE plugins SET is_active=${Number(saved.plugin)} WHERE name='digital-library'`);
    for (const f of Object.values(files)) fs.rmSync(path.join(STORAGE, f.name), { force: true });
  });

  test('the admin creates a "Maestro" role that only opens restricted attachments', async ({ page }) => {
    await signIn(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASS);
    await page.goto(`${BASE}/admin/roles`);
    await page.locator('#new-role-name').fill(`Maestro ${tag}`);
    await page.locator('input[name="new_role[permissions][]"][value="digital.access"]').check();
    await page.getByRole('button', { name: 'Salva ruoli e permessi' }).click();
    await expect(page.locator('body')).toContainText('Ruoli e permessi aggiornati');
    roleId = Number(db(`SELECT id FROM ruoli WHERE nome='Maestro ${tag}'`));
    expect(JSON.parse(db(`SELECT permessi FROM ruoli WHERE id=${roleId}`))).toEqual(['digital.access']);
  });

  test('the admin gives the conductor that role, and a reader the individual grant, from the user form', async ({ page }) => {
    await signIn(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASS);
    await page.goto(`${BASE}/admin/users/edit/${people.conductor.id}`);
    await page.locator('#ruolo_id').selectOption({ label: `Maestro ${tag}` });
    await saveUserForm(page);
    expect(Number(db(`SELECT ruolo_id FROM utenti WHERE id=${people.conductor.id}`))).toBe(roleId);
    expect(db(`SELECT tipo_utente FROM utenti WHERE id=${people.conductor.id}`)).toBe('premium'); // membership untouched

    await page.goto(`${BASE}/admin/users/edit/${people.granted.id}`);
    await page.locator('input[name=accesso_digitale]').check();
    await saveUserForm(page);
    expect(db(`SELECT accesso_digitale FROM utenti WHERE id=${people.granted.id}`)).toBe('1');
  });

  // What each account finds on the book page: 'open', 'login' (sign in to open), 'loan'
  // (only for the borrower) or 'hidden' (staff-only files are not listed at all).
  const everything = { public: 'open', users: 'open', staff: 'open', loan: 'open' };
  const expectations = {
    anonymous: { public: 'open', users: 'login', staff: 'hidden', loan: 'login' },
    reader: { public: 'open', users: 'open', staff: 'hidden', loan: 'loan' },
    cataloguer: { public: 'open', users: 'open', staff: 'hidden', loan: 'loan' },
    borrower: { public: 'open', users: 'open', staff: 'hidden', loan: 'open' },
    conductor: everything,
    granted: everything,
    archivist: everything,
    staff: everything,
  };

  for (const [who, expected] of Object.entries(expectations)) {
    test(`${who}: sees, downloads and plays exactly what the account may open`, async ({ browser }) => {
      const context = await browser.newContext({ acceptDownloads: true });
      const page = await context.newPage();
      if (who !== 'anonymous') await signIn(page, people[who].email, password);
      if (who === 'archivist') {
        // The public header names the custom role, not "Staff".
        await page.goto(`${BASE}/`);
        await expect(page.locator('a.btn-primary-header')).toContainText('Archivista');
      }
      await page.goto(`${BASE}/libro/${bookId}`);
      const list = page.locator('.digital-attachments-list');
      await expect(list).toBeVisible();

      for (const f of Object.values(files)) {
        const state = expected[f.visibility];
        const card = list.locator('.digital-attachment', { hasText: f.label });
        const url = `${BASE}/uploads/digital/${f.name}`;
        if (state === 'hidden') {
          await expect(card, `${f.label} is not listed`).toHaveCount(0);
        } else if (state === 'login') {
          await expect(card.locator('.digital-attachment__login'), `${f.label}: sign in to open`).toBeVisible();
        } else if (state === 'loan') {
          await expect(card.locator('.digital-attachment__locked'), `${f.label}: for the borrower`).toContainText('Per chi ha il libro in prestito');
        }
        if (state !== 'open') {
          // No address on the page, and the shared link does not open it either.
          await expect(page.locator(`[href*="${f.name}"], [src*="${f.name}"]`)).toHaveCount(0);
          const direct = await page.request.get(url, { maxRedirects: 0, headers: f.kind === 'audio' ? { Range: 'bytes=0-' } : {} });
          expect(direct.status(), `${who} following a shared link to ${f.label}`).toBe(state === 'hidden' || state === 'loan' ? 403 : 302);
          continue;
        }

        if (f.kind === 'ebook') {
          // "Scarica" saves the file, byte for byte.
          const [download] = await Promise.all([page.waitForEvent('download'), card.locator('.digital-attachment__download').click()]);
          expect(fs.readFileSync(await download.path()).equals(f.bytes), `${who} downloads ${f.label} intact`).toBe(true);
        } else {
          // The player plays, then jumps forward: every byte range comes from Pinakes.
          const ranges = [];
          page.on('response', r => { if (r.url().includes(f.name)) ranges.push(r.status()); });
          const player = card.locator('audio');
          await expect(player).toHaveCount(1);
          const played = await player.evaluate(async a => {
            a.muted = true;
            await a.play();
            await new Promise(r => (a.currentTime > 0.2 ? r() : a.addEventListener('timeupdate', function t() { if (a.currentTime > 0.2) { a.removeEventListener('timeupdate', t); r(); } })));
            a.currentTime = 3;
            await new Promise(r => a.addEventListener('seeked', r, { once: true }));
            a.pause();
            return { error: a.error ? a.error.code : null, time: a.currentTime, duration: a.duration };
          });
          expect(played.error, `${who} plays ${f.label}`).toBeNull();
          expect(played.duration).toBeGreaterThan(3.5);
          expect(played.time).toBeGreaterThanOrEqual(2.9);
          expect(ranges.length).toBeGreaterThan(0);
          expect(ranges.every(s => s === 200 || s === 206), `range answers: ${ranges.join(',')}`).toBe(true);
          // A jump forward asks "from byte N to the end": the bytes must be those of the file.
          const from = 20000;
          const part = await page.request.get(url, { headers: { Range: `bytes=${from}-` } });
          expect(part.status()).toBe(206);
          expect(part.headers()['content-range']).toBe(`bytes ${from}-${f.bytes.length - 1}/${f.bytes.length}`);
          expect((await part.body()).equals(f.bytes.subarray(from)), `${who} seeking in ${f.label} gets the right bytes`).toBe(true);
        }
      }
      await context.close();
    });
  }

  test('taking the grant back stops the reader in the middle of the audiobook', async ({ browser, page }) => {
    const listener = await browser.newContext();
    const ear = await listener.newPage();
    await signIn(ear, people.granted.email, password);
    await ear.goto(`${BASE}/libro/${bookId}`);
    const url = `${BASE}/uploads/digital/${files['audio-staff'].name}`;
    expect((await ear.request.get(url, { headers: { Range: 'bytes=0-999' } })).status()).toBe(206);

    // Meanwhile the admin unticks the grant on the reader's record.
    await signIn(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASS);
    await page.goto(`${BASE}/admin/users/edit/${people.granted.id}`);
    await page.locator('input[name=accesso_digitale]').uncheck();
    await saveUserForm(page);
    expect(db(`SELECT accesso_digitale FROM utenti WHERE id=${people.granted.id}`)).toBe('0');

    // The very next range of the same session is refused, and the page no longer lists the file.
    expect((await ear.request.get(url, { headers: { Range: 'bytes=1000-1999' } })).status()).toBe(403);
    await ear.reload();
    await expect(ear.locator('.digital-attachment', { hasText: 'Audio staff' })).toHaveCount(0);
    await expect(ear.locator('.digital-attachment', { hasText: 'Audio users' }).locator('audio')).toHaveCount(1);
    await listener.close();
  });

  test('emptying the "Maestro" role closes the restricted files to the conductor at once', async ({ browser, page }) => {
    const conductor = await browser.newContext();
    const ear = await conductor.newPage();
    await signIn(ear, people.conductor.email, password);
    const url = `${BASE}/uploads/digital/${files['pdf-loan'].name}`;
    expect((await ear.request.get(url, { maxRedirects: 0 })).status()).toBe(200);

    await signIn(page, process.env.E2E_ADMIN_EMAIL, process.env.E2E_ADMIN_PASS);
    await page.goto(`${BASE}/admin/roles`);
    await page.locator(`input[name="roles[${roleId}][permissions][]"][value="digital.access"]`).uncheck();
    await page.getByRole('button', { name: 'Salva ruoli e permessi' }).click();
    await expect(page.locator('body')).toContainText('Ruoli e permessi aggiornati');

    expect((await ear.request.get(url, { maxRedirects: 0 })).status()).toBe(403);
    await conductor.close();
  });
});
