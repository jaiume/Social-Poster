#!/usr/bin/env node
import fs from 'fs';
import { launchBrowser, respond, closeBrowserSafe } from './lib/session.js';
import { screenshot } from './lib/facebook.js';

const inputPath = process.argv[2];
const input = inputPath && fs.existsSync(inputPath)
  ? JSON.parse(fs.readFileSync(inputPath, 'utf8'))
  : JSON.parse(fs.readFileSync(0, 'utf8'));
let browser;
let context;
let page;

async function probeUrl(targetUrl) {
  await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(1500).catch(() => {});

  const recaptcha = await page.locator('iframe[src*="recaptcha"], [id*="captcha"], [class*="recaptcha"]')
    .first()
    .isVisible({ timeout: 1500 })
    .catch(() => false);
  const checkpointText = await page.getByText(/checkpoint loop|not a robot|confirm your identity|security check/i)
    .first()
    .isVisible({ timeout: 1500 })
    .catch(() => false);
  const loginForm = await page.locator('input[name="email"], #email')
    .first()
    .isVisible({ timeout: 1000 })
    .catch(() => false);
  const feeds = await page.getByRole('link', { name: /^feeds$/i })
    .first()
    .isVisible({ timeout: 1500 })
    .catch(() => false);
  const postModal = await page.locator('[role="dialog"]').filter({ hasText: /'s post/i })
    .first()
    .isVisible({ timeout: 1500 })
    .catch(() => false);

  const shareProbe = await page.evaluate(() => {
    const dialog = [...document.querySelectorAll('[role="dialog"]')]
      .find((node) => /'s post/i.test(node.textContent || ''));
    const root = dialog || document;
    const buttons = [...root.querySelectorAll('[role="button"]')].map((el) => {
      const rect = el.getBoundingClientRect();
      return {
        aria: (el.getAttribute('aria-label') || '').trim(),
        text: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 50),
        w: Math.round(rect.width),
        h: Math.round(rect.height),
        y: Math.round(rect.y),
      };
    }).filter((b) => b.w > 0 && b.h > 0);
    return { hasDialog: Boolean(dialog), buttons: buttons.slice(0, 30) };
  }).catch(() => ({ hasDialog: false, buttons: [] }));

  const feedArticles = await page.evaluate(() => {
    return [...document.querySelectorAll('[role="article"], [data-pagelet*="FeedUnit"]')]
      .slice(0, 6)
      .map((node, index) => ({
        index,
        text: (node.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 180),
      }));
  }).catch(() => []);

  const bodySnippet = ((await page.locator('body').innerText().catch(() => '')) || '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 240);

  return {
    url: targetUrl,
    pageUrl: page.url(),
    title: await page.title().catch(() => ''),
    recaptcha,
    checkpointText,
    loginForm,
    feeds,
    postModal,
    shareProbe,
    feedArticles,
    bodySnippet,
    screenshotPath: await screenshot(page, input, 'session-check'),
  };
}

try {
  ({ browser, context, page } = await launchBrowser(input));
  const urls = input.urls?.length
    ? input.urls
    : ['https://www.facebook.com/'];
  const checks = [];
  for (const url of urls) {
    checks.push(await probeUrl(url));
  }

  respond({
    success: true,
    headless: input.headless !== false,
    checks,
    captchaDetected: checks.some((c) => c.recaptcha || c.checkpointText),
    loggedIn: checks.some((c) => c.feeds || c.postModal) && !checks.some((c) => c.loginForm),
  });
} catch (e) {
  respond({ success: false, error: e.message });
  process.exit(1);
} finally {
  await closeBrowserSafe(browser, context);
}
