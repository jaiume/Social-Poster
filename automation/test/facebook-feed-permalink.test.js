import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { capturePrimaryPostPermalinkFromFeed } from '../lib/facebook.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

test('capturePrimaryPostPermalinkFromFeed prefers article matching text hint', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  const pageUrl = 'https://www.facebook.com/WiFiVentures';

  // Route real facebook.com traffic to local fixture HTML instead of hitting the
  // network. `page.setContent` alone leaves `page.url()` unset, which made this
  // test fall through to a *real* navigation to facebook.com (flaky/offline-unsafe
  // and liable to fail on facebook.com's actual login-wall markup).
  await page.route('https://www.facebook.com/**', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: `
      <div role="main">
        <article>
          <a href="https://www.facebook.com/WiFiVentures/posts/pfbidOldPost123">Old post</a>
          <span>Some older content about networking</span>
        </article>
        <article>
          <a href="https://www.facebook.com/WiFiVentures/posts/pfbidNewPost456">New post</a>
          <span>Stop fighting bad WiFi. WifiVentures installs commercial grade Business WiFi</span>
        </article>
      </div>
    `,
  }));

  try {
    await page.goto(pageUrl);
    const resolved = await capturePrimaryPostPermalinkFromFeed(page, pageUrl, {
      textHint: 'Stop fighting bad WiFi',
    });
    assert.equal(resolved, 'https://www.facebook.com/WiFiVentures/posts/pfbidNewPost456');
  } finally {
    await browser.close();
  }
});
