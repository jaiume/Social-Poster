import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { runHostPreSteps } from '../lib/host-bootstrap.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

test('assert_session pre_step throws a clear error on a stale Facebook session', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent('<form><input name="email" /><input name="pass" /></form>');

  await assert.rejects(
    () => runHostPreSteps(page, 'facebook', ['assert_session'], 'https://www.facebook.com/some.page'),
    /Facebook session expired/i
  );

  await browser.close();
});

test('assert_session pre_step passes through a healthy Facebook page', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent('<div role="main"><span>What\'s on your mind?</span></div>');

  await runHostPreSteps(page, 'facebook', ['assert_session'], 'https://www.facebook.com/some.page');

  await browser.close();
});

test('assert_session pre_step runs before later steps so session issues are reported first', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent('<form><input name="email" /><input name="pass" /></form>');

  // Even paired with a later step, a stale session should short-circuit with
  // its own specific error rather than falling through to a generic one.
  await assert.rejects(
    () => runHostPreSteps(page, 'facebook', ['assert_session', 'abort_if_unavailable'], 'https://www.facebook.com/some.page'),
    /Facebook session expired/i
  );

  await browser.close();
});
