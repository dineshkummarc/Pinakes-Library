// @ts-check
// An admin who edits a mail template must be able to tell which template a reader
// receives. Approving a loan that starts today sends «Pronto per il ritiro»
// (loan_pickup_ready), not «Prestito approvato» (loan_approved), which only goes
// out for a loan that starts on a later date. The editor said otherwise, so a
// library edited loan_approved and kept receiving the untouched pickup mail.
// Both templates are edited through the settings form, the loan is approved from
// the pending list, and the mail is read from Mailpit.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
const MAILPIT_API = process.env.MAILPIT_API || 'http://localhost:8025/api/v1';
const RUN = Date.now().toString(36);

function db(sql) {
  // CI connects over TCP (E2E_DB_HOST/E2E_DB_PORT, no socket); a local run may use a socket.
  const args = [];
  if (process.env.E2E_DB_HOST) {
    args.push('-h', process.env.E2E_DB_HOST);
    if (process.env.E2E_DB_PORT) args.push('-P', process.env.E2E_DB_PORT);
  } else if (process.env.E2E_DB_SOCKET) {
    args.push('-S', process.env.E2E_DB_SOCKET);
  }
  args.push('-u', process.env.E2E_DB_USER || '', process.env.E2E_DB_NAME || '', '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, {
    encoding: 'utf-8', env: { ...process.env, MYSQL_PWD: process.env.E2E_DB_PASS },
  }).trim();
}

test.skip(!process.env.E2E_ADMIN_EMAIL || !process.env.E2E_DB_USER || !process.env.E2E_DB_NAME, 'E2E credentials not configured');

