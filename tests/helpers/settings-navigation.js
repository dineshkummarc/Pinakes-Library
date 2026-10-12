// @ts-check
const { expect } = require('@playwright/test');

/** Use the visible local navigation, avoiding duplicate sidebar destinations. */
async function openSettingsSection(page, section) {
  const menu = page.locator('#settings-page-menu');
  if (!(await menu.evaluate(element => element.open))) {
    await menu.locator('summary').click();
  }
  await menu.locator(`[data-settings-section="${section}"]`).click();
  await expect(page.locator(`section[data-settings-panel="${section}"]`)).toBeVisible();
}

module.exports = { openSettingsSection };
