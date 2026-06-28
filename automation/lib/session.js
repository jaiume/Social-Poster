import fs from 'fs';
import { TYPE_DELAY_MS } from './timing.js';

export { TYPE_DELAY_MS };

/** Keep capture and automation contexts identical so Facebook/LinkedIn do not invalidate cookies. */
export const BROWSER_USER_AGENT =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
export const BROWSER_VIEWPORT = { width: 1280, height: 720 };

export function browserContextOptions(input = {}) {
  return {
    ...(input.sessionPath ? { storageState: input.sessionPath } : {}),
    viewport: BROWSER_VIEWPORT,
    userAgent: BROWSER_USER_AGENT,
    locale: 'en-US',
    timezoneId: 'UTC',
  };
}

export async function readStdinJson() {
  const chunks = [];
  for await (const chunk of process.stdin) {
    chunks.push(chunk);
  }
  const raw = Buffer.concat(chunks).toString('utf8').trim();
  return raw ? JSON.parse(raw) : {};
}

export function respond(obj) {
  process.stdout.write(JSON.stringify(obj));
}

export const BROWSER_ARGS = [
  '--no-sandbox',
  '--disable-setuid-sandbox',
  '--disable-dev-shm-usage',
  '--disable-blink-features=AutomationControlled',
];

export async function applyStealthScripts(page) {
  await page.addInitScript(() => {
    Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
    Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
    window.chrome = window.chrome || { runtime: {} };
  });
}

export async function humanPause(page, minMs = 800, maxMs = 1800) {
  const ms = minMs + Math.floor(Math.random() * Math.max(1, maxMs - minMs));
  await page.waitForTimeout(ms).catch(() => {});
}

export async function launchBrowser(input) {
  const { chromium } = await import('playwright');
  const browser = await chromium.launch({
    headless: true,
    ignoreDefaultArgs: ['--enable-automation'],
    args: [...BROWSER_ARGS, '--disable-gpu'],
  });
  const context = await browser.newContext(browserContextOptions(input));
  const page = await context.newPage();
  await applyStealthScripts(page);
  page.setDefaultTimeout(input.timeoutMs || 30000);
  return { browser, context, page };
}

/**
 * Close Playwright without hanging the Node process if Facebook leaves modals open.
 * @param {import('playwright').Browser|null|undefined} browser
 * @param {import('playwright').BrowserContext|null|undefined} context
 * @param {number} timeoutMs
 */
export async function closeBrowserSafe(browser, context, timeoutMs = 8000) {
  if (!browser && !context) {
    return;
  }

  const closeWork = (async () => {
    if (context) {
      for (const page of context.pages()) {
        await page.close({ runBeforeUnload: false }).catch(() => {});
      }
      await context.close().catch(() => {});
    }
    if (browser) {
      await browser.close().catch(() => {});
    }
  })();

  await Promise.race([
    closeWork,
    new Promise((resolve) => setTimeout(resolve, timeoutMs)),
  ]);
}

export async function saveSessionState(context, sessionPath) {
  if (!sessionPath || !context) {
    return;
  }
  const state = await context.storageState();
  fs.writeFileSync(sessionPath, JSON.stringify(state));
}
