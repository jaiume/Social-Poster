#!/usr/bin/env node
import { launchBrowser, readStdinJson, respond } from './lib/session.js';

const input = await readStdinJson();
const platform = process.argv[2] || 'facebook';
let browser;

try {
  ({ browser } = await launchBrowser({ ...input, headless: false }));
  const context = browser.contexts()[0];
  const page = context.pages()[0] || await context.newPage();

  const url = platform === 'facebook' ? 'https://www.facebook.com/' : 'https://www.linkedin.com/feed/';
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  console.error(`Log in to ${platform} in the browser window, then press Enter here...`);
  await new Promise((resolve) => {
    process.stdin.resume();
    process.stdin.once('data', resolve);
  });

  const state = await context.storageState();
  respond({ success: true, storageState: state });
} catch (e) {
  respond({ success: false, error: e.message });
  process.exit(1);
} finally {
  if (browser) await browser.close();
}
