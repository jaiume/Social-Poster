import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { openPagePostsSurface } from '../lib/facebook.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

test('openPagePostsSurface clicks the "All" tab on the modern Page UI without falling back to ?sk=posts', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent(`
    <div role="main">
      <div role="tab" tabindex="0">All</div>
      <div role="tab" tabindex="0">About</div>
      <article>Existing post</article>
    </div>
  `);

  let navigated = false;
  page.on('framenavigated', () => {
    navigated = true;
  });

  const result = await openPagePostsSurface(page, 'https://www.facebook.com/profile.php?id=12345');
  assert.equal(result, true);
  assert.equal(navigated, false, 'clicking the "All" tab should not trigger a page navigation');
  await browser.close();
});

test('openPagePostsSurface recovers when the legacy ?sk=posts URL is unavailable', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  const pageUrl = 'https://www.facebook.test/profile.php?id=999';

  await page.route('**/profile.php**', async (route) => {
    const url = new URL(route.request().url());
    if (url.searchParams.get('sk') === 'posts') {
      await route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: '<div role="main"><h2>This Page Isn\'t Available</h2></div>',
      });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'text/html',
      body: '<div role="main"><article>Recovered post feed</article></div>',
    });
  });

  await page.goto(pageUrl);
  const result = await openPagePostsSurface(page, pageUrl);
  assert.equal(result, true);

  const isRecovered = await page.getByText('Recovered post feed').isVisible().catch(() => false);
  assert.equal(isRecovered, true, 'should fall back to the base page URL after ?sk=posts 404s');

  const isUnavailable = await page.getByText(/this page isn'?t available/i).first().isVisible().catch(() => false);
  assert.equal(isUnavailable, false);

  await browser.close();
});
