import test from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { composerHasAttachedImage, composerHasUploadedPhoto } from '../lib/facebook.js';

async function launchBrowserOrSkip(t) {
  try {
    return await chromium.launch({ headless: true });
  } catch (e) {
    t.skip(`Playwright browser not available: ${e.message}`);
    return null;
  }
}

test('composerHasUploadedPhoto ignores link preview card', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setViewportSize({ width: 900, height: 800 });
  await page.setContent(`
    <div role="dialog">
      <h2>Create post</h2>
      <a href="https://l.facebook.com/l.php?u=https://entryzen.com" style="display:block;width:400px;height:200px">
        <img src="https://scontent.xx.fbcdn.net/v/example.jpg" width="200" height="200" alt="">
        <span>ENTRYZEN.COM</span>
        <span>EntryZen — Gated access done well</span>
      </a>
    </div>
  `);
  const dialog = page.locator('[role="dialog"]');
  assert.equal(await composerHasAttachedImage(page, dialog), true);
  assert.equal(await composerHasUploadedPhoto(page, dialog), false);
  await browser.close();
});

test('composerHasUploadedPhoto detects remove-photo control in create post dialog', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent(`
    <div role="dialog">
      <h2>Create post</h2>
      <button aria-label="Remove photo">Remove</button>
    </div>
  `);
  const dialog = page.locator('[role="dialog"]');
  assert.equal(await composerHasAttachedImage(page, dialog), true);
  assert.equal(await composerHasUploadedPhoto(page, dialog), true);
  await browser.close();
});

test('composerHasAttachedImage detects image inside add-to-post picker', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setViewportSize({ width: 900, height: 800 });
  await page.setContent(`
    <div role="dialog">
      <div>Photo/video</div>
      <div>Tag people</div>
      <img src="https://scontent.xx.fbcdn.net/v/example.jpg" width="320" height="320" alt="preview">
    </div>
  `);
  const dialog = page.locator('[role="dialog"]').first();
  assert.equal(await composerHasAttachedImage(page, dialog), true);
  await browser.close();
});

test('composerHasAttachedImage detects media-vc-image surface', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setViewportSize({ width: 900, height: 800 });
  await page.setContent(`
    <div role="dialog">
      <h2>Create post</h2>
      <div data-visualcompletion="media-vc-image" style="width:240px;height:240px;background:#ccc"></div>
    </div>
  `);
  const dialog = page.locator('[role="dialog"]');
  assert.equal(await composerHasAttachedImage(page, dialog), true);
  await browser.close();
});

test('composerHasAttachedImage detects visible Edit text on media preview', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setViewportSize({ width: 900, height: 800 });
  await page.setContent(`
    <div role="dialog">
      <h2>Create post</h2>
      <div style="position:relative;width:320px;height:320px;background:#1877f2">
        <button type="button">Edit</button>
      </div>
    </div>
  `);
  const dialog = page.locator('[role="dialog"]');
  assert.equal(await composerHasAttachedImage(page, dialog), true);
  await browser.close();
});

test('composerHasAttachedImage detects large solid preview block', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setViewportSize({ width: 900, height: 800 });
  await page.setContent(`
    <div role="dialog">
      <h2>Create post</h2>
      <div id="preview" style="width:320px;height:320px;background-color:#1877f2"></div>
    </div>
  `);
  const dialog = page.locator('[role="dialog"]');
  assert.equal(await composerHasAttachedImage(page, dialog), true);
  await browser.close();
});

test('composerHasAttachedImage ignores profile picture in header', async (t) => {
  const browser = await launchBrowserOrSkip(t);
  if (!browser) return;

  const page = await browser.newPage();
  await page.setContent(`
    <div role="dialog">
      <h2>Create post</h2>
      <div aria-label="Profile picture">
        <img src="https://scontent.xx.fbcdn.net/v/avatar.jpg" width="200" height="200">
      </div>
    </div>
  `);
  const dialog = page.locator('[role="dialog"]');
  assert.equal(await composerHasAttachedImage(page, dialog), false);
  await browser.close();
});
