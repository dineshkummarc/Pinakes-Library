// @ts-check
/**
 * Admin author and publisher detail pages on a phone (375px).
 *
 * Regression for two layout bugs:
 *  - the info card ("Totale Libri / Creato il / Ultimo aggiornamento") and the
 *    other cards lost all padding below 768px (main.css zeroes `.card`
 *    padding on mobile), so the text touched the card border;
 *  - book covers were rendered with object-fit: cover in a fixed box, cutting
 *    off part of every cover.
 *
 * A wide test cover (2:1) makes cropping impossible to miss: with
 * object-fit: cover the box would show only its middle third.
 */

const { test, expect, devices } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = process.env.E2E_BASE_URL || 'http://localhost:8081';
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

const TOKEN = Math.random().toString(16).slice(2, 8);
const COVER_NAME = `zz-mobile-cards-${TOKEN}.png`;
const COVER_FILE = path.join(__dirname, '..', 'public', 'uploads', 'copertine', COVER_NAME);
const PHONE = { ...devices['Pixel 5'], viewport: { width: 375, height: 812 } };
const MIN_INSET = 12;

/** Every card wrapper on the page: its padding and how far its content sits from the border. */
async function cardInsets(page) {
  return page.evaluate(() => {
    const anchors = [...document.querySelectorAll('main dl, main h2')];
    const cards = new Set();
    for (const anchor of anchors) {
      const card = anchor.closest('.rounded-lg.border, .card');
      if (card && card !== anchor) cards.add(card);
    }
    return [...cards].map((card) => {
      const box = card.getBoundingClientRect();
      const cs = getComputedStyle(card);
      const content = card.querySelector('dl, h2');
      const inner = content ? content.getBoundingClientRect() : box;
      return {
        label: (content?.textContent || '').trim().slice(0, 40),
        paddingLeft: parseFloat(cs.paddingLeft),
        paddingRight: parseFloat(cs.paddingRight),
        insetLeft: inner.left - box.left,
        insetRight: box.right - inner.right,
        insetTop: inner.top - box.top,
      };
    });
  });
}

async function coverFits(page) {
  return page.evaluate((name) => {
    const imgs = [...document.querySelectorAll('main article img')].filter((img) => img.currentSrc.includes(name));
    return imgs.map((img) => {
      const box = img.getBoundingClientRect();
      const fit = getComputedStyle(img).objectFit;
      // Size of the picture actually painted inside the element box.
      const scale = fit === 'cover'
        ? Math.max(box.width / img.naturalWidth, box.height / img.naturalHeight)
        : Math.min(box.width / img.naturalWidth, box.height / img.naturalHeight);
      return {
        fit,
        loaded: img.complete && img.naturalWidth > 0,
        paintedWidth: img.naturalWidth * scale,
        paintedHeight: img.naturalHeight * scale,
        boxWidth: box.width,
        boxHeight: box.height,
      };
    });
  }, COVER_NAME);
}

