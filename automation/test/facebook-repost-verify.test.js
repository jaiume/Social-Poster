import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { verifyFacebookRepostAppeared } from '../lib/facebook.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

const TIMELINE_WITH_MATCH = `
  <div role="main">
    <div role="article">
      <span>Jamie McLachlan shared EntryZen's post.</span>
      <span>Keep vendor access tight and transparent.</span>
    </div>
    <div role="article">
      <span>Some unrelated older post</span>
    </div>
  </div>
`;

const TIMELINE_WITHOUT_MATCH = `
  <div role="main">
    <div role="article">
      <span>Some unrelated post about boats</span>
    </div>
    <div role="article">
      <span>Another unrelated post</span>
    </div>
  </div>
`;

test('verifyFacebookRepostAppeared returns true when the brand appears on the timeline', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.route('https://www.facebook.com/me', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: TIMELINE_WITH_MATCH,
  }));

  const appeared = await verifyFacebookRepostAppeared(page, {
    brandPattern: /EntryZen/i,
    timeoutMs: 3000,
  });

  assert.equal(appeared, true);
  await browser.close();
});

test('verifyFacebookRepostAppeared returns false when nothing matches before the timeout', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.route('https://www.facebook.com/me', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: TIMELINE_WITHOUT_MATCH,
  }));

  const appeared = await verifyFacebookRepostAppeared(page, {
    brandPattern: /EntryZen/i,
    timeoutMs: 2000,
  });

  assert.equal(appeared, false);
  await browser.close();
});

test('verifyFacebookRepostAppeared skips the check when no brand or text hint is available', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  // No route registered: if the function tried to navigate, this would hang/fail,
  // proving the early-return path never attempts navigation.
  const appeared = await verifyFacebookRepostAppeared(page, {});

  assert.equal(appeared, true);
  await browser.close();
});

test('verifyFacebookRepostAppeared matches on text hint when no brand pattern is provided', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.route('https://www.facebook.com/me', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: TIMELINE_WITH_MATCH,
  }));

  const appeared = await verifyFacebookRepostAppeared(page, {
    textHint: 'Keep vendor access tight and transparent.',
    timeoutMs: 3000,
  });

  assert.equal(appeared, true);
  await browser.close();
});
