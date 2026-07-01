import { launchBrowser, closeBrowserSafe, readStdinJson } from '../../lib/session.js';

const input = await readStdinJson();
const pageUrl = input.pageUrl || 'https://www.facebook.com/me';
const { browser, context, page } = await launchBrowser({ ...input, platform: 'facebook' });

async function dumpVisibleRoles(label) {
  const roles = ['link', 'button'];
  const out = [];
  for (const role of roles) {
    const locator = page.getByRole(role);
    const count = Math.min(await locator.count().catch(() => 0), 60);
    for (let i = 0; i < count; i++) {
      const el = locator.nth(i);
      if (await el.isVisible({ timeout: 150 }).catch(() => false)) {
        const name = (await el.innerText().catch(() => '')) || (await el.getAttribute('aria-label').catch(() => '')) || '';
        const trimmed = name.replace(/\s+/g, ' ').trim();
        if (trimmed) {
          out.push(`${role}: ${trimmed.slice(0, 80)}`);
        }
      }
    }
  }
  console.error(`--- visible roles (${label}) ---`);
  console.error([...new Set(out)].join('\n'));
}

try {
  await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 25000 });
  await page.waitForTimeout(2000).catch(() => {});

  if (input.screenshotPath) {
    await page.screenshot({ path: input.screenshotPath.replace('.png', '-initial.png'), fullPage: false }).catch(() => {});
  }
  await dumpVisibleRoles('initial page load');

  const currentUrl = page.url();
  console.error('current URL after navigation:', currentUrl);

  const avatarCandidates = [
    page.locator('[aria-label*="Your profile" i]'),
    page.locator('[aria-label*="Account" i][role="button"]'),
    page.locator('div[data-visualcompletion="ignore-dynamic"] svg[aria-label]').locator('..'),
  ];
  let opened = false;
  for (const candidate of avatarCandidates) {
    const el = candidate.first();
    if (await el.isVisible({ timeout: 1000 }).catch(() => false)) {
      console.error('Clicking candidate account menu trigger');
      await el.click().catch(() => {});
      await page.waitForTimeout(1200).catch(() => {});
      opened = true;
      break;
    }
  }
  console.error('opened account menu:', opened);

  if (input.screenshotPath) {
    await page.screenshot({ path: input.screenshotPath, fullPage: false }).catch(() => {});
  }
  await dumpVisibleRoles('after attempting to open account menu');

  console.log(JSON.stringify({ success: true, currentUrl, openedMenu: opened }));
} catch (e) {
  console.error('ERROR:', e.message);
  if (input.screenshotPath) {
    await page.screenshot({ path: input.screenshotPath.replace('.png', '-error.png'), fullPage: false }).catch(() => {});
  }
  console.log(JSON.stringify({ success: false, error: e.message }));
} finally {
  await closeBrowserSafe(browser, context);
}