async function loginAdmin(page) {
  await page.goto(`${BASE}/login`);
  await page.fill('input[name="email"]', ADMIN_EMAIL);
  await page.fill('input[name="password"]', ADMIN_PASS);
  await Promise.all([
    page.waitForURL(/\/admin\//, { timeout: 30000, waitUntil: 'domcontentloaded' }),
    page.click('button[type="submit"]'),
  ]);
}

async function assertPhoneLayout(page, url) {
  const res = await page.goto(url, { waitUntil: 'domcontentloaded' });
  expect(res?.status()).toBe(200);
  await page.waitForLoadState('load');

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, 'no horizontal page scroll at 375px').toBeLessThanOrEqual(0);

  const cards = await cardInsets(page);
  expect(cards.length, 'the info card and the catalogue card are found').toBeGreaterThanOrEqual(2);
  for (const card of cards) {
    expect(card.paddingLeft, `padding of card "${card.label}"`).toBeGreaterThanOrEqual(MIN_INSET);
    expect(card.paddingRight, `padding of card "${card.label}"`).toBeGreaterThanOrEqual(MIN_INSET);
    expect(card.insetLeft, `text inset from the left border of "${card.label}"`).toBeGreaterThanOrEqual(MIN_INSET);
    expect(card.insetRight, `text inset from the right border of "${card.label}"`).toBeGreaterThanOrEqual(MIN_INSET);
    expect(card.insetTop, `text inset from the top border of "${card.label}"`).toBeGreaterThanOrEqual(MIN_INSET);
  }

  await page.locator(`main article img[src*="${COVER_NAME}"]`).first().scrollIntoViewIfNeeded();
  await expect.poll(async () => (await coverFits(page)).every((c) => c.loaded)).toBe(true);
  const covers = await coverFits(page);
  expect(covers.length, 'the test book cover is rendered').toBeGreaterThanOrEqual(1);
  for (const cover of covers) {
    expect(cover.fit).toBe('contain');
    // The whole picture is painted inside its box: nothing is cut off.
    expect(cover.paintedWidth).toBeLessThanOrEqual(cover.boxWidth + 0.5);
    expect(cover.paintedHeight).toBeLessThanOrEqual(cover.boxHeight + 0.5);
  }
}

test.describe.serial('Admin author / publisher detail at phone width', () => {
  let authorId = 0;
  let publisherId = 0;
  let bookId = 0;

  test.beforeAll(() => {
    // A deliberately wide (2:1) cover so any cropping is measurable.
    execFileSync('php', ['-r', `
      $im = imagecreatetruecolor(600, 300);
      imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
      imagepng($im, ${JSON.stringify(COVER_FILE)});
    `], { timeout: 10000 });

    dbQuery(`INSERT INTO autori (nome) VALUES ('ZZ Mobile Author ${TOKEN}')`);
    authorId = Number(dbQuery(`SELECT id FROM autori WHERE nome='ZZ Mobile Author ${TOKEN}'`));
    dbQuery(`INSERT INTO editori (nome) VALUES ('ZZ Mobile Publisher ${TOKEN}')`);
    publisherId = Number(dbQuery(`SELECT id FROM editori WHERE nome='ZZ Mobile Publisher ${TOKEN}'`));
    dbQuery(
      'INSERT INTO libri (titolo, editore_id, copertina_url) VALUES ' +
      `('ZZ Mobile Cards Book ${TOKEN}', ${publisherId}, '/uploads/copertine/${COVER_NAME}')`
    );
    bookId = Number(dbQuery(`SELECT id FROM libri WHERE titolo='ZZ Mobile Cards Book ${TOKEN}' AND deleted_at IS NULL`));
    dbQuery(`INSERT INTO libri_autori (libro_id, autore_id, ruolo, ordine_credito) VALUES (${bookId}, ${authorId}, 'principale', 1)`);
  });

  test.afterAll(() => {
    if (bookId) {
      dbQuery(`DELETE FROM libri_autori WHERE libro_id=${bookId}`);
      dbQuery(`DELETE FROM libri WHERE id=${bookId}`);
    }
    if (authorId) dbQuery(`DELETE FROM autori WHERE id=${authorId}`);
    if (publisherId) dbQuery(`DELETE FROM editori WHERE id=${publisherId}`);
    try { fs.unlinkSync(COVER_FILE); } catch { /* already gone */ }
  });

  test('author detail: padded cards, whole covers, no horizontal scroll', async ({ browser }) => {
    const ctx = await browser.newContext(PHONE);
    const page = await ctx.newPage();
    await loginAdmin(page);
    await assertPhoneLayout(page, `${BASE}/admin/authors/${authorId}`);
    await ctx.close();
  });

  test('publisher detail: padded cards, whole covers, no horizontal scroll', async ({ browser }) => {
    const ctx = await browser.newContext(PHONE);
    const page = await ctx.newPage();
    await loginAdmin(page);
    await assertPhoneLayout(page, `${BASE}/admin/publishers/${publisherId}`);
    await ctx.close();
  });
});
