import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { isPageAdminMode } from '../lib/facebook.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

test('isPageAdminMode returns false when Manage Page sidebar is visible but Switch Now is shown', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent(`
    <div>
      <h2>Manage Page</h2>
      <button>Switch Now</button>
      <button aria-label="Follow">Follow</button>
    </div>
  `);
  assert.equal(await isPageAdminMode(page), false);
  await browser.close();
});

test('ensurePageAdminMode clicks Switch banner and confirms Switch profiles dialog', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  let dialogOpen = false;
  await page.setContent(`
    <div>
      <p>Switch into WifiVentures.co.tt's Page to take more actions</p>
      <button id="banner-switch">Switch</button>
      <div id="dialog" role="dialog" style="display:none">
        <h2>Switch profiles</h2>
        <p>Switch to WifiVentures.co.tt for more features, tools and settings</p>
        <button id="confirm-switch">Switch</button>
      </div>
      <button id="composer" style="display:none">What's on your mind?</button>
    </div>
    <script>
      document.getElementById('banner-switch').addEventListener('click', () => {
        document.getElementById('dialog').style.display = 'block';
      });
      document.getElementById('confirm-switch').addEventListener('click', () => {
        document.getElementById('dialog').style.display = 'none';
        document.getElementById('composer').style.display = 'block';
      });
    </script>
  `);

  const { ensurePageAdminMode } = await import('../lib/facebook.js');
  const switched = await ensurePageAdminMode(page);
  assert.equal(switched, true);
  assert.equal(await page.locator('#composer').isVisible(), true);
  await browser.close();
});
