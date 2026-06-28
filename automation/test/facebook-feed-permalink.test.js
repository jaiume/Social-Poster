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
  await page.setContent(`
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
  `);

  const resolved = await capturePrimaryPostPermalinkFromFeed(page, pageUrl, {
    textHint: 'Stop fighting bad WiFi',
  });
  assert.equal(resolved, 'https://www.facebook.com/WiFiVentures/posts/pfbidNewPost456');
  await browser.close();
});
