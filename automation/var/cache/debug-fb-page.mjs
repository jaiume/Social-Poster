import { launchBrowser, closeBrowserSafe } from './lib/session.js';
import {
  isPageAdminMode,
  ensurePageReadyForComposer,
  openPagePostsSurface,
} from './lib/facebook.js';

const input = JSON.parse(await new Response(process.stdin).text());
const pageUrl = `https://www.facebook.com/${input.slug}`;
const { browser, context, page } = await launchBrowser({ ...input, platform: 'facebook' });

async function probe(label, locator) {
  const visible = await locator.first().isVisible({ timeout: 1500 }).catch(() => false);
  console.error(`${label}: ${visible}`);
  return visible;
}

try {
  await page.goto(pageUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2000);
  console.error('url:', page.url());
  await probe('admin mode', { first: async () => isPageAdminMode(page) });
  console.error('isPageAdminMode:', await isPageAdminMode(page));
  await probe('switch now', page.getByRole('button', { name: /switch now/i }));
  await probe('manage page', page.getByText(/^manage page$/i));
  await probe('whats on mind', page.getByText(/what'?s on your mind/i));
  await probe('create post btn', page.getByRole('button', { name: /create a post/i }));
  await probe('posts tab', page.getByRole('tab', { name: /^posts$/i }));
  await probe('posts link', page.getByRole('link', { name: /^posts$/i }));
  await probe('prof dashboard', page.getByRole('link', { name: /professional dashboard/i }));

  await ensurePageReadyForComposer(page, pageUrl);
  console.error('after ensurePageReadyForComposer whats on mind:', await page.getByText(/what'?s on your mind/i).first().isVisible({ timeout: 2000 }).catch(() => false));

  await openPagePostsSurface(page, pageUrl);
  console.error('after openPagePostsSurface url:', page.url());
  await probe('whats on mind (after posts)', page.getByText(/what'?s on your mind/i));

  await page.screenshot({ path: input.screenshotPath, fullPage: false });
  console.error('screenshot:', input.screenshotPath);
} finally {
  await closeBrowserSafe(browser, context);
}