import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { sharePostToFeed } from '../lib/facebook.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

test('sharePostToFeed throws when the dialog never closes after clicking Share Now', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  // No click handler: clicking "Share now" has no effect, simulating a silently
  // rejected/no-op click (rate limit, transient glitch, UI change, etc.).
  await page.setContent(`
    <div role="dialog">
      <div>Sharing to Feed</div>
      <button aria-label="Share now">Share now</button>
    </div>
  `);

  await assert.rejects(
    () => sharePostToFeed(page, { closeTimeoutMs: 300 }),
    /Facebook share dialog remained open after clicking Share Now/i
  );

  await browser.close();
});

test('sharePostToFeed surfaces a specific error when Facebook rejects the share', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent(`
    <div role="dialog" id="shareDialog">
      <div>Sharing to Feed</div>
      <button id="shareNowBtn" aria-label="Share now">Share now</button>
    </div>
    <script>
      document.getElementById('shareNowBtn').addEventListener('click', () => {
        const alert = document.createElement('div');
        alert.setAttribute('role', 'alert');
        alert.textContent = 'Something went wrong. Please try again later.';
        document.body.appendChild(alert);
      });
    </script>
  `);

  await assert.rejects(
    () => sharePostToFeed(page, { closeTimeoutMs: 300 }),
    /Facebook rejected the share: something went wrong/i
  );

  await browser.close();
});

test('sharePostToFeed resolves once the dialog closes after clicking Share Now', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent(`
    <div role="dialog" id="shareDialog">
      <div>Sharing to Feed</div>
      <button id="shareNowBtn" aria-label="Share now">Share now</button>
    </div>
    <script>
      document.getElementById('shareNowBtn').addEventListener('click', () => {
        document.getElementById('shareDialog').remove();
      });
    </script>
  `);

  await sharePostToFeed(page, { closeTimeoutMs: 2000 });

  await browser.close();
});