test('approving a loan that starts today sends the edited «Pronto per il ritiro», as the editor says', async ({ page }) => {
  const mailpitUp = await fetch(`${MAILPIT_API}/info`).then((r) => r.ok).catch(() => false);
  test.skip(!mailpitUp, 'Mailpit is not running on :8025');
  test.setTimeout(120000);
  // Whole rows, kept in the database: the restore must also drop the keys the
  // INSERT below adds, and keep NULLs and backslashes exactly as they were.
  db(`DROP TABLE IF EXISTS zz_mail_backup_${RUN}`);
  db(`CREATE TABLE zz_mail_backup_${RUN} AS SELECT * FROM system_settings WHERE category='email'`);
  db(`DROP TABLE IF EXISTS zz_tpl_backup_${RUN}`);
  db(`CREATE TABLE zz_tpl_backup_${RUN} AS SELECT * FROM email_templates WHERE locale='it_IT' AND name IN ('loan_approved','loan_pickup_ready')`);
  const userEmail = `probe-${RUN}@test.local`;
  let userId = '', bookId = '', loanId = '';
  try {
    db(`INSERT INTO system_settings (category, setting_key, setting_value) VALUES
      ('email','type','smtp'),('email','driver_mode','smtp'),('email','smtp_host','localhost'),('email','smtp_port','1025'),
      ('email','smtp_security',''),('email','smtp_username',''),('email','smtp_password',''),('email','from_email','test@biblioteca.local'),('email','from_name','Probe')
      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)`);
    await page.request.get(`${BASE}/_e2e/flush-cache`);

    await page.goto(`${BASE}/accedi`);
    await page.fill('input[name="email"]', process.env.E2E_ADMIN_EMAIL || '');
    await page.fill('input[name="password"]', process.env.E2E_ADMIN_PASS || '');
    await page.locator('button[type="submit"]').click();
    await page.waitForURL(/admin|\/$/, { timeout: 15000 }).catch(() => {});

    for (const name of ['loan_approved', 'loan_pickup_ready']) {
      await page.goto(`${BASE}/admin/settings?tab=templates&template_locale=it_IT&template=${name}`);
      const form = page.locator(`form:has(textarea[data-template="${name}"])`);
      await form.scrollIntoViewIfNeeded();
      await form.locator('input[name="subject"]').fill(`SUBJ-${name}-${RUN}`);
      const frame = form.frameLocator('iframe').first();
      await frame.locator('body').click();
      await page.keyboard.press('ControlOrMeta+End');
      await page.keyboard.type(` BODY-${name}-${RUN}`);
      await form.getByRole('button', { name: /Salva template/ }).click();
      await page.waitForLoadState('domcontentloaded');
      expect(db(`SELECT LOCATE('BODY-${name}-${RUN}', body) > 0 FROM email_templates WHERE name='${name}' AND locale='it_IT'`)).toBe('1');
    }

    await page.goto(`${BASE}/admin/settings?tab=templates&template_locale=it_IT`);
    const approvedCard = page.locator('div.overflow-hidden:has(textarea[data-template="loan_approved"])').last();
    await expect(approvedCard).toContainText('approva o crea un prestito che inizia in una data futura');
    await expect(approvedCard).toContainText('Pronto per il ritiro');

    db(`INSERT INTO utenti (nome, cognome, email, password, codice_tessera, stato, tipo_utente, email_verificata, locale) VALUES ('Probe','Reader','${userEmail}','x','PRB${RUN}','attivo','standard',1,'it_IT')`);
    userId = db(`SELECT id FROM utenti WHERE email='${userEmail}'`);
    bookId = db(`INSERT INTO libri (titolo, copie_totali, copie_disponibili, stato) VALUES ('Probe Book ${RUN}',1,1,'disponibile'); SELECT LAST_INSERT_ID()`);
    db(`INSERT INTO copie (libro_id, numero_inventario, stato) VALUES (${bookId}, 'PRB-${RUN}', 'disponibile')`);
    loanId = db(`INSERT INTO prestiti (libro_id, utente_id, stato, data_prestito, data_scadenza, attivo, created_at) VALUES (${bookId}, ${userId}, 'pendente', CURDATE(), CURDATE() + INTERVAL 30 DAY, 1, NOW()); SELECT LAST_INSERT_ID()`);
    await fetch(`${MAILPIT_API}/messages`, { method: 'DELETE' });

    await page.goto(`${BASE}/admin/loans/pending`);
    await page.locator(`.approve-btn[data-loan-id="${loanId}"]`).click();
    await page.locator('.swal2-confirm').click();
    await expect.poll(() => db(`SELECT stato FROM prestiti WHERE id=${loanId}`), { timeout: 10000 }).toBe('da_ritirare');

    let messages = [];
    for (let i = 0; i < 20 && messages.length === 0; i++) {
      const list = await (await fetch(`${MAILPIT_API}/search?query=${encodeURIComponent('to:' + userEmail)}`)).json();
      messages = list.messages || [];
      if (messages.length === 0) await page.waitForTimeout(500);
    }
    expect(messages).toHaveLength(1);
    const mail = await (await fetch(`${MAILPIT_API}/message/${messages[0].ID}`)).json();
    expect(mail.Subject).toBe(`SUBJ-loan_pickup_ready-${RUN}`);
    expect(mail.HTML).toContain(`BODY-loan_pickup_ready-${RUN}`);
    expect(mail.HTML).not.toContain(`BODY-loan_approved-${RUN}`);
  } finally {
    if (loanId) db(`DELETE FROM prestiti WHERE id=${loanId}`);
    if (bookId) { db(`DELETE FROM copie WHERE libro_id=${bookId}`); db(`DELETE FROM libri WHERE id=${bookId}`); }
    if (userId) db(`DELETE FROM utenti WHERE id=${userId}`);
    db(`DELETE FROM system_settings WHERE category='email'`);
    db(`INSERT INTO system_settings SELECT * FROM zz_mail_backup_${RUN}`);
    db(`DROP TABLE zz_mail_backup_${RUN}`);
    db(`DELETE FROM email_templates WHERE locale='it_IT' AND name IN ('loan_approved','loan_pickup_ready')`);
    db(`INSERT INTO email_templates SELECT * FROM zz_tpl_backup_${RUN}`);
    db(`DROP TABLE zz_tpl_backup_${RUN}`);
    await page.request.get(`${BASE}/_e2e/flush-cache`);
  }
});
