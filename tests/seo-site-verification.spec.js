// @ts-check
// Settings > Advanced > "Search engine verification": the admin pastes the
// <meta> tag Google Search Console (or Bing, Yandex…) hands out and the public
// pages must carry it in <head>, where the service looks for it. The custom JS
// fields cannot do this: they print inside <script>, so a pasted <meta> never
// reached the markup. This drives the real form as an admin would, then reads
// the home page as an anonymous visitor (the crawler's view).
//
// Run: /tmp/run-e2e.sh tests/seo-site-verification.spec.js --config=tests/playwright.config.js --workers=1
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

const GOOGLE_CODE = 'e2e-google-verification-code';
const BING_CODE = 'E2E0123456789ABCDEF0123456789ABCD';
const PASTED = [
  `<meta name="google-site-verification" content="${GOOGLE_CODE}" />`,
  `<meta name="msvalidate.01" content="${BING_CODE}" />`,
  '<meta name="robots" content="noindex">',
  '<script>window.__e2eInjected = true;</script>',
].join('\n');

function db(sql) {
  const args = [];
  if (DB_SOCKET) args.push('-S', DB_SOCKET);
  args.push('-u', DB_USER, DB_NAME, '-N', '-B', '-e', sql);
  return execFileSync('mysql', args, { encoding: 'utf-8', timeout: 10000, env: { ...process.env, MYSQL_PWD: DB_PASS } }).trim();
}

let priorValue = '';

test.beforeAll(() => {
  priorValue = db("SELECT COALESCE(MAX(setting_value), '') FROM system_settings WHERE category='advanced' AND setting_key='site_verification_meta'");
});

async function login(page) {
  await page.goto(`${BASE}/accedi`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await page.locator('button[type="submit"]').click();
  await page.waitForFunction(
    () => !window.location.pathname.includes('accedi') && !window.location.pathname.includes('login'),
    { timeout: 15000 },
  );
}

async function saveVerification(page, value) {
  await page.goto(`${BASE}/admin/settings?tab=advanced`);
  const field = page.locator('#site_verification_meta');
  await field.waitFor({ state: 'visible', timeout: 10000 });
  await field.fill(value);
  // Wait for the POST answer and the page it redirects to: networkidle can
  // settle before the submission has even started.
  await Promise.all([
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/admin/settings/advanced')),
    field.evaluate((element) => element.closest('form')?.requestSubmit()),
  ]);
  await page.waitForLoadState('load');
  return field;
}

test('verification meta tags pasted in Advanced settings reach the public <head>', async ({ page, browser }) => {
  await login(page);
  try {
    const field = await saveVerification(page, PASTED);

    // The two foreign lines are dropped and the admin is told so.
    await expect(page.getByText(/2 righe|2 lines|2 Zeilen|2 lignes|2 linjer/)).toBeVisible();
    // The field shows the canonical form: only the verification tags, rebuilt.
    await expect(field).toHaveValue(
      `<meta name="google-site-verification" content="${GOOGLE_CODE}">\n<meta name="msvalidate.01" content="${BING_CODE}">`,
    );

    // Anonymous visitor, as the search engine's verifier fetches the page.
    const visitor = await browser.newContext();
    const home = await visitor.newPage();
    const response = await home.goto(`${BASE}/`);
    expect(response?.status()).toBe(200);
    await expect(home.locator('head meta[name="google-site-verification"]')).toHaveAttribute('content', GOOGLE_CODE);
    await expect(home.locator('head meta[name="msvalidate.01"]')).toHaveAttribute('content', BING_CODE);
    await expect(home.locator('head meta[name="google-site-verification"]')).toHaveCount(1);
    // Nothing else from the paste made it in.
    const robots = await home.locator('head meta[name="robots"]').evaluateAll((els) => els.map((e) => e.getAttribute('content') || ''));
    expect(robots.some((c) => c.includes('noindex'))).toBe(false);
    expect(await home.evaluate(() => /** @type {any} */ (window).__e2eInjected === true)).toBe(false);

    // The tag must be in the server-rendered HTML, not added by JavaScript:
    // the verifier does not run scripts.
    const raw = await (await visitor.request.get(`${BASE}/catalogo`)).text();
    expect(raw).toContain(`<meta name="google-site-verification" content="${GOOGLE_CODE}">`);
    await visitor.close();

    // Clearing the field removes the tags.
    await saveVerification(page, '');
    const after = await (await page.request.get(`${BASE}/`)).text();
    expect(after).not.toContain('google-site-verification');
  } finally {
    await saveVerification(page, priorValue);
  }
});
