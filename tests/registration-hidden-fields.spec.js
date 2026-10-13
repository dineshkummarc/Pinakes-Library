// @ts-check
/**
 * Settings -> Registration: hide the optional "sesso" and "codice fiscale"
 * fields.
 *
 * Everything goes through what a person actually does: the administrator
 * unticks the two fields in the Registration tab, a visitor registers through
 * the public form, a member edits their profile, the administrator edits the
 * member, and the Android app reads the Mobile API. Then the administrator
 * ticks them again and every surface shows them once more.
 *
 * Hiding must never delete stored data: a member who already had a gender and
 * a tax code keeps them through profile saves, admin saves and PATCH /me
 * while the fields are hidden, and they reappear intact afterwards.
 */

const { test, expect } = require('@playwright/test');
const { flushCache } = require('./helpers/flush-cache');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const API = `${BASE}/api/v1`;
const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL || '';
const ADMIN_PASS = process.env.E2E_ADMIN_PASS || '';
const DB_USER = process.env.E2E_DB_USER || '';
const DB_PASS = process.env.E2E_DB_PASS || '';
const DB_SOCKET = process.env.E2E_DB_SOCKET || '';
const DB_HOST = process.env.E2E_DB_HOST || '';
const DB_PORT = process.env.E2E_DB_PORT || '';
const DB_NAME = process.env.E2E_DB_NAME || '';

test.skip(!ADMIN_EMAIL || !ADMIN_PASS || !DB_USER || !DB_NAME,
  'E2E credentials not configured (set E2E_ADMIN_EMAIL, E2E_ADMIN_PASS, E2E_DB_*)');

function dbQuery(sql) {
  const args = [];
  if (DB_HOST) {
    args.push('-h', DB_HOST);
    if (DB_PORT) args.push('-P', DB_PORT);
  } else if (DB_SOCKET) {
    args.push('-S', DB_SOCKET);
  }
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', timeout: 10000,
    env: { ...process.env, MYSQL_PWD: DB_PASS },
  }).trim();
}

/** Direct SQL restores bypass SettingsRepository: drop the settings cache. */
async function clearConfigCache() {
  execFileSync('php', ['-r', 'require "vendor/autoload.php"; \\App\\Support\\ConfigStore::clearCache();'], {
    cwd: process.cwd(), encoding: 'utf-8', timeout: 15000, env: process.env,
  });
    // ConfigStore caches settings for 60 s in the web server's APCu, which
    // a CLI process cannot reach: flush it from inside the app as well.
    await flushCache();
}

const TOKEN = Math.random().toString(16).slice(2, 8).toUpperCase();
const VISIBILITY_KEYS = ['show_sesso', 'show_cod_fiscale'];
// Saving the Registration tab writes every switch on it, so snapshot (and
// restore, row presence included) all of them, not only the two under test.
const SNAPSHOT_KEYS = ['require_admin_approval', 'require_cognome', 'require_telefono', 'require_indirizzo', ...VISIBILITY_KEYS];
const MEMBER_EMAIL = `zz-hidden-member-${TOKEN.toLowerCase()}@example.test`;
const MEMBER_PASS = 'HiddenFields1!ok';
const MEMBER_CF = `ZZHF${TOKEN}00000000`.slice(0, 16);
const SIGNUP_EMAIL = `zz-hidden-signup-${TOKEN.toLowerCase()}@example.test`;
const SIGNUP_EMAIL_2 = `zz-hidden-signup2-${TOKEN.toLowerCase()}@example.test`;

async function login(page, email, password) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 15000 }),
    page.click('button[type="submit"]'),
  ]);
}

/** Tick/untick the two visibility switches through the real Registration tab. */
async function setVisibility(adminPage, showSesso, showCodFiscale) {
  await adminPage.goto(`${BASE}/admin/settings?tab=registration`);
  await adminPage.locator('input[name="show_sesso"]').setChecked(showSesso);
  await adminPage.locator('input[name="show_cod_fiscale"]').setChecked(showCodFiscale);
  await Promise.all([
    adminPage.waitForResponse((response) => response.request().method() === 'POST'
      && new URL(response.url()).pathname.endsWith('/admin/settings/registration'), { timeout: 15000 }),
    adminPage.click('form[action*="/admin/settings/registration"] button[type="submit"]'),
  ]);
  await adminPage.waitForLoadState('domcontentloaded');
}

async function fillSignup(page, email) {
  await page.goto(`${BASE}/registrati`);
  await page.fill('[name="nome"]', 'Hidden');
  await page.fill('[name="cognome"]', 'Fields');
  await page.fill('[name="email"]', email);
  await page.fill('[name="telefono"]', '+39 333 000 1111');
  await page.fill('[name="indirizzo"]', 'Via dei Test 1, Roma');
  await page.fill('[name="password"]', 'Signup1!secure');
  await page.fill('[name="password_confirm"]', 'Signup1!secure');
  await page.check('input[name="privacy_acceptance"]');
}

