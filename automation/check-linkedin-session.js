#!/usr/bin/env node
import fs from 'fs';
import { launchBrowser, respond, closeBrowserSafe } from './lib/session.js';
import { assertLinkedInSession, detectLinkedInSecurityChallenge, screenshot } from './lib/linkedin.js';

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

  let sessionError = null;
  try {
    await assertLinkedInSession(page);
  } catch (error) {
    sessionError = error.message;
  }

  const security = await detectLinkedInSecurityChallenge(page);
  const loginPassword = await page.locator(
    'input[name="session_password"], input#password, input[autocomplete="current-password"]'
  ).first().isVisible({ timeout: 1000 }).catch(() => false);
  const globalNav = await page.locator('.global-nav, nav[aria-label*="Primary" i]').first()
    .isVisible({ timeout: 1500 }).catch(() => false);
  const feedComposer = await page.getByRole('button', { name: /start a post/i }).first()
    .isVisible({ timeout: 1500 }).catch(() => false);
  const repostBtn = await page.locator('button[aria-label*="Repost" i]').first()
    .isVisible({ timeout: 1500 }).catch(() => false);

  const bodySnippet = ((await page.locator('body').innerText().catch(() => '')) || '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 280);

  return {
    url: targetUrl,
    pageUrl: page.url(),
    title: await page.title().catch(() => ''),
    sessionError,
    security,
    loginPassword,
    globalNav,
    feedComposer,
    repostBtn,
    bodySnippet,
    screenshotPath: await screenshot(page, input, 'linkedin-session-check'),
  };
}

try {
  ({ browser, context, page } = await launchBrowser(input));
  const urls = input.urls?.length
    ? input.urls
    : ['https://www.linkedin.com/feed/'];
  const checks = [];
  for (const url of urls) {
    checks.push(await probeUrl(url));
  }

  const captchaDetected = checks.some((c) => c.security?.recaptcha || c.security?.challengeText);
  const loggedIn = checks.some((c) =>
    c.globalNav
    || c.feedComposer
    || c.repostBtn
    || /profile viewers|post impressions/i.test(c.bodySnippet || '')
  ) && !checks.some((c) => c.loginPassword || c.sessionError);

  respond({
    success: true,
    headless: input.headless !== false,
    checks,
    captchaDetected,
    loggedIn,
    sessionHealthy: loggedIn && !captchaDetected,
  });
} catch (e) {
  respond({ success: false, error: e.message });
  process.exit(1);
} finally {
  await closeBrowserSafe(browser, context);
}