async function submitSignup(page) {
  await Promise.all([
    page.waitForURL((url) => !url.pathname.endsWith('/registrati') || url.search.includes('error='), { timeout: 15000 }),
    page.click('form:has(input[name="nome"]) button[type="submit"]'),
  ]);
}

function memberRow() {
  const row = dbQuery(`SELECT IFNULL(sesso,'NULL'), IFNULL(cod_fiscale,'NULL'), nome, telefono FROM utenti WHERE email='${MEMBER_EMAIL}'`);
  const [sesso, cf, nome, telefono] = row.split('\t');
  return { sesso, cf, nome, telefono };
}

test.describe.serial('Registration settings: hide sesso / codice fiscale', () => {
  /** @type {import('@playwright/test').Page} */
  let admin;
  /** @type {Map<string, string>} */
  const originalRows = new Map();
  let memberId = 0;
  let originalRequiredFieldIds = [];
  let mobileToken = '';

  test.beforeAll(async ({ browser }) => {
    const rows = dbQuery(
      "SELECT setting_key, HEX(setting_value) FROM system_settings WHERE category='registration' " +
      `AND setting_key IN ('${SNAPSHOT_KEYS.join("','")}')`
    );
    for (const row of rows ? rows.split('\n') : []) {
      const [key, hex = ''] = row.split('\t');
      originalRows.set(key, hex);
    }

    // Unrelated required custom fields on this installation must not block the
    // signup this suite performs: make them optional for the duration.
    const required = dbQuery('SELECT id FROM registrazione_campi WHERE obbligatorio=1 AND attivo=1');
    originalRequiredFieldIds = required ? required.split('\n').map(Number).filter(Number.isFinite) : [];
    if (originalRequiredFieldIds.length) {
      dbQuery(`UPDATE registrazione_campi SET obbligatorio=0 WHERE id IN (${originalRequiredFieldIds.join(',')})`);
    }

    // An existing member who already gave a gender and a tax code.
    const hash = execFileSync('php', ['-r', `echo password_hash('${MEMBER_PASS}', PASSWORD_DEFAULT);`], {
      encoding: 'utf-8', timeout: 5000,
    }).trim();
    dbQuery(
      'INSERT INTO utenti (nome, cognome, email, password, telefono, indirizzo, sesso, cod_fiscale, tipo_utente, ' +
      'email_verificata, stato, codice_tessera, privacy_accettata) VALUES ' +
      `('Member${TOKEN}', 'Hidden', '${MEMBER_EMAIL}', '${hash}', '+39 333 222 4444', 'Via Esistente 2', 'F', ` +
      `'${MEMBER_CF}', 'standard', 1, 'attivo', 'ZZHF${TOKEN}', 1)`
    );
    memberId = Number(dbQuery(`SELECT id FROM utenti WHERE email='${MEMBER_EMAIL}'`));
    await clearConfigCache();

    const ctx = await browser.newContext();
    admin = await ctx.newPage();
    await login(admin, ADMIN_EMAIL, ADMIN_PASS);
    // Start from the default (both visible) through the real writer.
    await setVisibility(admin, true, true);
  });

  test.afterAll(async () => {
    const effective = (key) => {
      if (!originalRows.has(key)) return true;
      const raw = Buffer.from(originalRows.get(key) || '', 'hex').toString('utf8').toLowerCase();
      return ['1', 'true', 'yes', 'on'].includes(raw);
    };
    if (admin) {
      await setVisibility(admin, effective('show_sesso'), effective('show_cod_fiscale'));
    }
    dbQuery(`DELETE FROM system_settings WHERE category='registration' AND setting_key IN ('${SNAPSHOT_KEYS.join("','")}')`);
    for (const [key, hex] of originalRows) {
      dbQuery(
        `INSERT INTO system_settings (category, setting_key, setting_value) VALUES ('registration', '${key}', UNHEX('${hex}')) ` +
        'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
      );
    }
    if (originalRequiredFieldIds.length) {
      dbQuery(`UPDATE registrazione_campi SET obbligatorio=1 WHERE id IN (${originalRequiredFieldIds.join(',')})`);
    }
    const ids = dbQuery(`SELECT id FROM utenti WHERE email IN ('${MEMBER_EMAIL}','${SIGNUP_EMAIL}','${SIGNUP_EMAIL_2}')`);
    if (ids) {
      const list = ids.split('\n').join(',');
      try { dbQuery(`DELETE FROM mobile_app_tokens WHERE user_id IN (${list})`); } catch { /* table may be absent */ }
      dbQuery(`DELETE FROM utenti WHERE id IN (${list})`);
    }
    await clearConfigCache();
    await admin?.context()?.close();
  });

  test('1. both fields are shown by default on every surface', async ({ browser }) => {
    await admin.goto(`${BASE}/admin/settings?tab=registration`);
    await expect(admin.locator('input[name="show_sesso"]')).toBeChecked();
    await expect(admin.locator('input[name="show_cod_fiscale"]')).toBeChecked();

    const ctx = await browser.newContext();
    const visitor = await ctx.newPage();
    await visitor.goto(`${BASE}/registrati`);
    await expect(visitor.locator('select[name="sesso"]')).toBeVisible();
    await expect(visitor.locator('input[name="cod_fiscale"]')).toBeVisible();
    await ctx.close();
  });

  test('2. the admin unticks both fields and the choice persists', async () => {
    await setVisibility(admin, false, false);
    await admin.goto(`${BASE}/admin/settings?tab=registration`);
    await expect(admin.locator('input[name="show_sesso"]')).not.toBeChecked();
    await expect(admin.locator('input[name="show_cod_fiscale"]')).not.toBeChecked();
    // The other registration switches are untouched by the new ones.
    await expect(admin.locator('input[name="require_cognome"]')).toBeVisible();
  });

  test('3. the public form no longer shows them and signup succeeds without them', async ({ browser }) => {
    const ctx = await browser.newContext();
    const visitor = await ctx.newPage();
    await fillSignup(visitor, SIGNUP_EMAIL);
    await expect(visitor.locator('[name="sesso"]')).toHaveCount(0);
    await expect(visitor.locator('[name="cod_fiscale"]')).toHaveCount(0);
    await expect(visitor.getByText('Codice Fiscale', { exact: true })).toHaveCount(0);
    // Birth date is not configurable and stays.
    await expect(visitor.locator('[name="data_nascita"]')).toHaveCount(1);

    // A crafted submission that still carries the hidden fields must not store them.
    await visitor.evaluate(() => {
      const form = document.querySelector('form:has(input[name="nome"])');
      for (const [name, value] of [['sesso', 'M'], ['cod_fiscale', 'CRAFTED00000000X']]) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form?.appendChild(input);
      }
    });
    await submitSignup(visitor);
    expect(visitor.url()).not.toContain('error=');
    const row = dbQuery(`SELECT IFNULL(sesso,'NULL'), IFNULL(cod_fiscale,'NULL') FROM utenti WHERE email='${SIGNUP_EMAIL}'`);
    expect(row).toBe('NULL\tNULL');
    await ctx.close();
  });

  test('4. profile edit hides them and saving keeps the stored values', async ({ browser }) => {
    const ctx = await browser.newContext();
    const member = await ctx.newPage();
    await login(member, MEMBER_EMAIL, MEMBER_PASS);
    await member.goto(`${BASE}/profilo`);
    const form = member.locator('form[action*="/profilo/aggiorna"]');
    await expect(form.locator('[name="nome"]')).toBeVisible();
    await expect(form.locator('[name="sesso"]')).toHaveCount(0);
    await expect(form.locator('[name="cod_fiscale"]')).toHaveCount(0);

    await form.locator('[name="nome"]').fill(`Renamed${TOKEN}`);
    await Promise.all([
      member.waitForLoadState('domcontentloaded'),
      form.locator('button[type="submit"]').click(),
    ]);
    await expect(member.locator('body')).not.toContainText('Compila tutti i campi obbligatori');
    const row = memberRow();
    expect(row.nome).toBe(`Renamed${TOKEN}`);
    expect(row.sesso).toBe('F');
    expect(row.cf).toBe(MEMBER_CF);
    await ctx.close();
  });

  test('5. admin user detail and edit form hide them; an admin save keeps the values', async () => {
    const detail = await admin.goto(`${BASE}/admin/users/details/${memberId}`);
    expect(detail?.status()).toBe(200);
    await expect(admin.locator('main')).toContainText('+39 333 222 4444');
    await expect(admin.locator('[data-field="sesso"]')).toHaveCount(0);
    await expect(admin.locator('main')).not.toContainText(MEMBER_CF);

    await admin.goto(`${BASE}/admin/users/edit/${memberId}`);
    const form = admin.locator('#user-form');
    await expect(form.locator('[name="sesso"]')).toHaveCount(0);
    await expect(form.locator('[name="cod_fiscale"]')).toHaveCount(0);
    await form.locator('[name="telefono"]').fill('+39 333 999 8888');
    await Promise.all([
      admin.waitForLoadState('domcontentloaded'),
      form.locator('button[type="submit"]').click(),
    ]);
    expect(admin.url()).not.toContain('error=');
    const row = memberRow();
    expect(row.telefono).toBe('+39 333 999 8888');
    expect(row.sesso).toBe('F');
    expect(row.cf).toBe(MEMBER_CF);

    await admin.goto(`${BASE}/admin/users/create`);
    await expect(admin.locator('[name="sesso"]')).toHaveCount(0);
    await expect(admin.locator('[name="cod_fiscale"]')).toHaveCount(0);
  });

  test('6. Mobile API reports the hidden fields and PATCH /me cannot change them', async ({ request }) => {
    const health = await request.get(`${API}/health`);
    test.skip(health.status() !== 200, 'mobile-api plugin not active on this installation');
    const healthBody = await health.json();
    expect(healthBody.data.registration).toMatchObject({ show_sesso: false, show_cod_fiscale: false });

    const fields = await request.get(`${API}/auth/registration-fields`);
    expect(fields.status()).toBe(200);
    const fd = (await fields.json()).data;
    expect(fd.profile_fields).toMatchObject({
      data_nascita: { visible: true, configurable: false },
      sesso: { visible: false, configurable: true },
      cod_fiscale: { visible: false, configurable: true },
    });
    // The signup contract is unchanged: profile-only fields never join builtin_fields.
    expect(fd.builtin_fields).not.toHaveProperty('sesso');
    expect(fd.builtin_fields).not.toHaveProperty('cod_fiscale');

    const loginRes = await request.post(`${API}/auth/login`, {
      data: { email: MEMBER_EMAIL, password: MEMBER_PASS, device_name: 'HiddenFieldsTest', device_id: `hf${TOKEN}`, platform: 'android' },
    });
    expect(loginRes.status()).toBe(200);
    mobileToken = (await loginRes.json()).data.token;
    const auth = { Authorization: `Bearer ${mobileToken}` };

    const me = await request.get(`${API}/me`, { headers: auth });
    expect(me.status()).toBe(200);
    const meData = (await me.json()).data;
    expect(meData).not.toHaveProperty('sesso');
    expect(meData).not.toHaveProperty('cod_fiscale');
    expect(meData.hidden_fields.sort()).toEqual(['cod_fiscale', 'sesso']);

    const patch = await request.patch(`${API}/me`, {
      headers: auth,
      data: { sesso: 'M', cod_fiscale: 'PATCHED000000000', telefono: '+39 333 777 6666' },
    });
    expect(patch.status()).toBe(200);
    const patched = (await patch.json()).data;
    expect(patched).not.toHaveProperty('sesso');
    const row = memberRow();
    expect(row.telefono).toBe('+39 333 777 6666');
    expect(row.sesso).toBe('F');
    expect(row.cf).toBe(MEMBER_CF);
  });

  test('7. ticking them again brings every surface back with the stored values', async ({ browser, request }) => {
    await setVisibility(admin, true, true);

    const ctx = await browser.newContext();
    const visitor = await ctx.newPage();
    await fillSignup(visitor, SIGNUP_EMAIL_2);
    await expect(visitor.locator('select[name="sesso"]')).toBeVisible();
    await expect(visitor.locator('input[name="cod_fiscale"]')).toBeVisible();
    await visitor.selectOption('select[name="sesso"]', 'Altro');
    await submitSignup(visitor);
    expect(visitor.url()).not.toContain('error=');
    expect(dbQuery(`SELECT sesso FROM utenti WHERE email='${SIGNUP_EMAIL_2}'`)).toBe('Altro');
    await ctx.close();

    const memberCtx = await browser.newContext();
    const member = await memberCtx.newPage();
    await login(member, MEMBER_EMAIL, MEMBER_PASS);
    await member.goto(`${BASE}/profilo`);
    const form = member.locator('form[action*="/profilo/aggiorna"]');
    await expect(form.locator('select[name="sesso"]')).toHaveValue('F');
    await expect(form.locator('input[name="cod_fiscale"]')).toHaveValue(MEMBER_CF);
    await memberCtx.close();

    const detail = await admin.goto(`${BASE}/admin/users/details/${memberId}`);
    expect(detail?.status()).toBe(200);
    await expect(admin.locator('[data-field="sesso"]')).toHaveCount(1);
    await expect(admin.locator('main')).toContainText(MEMBER_CF);

    const health = await request.get(`${API}/health`);
    if (health.status() === 200) {
      const fd = (await (await request.get(`${API}/auth/registration-fields`)).json()).data;
      expect(fd.profile_fields.sesso.visible).toBe(true);
      expect(fd.profile_fields.cod_fiscale.visible).toBe(true);
      if (mobileToken) {
        const meData = (await (await request.get(`${API}/me`, { headers: { Authorization: `Bearer ${mobileToken}` } })).json()).data;
        expect(meData.sesso).toBe('F');
        expect(meData.cod_fiscale).toBe(MEMBER_CF);
        expect(meData.hidden_fields).toEqual([]);
      }
    }
  });
});
